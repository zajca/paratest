<?php

declare(strict_types=1);

namespace ParaTest\JUnit;

use SplFileInfo;

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
     * @param non-empty-list<MessageType> $retryOn    message types that trigger retry
     * @param array<string, list<string>> $dependsMap transitive closure of @depends ancestors keyed by "Class::method"
     */
    public function __construct(
        private array $retryOn,
        private array $dependsMap,
        private bool $functional
    ) {
    }

    /**
     * @param list<SplFileInfo> $junitFiles one attempt's per-worker JUnit files
     *
     * @return list<non-empty-string> deduplicated work items ready to feed runAttempt()
     */
    public function extractFailures(array $junitFiles): array
    {
        /** @var array<string, non-empty-string> $workItems */
        $workItems = [];
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
            $this->emitWorkItem($case, $workItems);

            // Close under the transitive @depends ancestor map.
            $ancestors = $this->dependsMap[$key] ?? [];
            foreach ($ancestors as $ancestorKey) {
                if (isset($failedByKey[$ancestorKey])) {
                    // already emitted
                    continue;
                }

                $ancestorCase = $this->synthesizeAncestorCase($ancestorKey, $case);
                if ($ancestorCase === null) {
                    continue;
                }

                $this->emitWorkItem($ancestorCase, $workItems);
            }
        }

        return array_values($workItems);
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
    private function emitWorkItem(TestCase $case, array &$workItems): void
    {
        $file = $case->file;
        if ($file === '') {
            return;
        }

        // PHPT cases always map to a bare filename regardless of mode.
        if ($case->class === self::PHPT_CLASS) {
            $workItems[$file] = $file;

            return;
        }

        if (! $this->functional) {
            // Non-functional mode re-runs the whole file (§2.5).
            $workItems[$file] = $file;

            return;
        }

        // H3 — guard against embedded null bytes in the JUnit name attribute
        // because they would corrupt the "$file\0$pcre" work-item framing.
        if (str_contains($case->name, "\0")) {
            error_log(sprintf(
                'ParaTest retry: skipping test "%s::%s" because its name contains a null byte which is incompatible with the functional-mode work-item format.',
                $case->class,
                $case->name,
            ));

            return;
        }

        $pcre             = sprintf('/%s$/', preg_quote($case->name, '/'));
        $item             = sprintf("%s\0%s", $file, $pcre);
        $workItems[$item] = $item;
    }

    /**
     * Builds a synthetic TestCase for an @depends ancestor keyed "Class::method".
     * Because the ancestor itself may not appear in the current attempt's failing
     * set (e.g., it passed or wasn't included at all), we inherit $file from the
     * triggering failed case as a best-effort pointer — non-functional mode only
     * uses $file, functional mode only uses $class + $name.
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

        return new TestCase(
            $name,
            $class,
            $from->file,
            0,
            0,
            0.0,
        );
    }
}
