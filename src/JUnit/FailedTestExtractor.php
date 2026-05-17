<?php

declare(strict_types=1);

namespace ParaTest\JUnit;

use SplFileInfo;

use function array_reverse;
use function array_values;
use function error_log;
use function in_array;
use function preg_quote;
use function sprintf;
use function str_contains;
use function strpos;
use function substr;

/**
 * Extracts retry-eligible work items from a set of per-worker JUnit files
 * produced by a single attempt. Output matches the SuiteLoader::$tests
 * format (see retry-feature-design.md §2.5).
 *
 * @internal
 *
 * @immutable
 */
final readonly class FailedTestExtractor
{
    private const string PHPT_CLASS = 'PHPUnit\\Runner\\Phpt\\TestCase';

    /**
     * @param non-empty-list<MessageType>     $retryOn     message types that trigger retry
     * @param array<string, list<string>>     $dependsMap  transitive closure of @depends ancestors keyed by "Class::method"
     * @param array<string, non-empty-string> $testFileMap source file for each "Class::method" key
     */
    public function __construct(
        private array $retryOn,
        private array $dependsMap,
        private bool $functional,
        private array $testFileMap = [],
    ) {
    }

    /**
     * @param list<SplFileInfo> $junitFiles one attempt's per-worker JUnit files
     *
     * @return list<non-empty-string>
     */
    public function extractFailures(array $junitFiles): array
    {
        return $this->extractFailureDetails($junitFiles)->workItems;
    }

    /** @param list<SplFileInfo> $junitFiles one attempt's per-worker JUnit files */
    public function extractFailureDetails(array $junitFiles): RetryFailures
    {
        /** @var array<string, non-empty-string> $workItems */
        $workItems = [];
        /** @var array<string, non-empty-string> $testNames */
        $testNames = [];
        /** @var array<string, TestCase> $failedByKey */
        $failedByKey = [];

        foreach ($junitFiles as $junitFile) {
            if (! $junitFile->isFile() || $junitFile->getSize() === 0) {
                continue;
            }

            $suite = TestSuite::fromFile($junitFile);
            $this->collectFailures($suite, $failedByKey);
        }

        foreach ($failedByKey as $key => $case) {
            // Close under the transitive @depends ancestor map.
            $this->emitAncestorWorkItems($key, $case, $failedByKey, $workItems, $testNames);

            if (! $this->emitWorkItem($case, $workItems)) {
                continue;
            }

            $this->emitTestName($case, $testNames);
        }

        return new RetryFailures(array_values($workItems), array_values($testNames));
    }

    /**
     * @param array<string, TestCase>         $failedByKey
     * @param array<string, non-empty-string> $workItems
     * @param array<string, non-empty-string> $testNames
     */
    private function emitAncestorWorkItems(
        string $key,
        TestCase $case,
        array $failedByKey,
        array &$workItems,
        array &$testNames
    ): void {
        $ancestors = array_reverse($this->dependsMap[$key] ?? []);
        foreach ($ancestors as $ancestorKey) {
            if (isset($failedByKey[$ancestorKey])) {
                continue;
            }

            $ancestorCase = $this->synthesizeAncestorCase($ancestorKey, $case);
            if ($ancestorCase === null) {
                continue;
            }

            if (! $this->emitWorkItem($ancestorCase, $workItems)) {
                continue;
            }

            $this->emitTestName($ancestorCase, $testNames);
        }
    }

    /** @param array<string, TestCase> $failedByKey */
    private function collectFailures(TestSuite $suite, array &$failedByKey): void
    {
        foreach ($suite->suites as $child) {
            $this->collectFailures($child, $failedByKey);
        }

        foreach ($suite->cases as $case) {
            if (! $case instanceof TestCaseWithMessage) {
                continue;
            }

            if (! in_array($case->xmlTagName, $this->retryOn, true)) {
                continue;
            }

            $key = $case->class . '::' . $case->name;
            // First-wins keeps the original payload; later collisions across worker
            // files would be the exact same test and produce identical work items.
            if (isset($failedByKey[$key])) {
                continue;
            }

            $failedByKey[$key] = $case;
        }
    }

    /**
     * Builds the work item per mode (§2.5) and appends it to $workItems
     * keyed by its string value for deduplication.
     *
     * @param array<string, non-empty-string> $workItems
     */
    private function emitWorkItem(TestCase $case, array &$workItems): bool
    {
        $file = $case->file;
        if ($file === '') {
            return false;
        }

        // PHPT cases always map to a bare filename regardless of mode.
        if ($case->class === self::PHPT_CLASS) {
            $workItems[$file] = $file;

            return true;
        }

        if (! $this->functional) {
            // Non-functional mode re-runs the whole file (§2.5).
            $workItems[$file] = $file;

            return true;
        }

        // H3 — guard against embedded null bytes in the JUnit name attribute
        // because they would corrupt the "$file\0$pcre" work-item framing.
        if (str_contains($case->name, "\0")) {
            error_log(sprintf(
                'ParaTest retry: skipping test "%s::%s" because its name contains a null byte which is incompatible with the functional-mode work-item format.',
                $case->class,
                $case->name,
            ));

            return false;
        }

        $pcre             = sprintf('/%s$/', preg_quote($case->name, '/'));
        $item             = sprintf("%s\0%s", $file, $pcre);
        $workItems[$item] = $item;

        return true;
    }

    /** @param array<string, non-empty-string> $testNames */
    private function emitTestName(TestCase $case, array &$testNames): void
    {
        $testName = $this->displayName($case);
        if ($testName === null) {
            return;
        }

        $testNames[$testName] = $testName;
    }

    /** @return non-empty-string|null */
    private function displayName(TestCase $case): ?string
    {
        if ($case->class === self::PHPT_CLASS) {
            return $case->name !== '' ? $case->name : ($case->file !== '' ? $case->file : null);
        }

        if ($case->class !== '' && $case->name !== '') {
            return $case->class . '::' . $case->name;
        }

        return $case->name !== '' ? $case->name : null;
    }

    /**
     * Builds a synthetic TestCase for an @depends ancestor keyed "Class::method".
     * Because the ancestor itself may not appear in the current attempt's failing
     * set, SuiteLoader provides its source file when it is known.
     */
    private function synthesizeAncestorCase(string $ancestorKey, TestCase $from): ?TestCase
    {
        $sep = strpos($ancestorKey, '::');
        if ($sep === false) {
            return null;
        }

        $class = substr($ancestorKey, 0, $sep);
        $name  = substr($ancestorKey, $sep + 2);
        if ($class === '' || $name === '') {
            return null;
        }

        $file = $this->testFileMap[$ancestorKey] ?? $from->file;

        return new TestCase(
            $name,
            $class,
            $file,
            0,
            0,
            0.0,
        );
    }
}
