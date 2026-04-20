<?php

declare(strict_types=1);

namespace ParaTest\WrapperRunner;

use PHPUnit\TestRunner\TestResult\TestResult;
use SplFileInfo;

/**
 * Immutable snapshot of a single retry attempt's worker-produced artifacts plus
 * its aggregated PHPUnit `TestResult`.
 *
 * See `docs/retry-feature-design.md` §2.2. Field types mirror the per-attempt
 * accumulator arrays on `WrapperRunner` so the attempt outcome can be fed back
 * into `WrapperRunner::complete()` without transformation.
 *
 * @internal
 *
 * @immutable
 */
final readonly class AttemptOutcome
{
    /**
     * @param int                    $attemptNumber    1-based attempt index.
     * @param list<non-empty-string> $executedWorkItems Work-item identifiers submitted to workers for this attempt.
     * @param list<SplFileInfo>      $junitFiles
     * @param list<SplFileInfo>      $coverageFiles
     * @param list<SplFileInfo>      $testResultFiles
     * @param list<SplFileInfo>      $testdoxFiles
     * @param list<SplFileInfo>      $teamcityFiles
     * @param list<SplFileInfo>      $resultCacheFiles
     * @param list<SplFileInfo>      $progressFiles
     * @param list<SplFileInfo>      $unexpectedOutputFiles
     * @param list<SplFileInfo>      $statusFiles
     */
    public function __construct(
        public int $attemptNumber,
        public array $executedWorkItems,
        public array $junitFiles,
        public array $coverageFiles,
        public array $testResultFiles,
        public array $testdoxFiles,
        public array $teamcityFiles,
        public array $resultCacheFiles,
        public array $progressFiles,
        public array $unexpectedOutputFiles,
        public array $statusFiles,
        public int $exitcode,
        public TestResult $testResultAggregate,
    ) {
    }
}
