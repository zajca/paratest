<?php

declare(strict_types=1);

namespace ParaTest\WrapperRunner;

use ParaTest\JUnit\FailedTestExtractor;
use ParaTest\JUnit\MessageType;
use ParaTest\JUnit\TestCaseWithMessage;
use ParaTest\JUnit\TestSuite as JUnitTestSuite;
use ParaTest\Options;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Output\OutputInterface;

use function count;
use function dirname;
use function file_exists;
use function is_dir;
use function mkdir;
use function rename;
use function sprintf;

/**
 * Drives the retry loop around `WrapperRunner::runAttempt()`.
 *
 *  Only instantiated when
 * `Options::$retry > 0`; the retry=0 path in `WrapperRunner::run()` bypasses
 * this orchestrator entirely to preserve bit-identical behavior (§2.8).
 *
 * Public API:
 * - {@see self::orchestrate()} — runs up to `retry + 1` attempts, archives
 *   per-attempt artifacts between runs, stops early on empty pending set or
 *   on any `stopOn*` trigger.
 * - {@see self::getFlakyTestList()} — tests that failed at least once then
 *   passed in the final attempt (for the R4 summary and H2 cache rewrite).
 *
 * @internal
 */
final class RetryOrchestrator
{
    /** @var list<string> */
    private array $flakyTests = [];

    public function __construct(
        private readonly Options $options,
        private readonly OutputInterface $output,
        private readonly SuiteLoader $suiteLoader,
        private readonly FailedTestExtractor $extractor,
    ) {
    }

    /**
     * Executes attempts until the pending set is empty, the max attempt count
     * is reached, or a `stopOn*` trigger fires.
     *
     * @param list<non-empty-string>                                         $initialPending
     * @param callable(int, list<non-empty-string>): AttemptOutcome          $attemptRunner
     */
    public function orchestrate(array $initialPending, callable $attemptRunner): RetryResult
    {
        $maxAttempts = $this->options->retry + 1;
        /** @var list<AttemptOutcome> $attempts */
        $attempts          = [];
        $pending           = $initialPending;
        /** @var array<int, list<SplFileInfo>> $archivedJunitsPerAttempt */
        $archivedJunitsPerAttempt = [];

        for ($attemptNumber = 1; $attemptNumber <= $maxAttempts; ++$attemptNumber) {
            $outcome    = $attemptRunner($attemptNumber, $pending);
            $attempts[] = $outcome;

            $isFinal = $attemptNumber === $maxAttempts;

            if ($this->shouldStopOnDefect($outcome)) {
                // A `--stop-on-*` trigger fired during this attempt; retry is
                // suppressed for the remaining attempts. See design §2.7 and H4.
                break;
            }

            if ($isFinal) {
                break;
            }

            $nextPending = $this->extractor->extractFailures($outcome->junitFiles);
            if ($nextPending === []) {
                // Everyone passed — no more work. We stop early and do not
                // archive this attempt's files (they are the final-attempt
                // artifacts that `WrapperRunner::complete()` reads).
                break;
            }

            $this->output->writeln(sprintf(
                '<info>Retry attempt %d/%d: re-running %d of %d test%s.</info>',
                $attemptNumber + 1,
                $maxAttempts,
                count($nextPending),
                $this->suiteLoader->testCount,
                count($nextPending) === 1 ? '' : 's',
            ));

            // Archive this (non-final) attempt's artifacts into {tmpDir}/attempt-N/
            // and mutate the AttemptOutcome's SplFileInfo lists to reflect the
            // new paths.
            $archivedOutcome = $this->archiveAttempt($outcome);
            // Replace the last outcome with the archived version so downstream
            // complete()/coverage aggregation reads from the archived paths.
            $attempts[count($attempts) - 1] = $archivedOutcome;

            $archivedJunitsPerAttempt[$attemptNumber] = $archivedOutcome->junitFiles;

            $pending = $nextPending;
        }

        // Final attempt is whatever is left in $attempts. Compute flaky set
        // relative to the final attempt's JUnit output.
        if (count($attempts) >= 2) {
            $this->flakyTests = $this->computeFlakyTests($attempts);
        }

        return new RetryResult($attempts, $this->flakyTests);
    }

    /** @return list<string> */
    public function getFlakyTestList(): array
    {
        return $this->flakyTests;
    }

    /**
     * Checks whether any of the PHPUnit `stopOn*` flags fired during this attempt
     * by inspecting the exit code and the configuration. `WrapperRunner` already
     * zero-s out its pending queue when `stopOnFailure` fires; mirroring the same
     * reaction here means we don't start another attempt on the remainder.
     */
    private function shouldStopOnDefect(AttemptOutcome $outcome): bool
    {
        if ($outcome->exitcode === 0) {
            return false;
        }

        $configuration = $this->options->configuration;

        return $configuration->stopOnDefect()
            || $configuration->stopOnError()
            || $configuration->stopOnFailure()
            || $configuration->stopOnWarning()
            || $configuration->stopOnRisky()
            || $configuration->stopOnDeprecation()
            || $configuration->stopOnNotice()
            || $configuration->stopOnSkipped()
            || $configuration->stopOnIncomplete();
    }

