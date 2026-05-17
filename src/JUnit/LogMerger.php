<?php

declare(strict_types=1);

namespace ParaTest\JUnit;

use SplFileInfo;

use function array_keys;
use function array_values;
use function count;
use function in_array;
use function ksort;
use function max;
use function sort;

/**
 * @internal
 *
 * @immutable
 */
final readonly class LogMerger
{
    /** @param list<SplFileInfo> $junitFiles */
    public function merge(array $junitFiles): ?TestSuite
    {
        $mainSuite = null;
        foreach ($junitFiles as $junitFile) {
            if (! $junitFile->isFile()) {
                continue;
            }

            $otherSuite = TestSuite::fromFile($junitFile);
            if ($mainSuite === null) {
                $mainSuite = $otherSuite;
                continue;
            }

            if ($mainSuite->name !== $otherSuite->name) {
                if ($mainSuite->name !== '') {
                    $mainSuite = new TestSuite(
                        '',
                        $mainSuite->tests,
                        $mainSuite->assertions,
                        $mainSuite->failures,
                        $mainSuite->errors,
                        $mainSuite->skipped,
                        $mainSuite->time,
                        '',
                        [$mainSuite->name => $mainSuite],
                        [],
                    );
                }

                if ($otherSuite->name !== '') {
                    $otherSuite = new TestSuite(
                        '',
                        $otherSuite->tests,
                        $otherSuite->assertions,
                        $otherSuite->failures,
                        $otherSuite->errors,
                        $otherSuite->skipped,
                        $otherSuite->time,
                        '',
                        [$otherSuite->name => $otherSuite],
                        [],
                    );
                }
            }

            $mainSuite = $mainSuite->mergeWith($otherSuite);
        }

        return $mainSuite;
    }

    /**
     * Cross-attempt JUnit merge.
     *
     * Takes per-attempt JUnit file lists keyed by 1-based attempt number and
     * produces a canonical TestSuite whose leaves are annotated with retry
     * metadata (TestCaseWithRetries) describing prior-attempt failures.
     *
     * Memory is bounded: prior attempts are parsed one at a time and discarded
     * after their failure payloads have been copied onto the accumulator map.
     * Peak memory ≈ final_test_count + max(attempt_test_count).
     *
     * @param array<int, list<SplFileInfo>> $perAttemptJunitFiles attempt (1-based) => per-worker JUnit files
     */
    public function mergeAcrossAttempts(array $perAttemptJunitFiles, bool $includeRetryMetadata = true): ?TestSuite
    {
        if ($perAttemptJunitFiles === []) {
            return null;
        }

        $finalAttempt   = max(array_keys($perAttemptJunitFiles));
        $attemptNumbers = array_keys($perAttemptJunitFiles);
        sort($attemptNumbers);

        $effectiveSuite = null;
        foreach ($attemptNumbers as $attemptNumber) {
            $attemptSuite = $this->merge($perAttemptJunitFiles[$attemptNumber]);
            if ($attemptSuite === null) {
                continue;
            }

            $effectiveSuite = $effectiveSuite === null
                ? $attemptSuite
                : $this->mergeLatestCases($effectiveSuite, $attemptSuite);
        }

        if ($effectiveSuite === null || ! $includeRetryMetadata) {
            return $effectiveSuite;
        }

        /** @var array<string, bool> $finalPassedByKey */
        $finalPassedByKey = [];
        $this->indexFinalCases($effectiveSuite, $finalPassedByKey);

        /** @var array<string, list<TestCaseWithMessage>> $priorFailuresByKey */
        $priorFailuresByKey = [];

        foreach ($attemptNumbers as $attemptNumber) {
            if ($attemptNumber === $finalAttempt) {
                continue;
            }

            $priorSuite = $this->merge($perAttemptJunitFiles[$attemptNumber]);
            if ($priorSuite === null) {
                continue;
            }

            $this->collectPriorFailures($priorSuite, $finalPassedByKey, $priorFailuresByKey);
        }

        if ($priorFailuresByKey === []) {
            return $effectiveSuite;
        }

        return $this->decorateSuite($effectiveSuite, $priorFailuresByKey);
    }

    private function mergeLatestCases(TestSuite $base, TestSuite $latest): TestSuite
    {
        if ($base->name !== $latest->name) {
            $base   = $this->wrapNamedSuite($base);
            $latest = $this->wrapNamedSuite($latest);
        }

        $suites = $base->suites;
        foreach ($latest->suites as $name => $latestSuite) {
            $suites[$name] = isset($suites[$name])
                ? $this->mergeLatestCases($suites[$name], $latestSuite)
                : $latestSuite;
        }

        ksort($suites);

        /** @var array<string, TestCase> $casesByKey */
        $casesByKey = [];
        foreach ($base->cases as $case) {
            $casesByKey[$this->caseKey($case)] = $case;
        }

        foreach ($latest->cases as $case) {
            $casesByKey[$this->caseKey($case)] = $case;
        }

        return $this->recountSuite(
            $base->name,
            $base->file,
            $suites,
            array_values($casesByKey),
        );
    }

    private function wrapNamedSuite(TestSuite $suite): TestSuite
    {
        if ($suite->name === '') {
            return $suite;
        }

        return new TestSuite(
            '',
            $suite->tests,
            $suite->assertions,
            $suite->failures,
            $suite->errors,
            $suite->skipped,
            $suite->time,
            '',
            [$suite->name => $suite],
            [],
        );
    }

    /**
     * @param array<string, TestSuite> $suites
     * @param list<TestCase>           $cases
     */
    private function recountSuite(string $name, string $file, array $suites, array $cases): TestSuite
    {
        $tests      = count($cases);
        $assertions = 0;
        $failures   = 0;
        $errors     = 0;
        $skipped    = 0;
        $time       = 0.0;

        foreach ($cases as $case) {
            $assertions += $case->assertions;
            $time       += $case->time;

            if (! $case instanceof TestCaseWithMessage) {
                continue;
            }

            match ($case->xmlTagName) {
                MessageType::failure => ++$failures,
                MessageType::error => ++$errors,
                MessageType::skipped => ++$skipped,
            };
        }

        foreach ($suites as $suite) {
            $tests      += $suite->tests;
            $assertions += $suite->assertions;
            $failures   += $suite->failures;
            $errors     += $suite->errors;
            $skipped    += $suite->skipped;
            $time       += $suite->time;
        }

        return new TestSuite(
            $name,
            $tests,
            $assertions,
            $failures,
            $errors,
            $skipped,
            $time,
            $file,
            $suites,
            $cases,
        );
    }

    /** @param array<string, bool> $finalPassedByKey */
    private function indexFinalCases(TestSuite $suite, array &$finalPassedByKey): void
    {
        foreach ($suite->suites as $child) {
            $this->indexFinalCases($child, $finalPassedByKey);
        }

        foreach ($suite->cases as $case) {
            $key = $this->caseKey($case);
            // A case "passed" in the final attempt iff its emission in that attempt
            // carried no failure/error message (TestCaseWithMessage with failure/error).
            $finalPassedByKey[$key] = ! $this->isRetriableDefect($case);
        }
    }

    /**
     * Walk a prior attempt's tree and append every retriable failure/error to
     * $priorFailuresByKey under "Class::name".
     *
     * @param array<string, bool>                      $finalPassedByKey
     * @param array<string, list<TestCaseWithMessage>> $priorFailuresByKey
     */
    private function collectPriorFailures(
        TestSuite $suite,
        array $finalPassedByKey,
        array &$priorFailuresByKey
    ): void {
        foreach ($suite->suites as $child) {
            $this->collectPriorFailures($child, $finalPassedByKey, $priorFailuresByKey);
        }

        foreach ($suite->cases as $case) {
            if (! $case instanceof TestCaseWithMessage) {
                continue;
            }

            if (! in_array($case->xmlTagName, [MessageType::failure, MessageType::error], true)) {
                continue;
            }

            $key = $this->caseKey($case);
            // Only retain prior failures whose counterpart survived into the final
            // attempt's merged tree — otherwise we'd have nothing to attach to.
            if (! isset($finalPassedByKey[$key])) {
                continue;
            }

            $priorFailuresByKey[$key][] = $case;
        }
    }

    /**
     * Rebuild the final TestSuite tree, replacing matched TestCase leaves with
     * TestCaseWithRetries decorators.
     *
     * @param array<string, list<TestCaseWithMessage>> $priorFailuresByKey
     */
    private function decorateSuite(TestSuite $suite, array $priorFailuresByKey): TestSuite
    {
        $suites = [];
        foreach ($suite->suites as $name => $child) {
            $suites[$name] = $this->decorateSuite($child, $priorFailuresByKey);
        }

        /** @var list<TestCase> $cases */
        $cases = [];
        foreach ($suite->cases as $case) {
            $key = $this->caseKey($case);
            if (! isset($priorFailuresByKey[$key])) {
                $cases[] = $case;
                continue;
            }

            $priorFailures      = $priorFailuresByKey[$key];
            $finalAttemptPassed = ! $this->isRetriableDefect($case);
            $finalDefect        = $case instanceof TestCaseWithMessage ? $case : null;

            $cases[] = new TestCaseWithRetries(
                $case->name,
                $case->class,
                $case->file,
                $case->line,
                $case->assertions,
                $case->time,
                count($priorFailures),
                $priorFailures,
                $finalAttemptPassed,
                $finalDefect,
            );
        }

        return new TestSuite(
            $suite->name,
            $suite->tests,
            $suite->assertions,
            $suite->failures,
            $suite->errors,
            $suite->skipped,
            $suite->time,
            $suite->file,
            $suites,
            $cases,
        );
    }

    private function isRetriableDefect(TestCase $case): bool
    {
        if (! $case instanceof TestCaseWithMessage) {
            return false;
        }

        return in_array($case->xmlTagName, [MessageType::failure, MessageType::error], true);
    }

    private function caseKey(TestCase $case): string
    {
        return $case->class . '::' . $case->name;
    }
}
