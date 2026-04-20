<?php

declare(strict_types=1);

namespace ParaTest\JUnit;

use SplFileInfo;

use function array_keys;
use function count;
use function in_array;
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
     * Cross-attempt JUnit merge (see docs/retry-feature-design.md §3.3).
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
    public function mergeAcrossAttempts(array $perAttemptJunitFiles): ?TestSuite
    {
        if ($perAttemptJunitFiles === []) {
            return null;
        }

        $finalAttempt = max(array_keys($perAttemptJunitFiles));
        $finalSuite   = $this->merge($perAttemptJunitFiles[$finalAttempt]);
        if ($finalSuite === null) {
            return null;
        }

        /** @var array<string, bool> $finalPassedByKey */
        $finalPassedByKey = [];
        $this->indexFinalCases($finalSuite, $finalPassedByKey);

        /** @var array<string, list<TestCaseWithMessage>> $priorFailuresByKey */
        $priorFailuresByKey = [];

        $attemptNumbers = array_keys($perAttemptJunitFiles);
        sort($attemptNumbers);

        foreach ($attemptNumbers as $attemptNumber) {
            if ($attemptNumber === $finalAttempt) {
                continue;
            }

            $priorSuite = $this->merge($perAttemptJunitFiles[$attemptNumber]);
            if ($priorSuite === null) {
                continue;
            }

            $this->collectPriorFailures($priorSuite, $finalPassedByKey, $priorFailuresByKey);

            // Drop the prior attempt's TestSuite tree before loading the next one
            // to keep memory bounded. Small TestCaseWithMessage payloads remain
            // referenced by $priorFailuresByKey.
            unset($priorSuite);
        }

        if ($priorFailuresByKey === []) {
            return $finalSuite;
        }

        return $this->decorateSuite($finalSuite, $priorFailuresByKey);
    }

    /** @param array<string, bool> $finalPassedByKey */
    private function indexFinalCases(TestSuite $suite, array &$finalPassedByKey): void
    {
        foreach ($suite->suites as $child) {
            $this->indexFinalCases($child, $finalPassedByKey);
        }

        foreach ($suite->cases as $case) {
            $key = $case->class . '::' . $case->name;
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

            $key = $case->class . '::' . $case->name;
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
            $key = $case->class . '::' . $case->name;
            if (! isset($priorFailuresByKey[$key])) {
                $cases[] = $case;
                continue;
            }

            $priorFailures      = $priorFailuresByKey[$key];
            $finalAttemptPassed = ! $this->isRetriableDefect($case);

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
}