    /**
     * Moves every `SplFileInfo` in this attempt's artifact arrays to
     * `{tmpDir}/attempt-{N}/` using `rename()` (atomic on same filesystem,
     * preserves inodes). Returns a new `AttemptOutcome` carrying the updated
     * paths.
     */
    private function archiveAttempt(AttemptOutcome $outcome): AttemptOutcome
    {
        $attemptDir = $this->options->tmpDir . '/attempt-' . $outcome->attemptNumber;
        if (! is_dir($attemptDir) && ! mkdir($attemptDir, 0o777, true) && ! is_dir($attemptDir)) {
            throw new RuntimeException(sprintf('Failed to create archival directory: %s', $attemptDir));
        }

        return new AttemptOutcome(
            $outcome->attemptNumber,
            $outcome->executedWorkItems,
            $this->archiveFiles($outcome->junitFiles, $attemptDir),
            $this->archiveFiles($outcome->coverageFiles, $attemptDir),
            $this->archiveFiles($outcome->testResultFiles, $attemptDir),
            $this->archiveFiles($outcome->testdoxFiles, $attemptDir),
            $this->archiveFiles($outcome->teamcityFiles, $attemptDir),
            $this->archiveFiles($outcome->resultCacheFiles, $attemptDir),
            $this->archiveFiles($outcome->progressFiles, $attemptDir),
            $this->archiveFiles($outcome->unexpectedOutputFiles, $attemptDir),
            $this->archiveFiles($outcome->statusFiles, $attemptDir),
            $outcome->exitcode,
            $outcome->testResultAggregate,
        );
    }

    /**
     * @param list<SplFileInfo> $files
     *
     * @return list<SplFileInfo>
     */
    private function archiveFiles(array $files, string $destDir): array
    {
        $archived = [];
        foreach ($files as $file) {
            $src = $file->getPathname();
            if ($src === '' || ! file_exists($src)) {
                // Worker never wrote this file (e.g., no executed test of its kind);
                // keep the original SplFileInfo so downstream existence checks
                // still produce a consistent result.
                $archived[] = $file;
                continue;
            }

            $dst = $destDir . '/' . $file->getFilename();
            // If the source and destination already match (paranoia — the orchestrator
            // is not re-entrant on the same outcome), skip the rename.
            if (dirname($src) === $destDir) {
                $archived[] = $file;
                continue;
            }

            if (! @rename($src, $dst)) {
                throw new RuntimeException(sprintf('Failed to archive artifact "%s" to "%s"', $src, $dst));
            }

            $archived[] = new SplFileInfo($dst);
        }

        return $archived;
    }

    /**
     * A test is "flaky" iff it failed in at least one non-final attempt
     * (message matches `--retry-on` filter) and passed in the final attempt.
     *
     * @param list<AttemptOutcome> $attempts
     *
     * @return list<string>
     */
    private function computeFlakyTests(array $attempts): array
    {
        $final        = $attempts[count($attempts) - 1];
        $finalFailed  = $this->collectFailedKeys($final->junitFiles);
        /** @var array<string, true> $priorFailed */
        $priorFailed = [];

        for ($i = 0; $i < count($attempts) - 1; ++$i) {
            foreach ($this->collectFailedKeys($attempts[$i]->junitFiles) as $key => $_true) {
                $priorFailed[$key] = true;
            }
        }

        $flaky = [];
        foreach ($priorFailed as $key => $_true) {
            if (isset($finalFailed[$key])) {
                continue;
            }

            $flaky[] = $key;
        }

        return $flaky;
    }

    /**
     * Parses one attempt's JUnit XML files and returns the set of failed test
     * keys (`"Class::method"` form) filtered by `--retry-on`.
     *
     * @param list<SplFileInfo> $junitFiles
     *
     * @return array<string, true>
     */
    private function collectFailedKeys(array $junitFiles): array
    {
        $failed = [];
        foreach ($junitFiles as $junitFile) {
            if (! $junitFile->isFile() || (int) $junitFile->getSize() <= 0) {
                continue;
            }

            $suite = JUnitTestSuite::fromFile($junitFile);
            $this->walkCases($suite, $this->options->retryOn, $failed);
        }

        return $failed;
    }

    /**
     * @param list<MessageType>      $retryOn
     * @param array<string, true>    $failed
     */
    private function walkCases(JUnitTestSuite $suite, array $retryOn, array &$failed): void
    {
        foreach ($suite->cases as $case) {
            if (! $case instanceof TestCaseWithMessage) {
                continue;
            }

            foreach ($retryOn as $type) {
                if ($case->xmlTagName !== $type) {
                    continue;
                }

                $failed[$case->class . '::' . $case->name] = true;
                break;
            }
        }

        foreach ($suite->suites as $child) {
            $this->walkCases($child, $retryOn, $failed);
        }
    }
}
