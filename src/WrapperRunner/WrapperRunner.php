<?php

declare(strict_types=1);

namespace ParaTest\WrapperRunner;

use ParaTest\JUnit\FailedTestExtractor;
use ParaTest\JUnit\LogMerger;
use ParaTest\JUnit\Writer;
use ParaTest\Options;
use ParaTest\RunnerInterface;
use ParaTest\TestDox\TestDoxResultsMerger;
use PHPUnit\Framework\TestStatus\TestStatus;
use PHPUnit\Logging\TestDox\HtmlRenderer as TestDoxHtmlRenderer;
use PHPUnit\Logging\TestDox\PlainTextRenderer as TestDoxPlainTextRenderer;
use PHPUnit\Logging\TestDox\TestResultCollection as TestDoxTestResultCollection;
use PHPUnit\Runner\CodeCoverage;
use PHPUnit\Runner\ResultCache\DefaultResultCache;
use PHPUnit\Runner\ResultCache\ResultCacheId;
use PHPUnit\TestRunner\TestResult\Facade as TestResultFacade;
use PHPUnit\TestRunner\TestResult\TestResult;
use PHPUnit\TextUI\Configuration\CodeCoverageFilterRegistry;
use PHPUnit\TextUI\Output\DefaultPrinter;
use PHPUnit\TextUI\ShellExitCodeCalculator;
use PHPUnit\Util\ExcludeList;
use ReflectionProperty;
use SebastianBergmann\CodeCoverage\Node\Builder;
use SebastianBergmann\CodeCoverage\Serialization\Merger;
use SebastianBergmann\CodeCoverage\StaticAnalysis\FileAnalyser;
use SebastianBergmann\CodeCoverage\StaticAnalysis\ParsingSourceAnalyser;
use SplFileInfo;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\PhpExecutableFinder;

use function array_filter;
use function array_map;
use function array_merge;
use function array_merge_recursive;
use function array_shift;
use function assert;
use function count;
use function dirname;
use function end;
use function explode;
use function file_get_contents;
use function filesize;
use function is_a;
use function is_file;
use function max;
use function preg_match;
use function realpath;
use function str_contains;
use function str_starts_with;
use function unlink;
use function unserialize;
use function usleep;

use const DIRECTORY_SEPARATOR;

/** @internal */
final class WrapperRunner implements RunnerInterface
{
    private const int CYCLE_SLEEP = 10000;
    private readonly ResultPrinter $printer;

    // ------------------------------------------------------------------
    // Per-attempt fields — cleared and re-populated at the start/end of
    // every `runAttempt()` call. Snapshots are captured into `AttemptOutcome`.
    // ------------------------------------------------------------------
    /** @var list<non-empty-string> */
    private array $pending = [];
    private int $exitcode  = -1;
    /** @var array<positive-int,WrapperWorker> */
    private array $workers = [];
    /** @var array<int,int> */
    private array $batches = [];

    /** @var list<SplFileInfo> */
    private array $statusFiles = [];
    /** @var list<SplFileInfo> */
    private array $progressFiles = [];
    /** @var list<SplFileInfo> */
    private array $unexpectedOutputFiles = [];
    /** @var list<SplFileInfo> */
    private array $resultCacheFiles = [];
    /** @var list<SplFileInfo> */
    private array $testResultFiles = [];
    /** @var list<SplFileInfo> */
    private array $coverageFiles = [];
    /** @var list<SplFileInfo> */
    private array $junitFiles = [];
    /** @var list<SplFileInfo> */
    private array $teamcityFiles = [];
    /** @var list<SplFileInfo> */
    private array $testdoxFiles = [];

    // ------------------------------------------------------------------
    // Cross-attempt fields — accumulate across every attempt so coverage
    // union (R6) and `requiredTestResultFiles`/`requiredCoverageFiles`
    // sanity checks cover the whole run, not just the final attempt.
    // ------------------------------------------------------------------
    /** @var array<non-empty-string,true> */
    private array $requiredTestResultFiles = [];
    /** @var array<non-empty-string,true> */
    private array $requiredCoverageFiles = [];

    /** @var array<non-empty-string> */
    private readonly array $parameters;
    private CodeCoverageFilterRegistry $codeCoverageFilterRegistry;

    public function __construct(
        private readonly Options $options,
        private readonly OutputInterface $output
    ) {
        $this->printer = new ResultPrinter($output, $options);

        $wrapper = realpath(
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpunit-wrapper.php',
        );
        assert($wrapper !== false);
        $phpFinder = new PhpExecutableFinder();
        $phpBin    = $phpFinder->find(false);
        assert($phpBin !== false);
        assert($phpBin !== '');
        $parameters = [$phpBin];
        /** @var array<non-empty-string> $arguments */
        $arguments  = $phpFinder->findArguments();
        $parameters = array_merge($parameters, $arguments);

        if ($options->passthruPhp !== null) {
            $parameters = array_merge($parameters, $options->passthruPhp);
        }

        $parameters[] = $wrapper;

        $this->parameters                 = $parameters;
        $this->codeCoverageFilterRegistry = new CodeCoverageFilterRegistry();
    }

    public function run(): int
    {
        $directory = dirname(__DIR__);
        assert($directory !== '');
        ExcludeList::addDirectory($directory);
        $suiteLoader = new SuiteLoader(
            $this->options,
            $this->output,
            $this->codeCoverageFilterRegistry,
        );

        $this->printer->setTestCount($suiteLoader->testCount);
        $this->printer->start();

        // H4 — `--stop-on-*` takes priority over `--retry`. Warn and disable retry.
        // See docs/retry-feature-devils-advocate.md H4.
        $effectiveRetry = $this->options->retry;
        if ($effectiveRetry > 0 && $this->isAnyStopOnFlagSet()) {
            $this->output->writeln(
                '<comment>Warning: --retry is disabled because --stop-on-* is set.</comment>',
            );
            $effectiveRetry = 0;
        }

        if ($effectiveRetry === 0) {
            // Fast path: single attempt, no orchestrator. Preserves bit-identical
            // behavior against pre-refactor baseline (design §2.8).
            $outcome = $this->runAttempt(1, $suiteLoader->tests);

            return $this->complete([$outcome], null);
        }

        // `retryOn` is validated non-empty when `retry > 0` in Options::fromConsoleInput(),
        // but the property type is `list<MessageType>`. Re-assert here so phpstan can
        // narrow and the Dev B extractor contract's `non-empty-list` input is respected.
        $retryOn = $this->options->retryOn;
        assert($retryOn !== []);

        $extractor   = new FailedTestExtractor(
            $retryOn,
            $suiteLoader->dependsMap,
            $this->options->functional,
        );
        $orchestrator = new RetryOrchestrator(
            $this->options,
            $this->output,
            $suiteLoader,
            $extractor,
        );

        $maxAttempts = $effectiveRetry + 1;
        $retryResult = $orchestrator->orchestrate(
            $suiteLoader->tests,
            function (int $attempt, array $pending) use ($maxAttempts): AttemptOutcome {
                // H1 — intermediate-attempt TeamCity stdout events would confuse
                // IDE parsers. Suppress live stdout emission for all attempts
                // except the final one; the final attempt's events stream
                // normally and printResults() replays accumulated final-attempt
                // events from their tmp file afterwards.
                $this->printer->setSuppressTeamcityStdout($attempt < $maxAttempts);

                return $this->runAttempt($attempt, $pending);
            },
        );

        // Ensure stdout is re-enabled for the final printResults() replay.
        $this->printer->setSuppressTeamcityStdout(false);

        return $this->complete($retryResult->allAttempts, $retryResult);
    }

    /**
     * Executes a single attempt: start worker pool, distribute `$pending`,
     * wait for completion, snapshot this attempt's per-attempt accumulators
     * into an {@see AttemptOutcome}, and clear them for the next attempt.
     *
     * Cross-attempt fields (`requiredTestResultFiles`, `requiredCoverageFiles`)
     * are deliberately NOT cleared — they accumulate so `complete()` can
     * validate every attempt's workers wrote their artifacts and the coverage
     * union (R6) sees every file.
     *
     * @param list<non-empty-string> $pending
     */
    private function runAttempt(int $attempt, array $pending): AttemptOutcome
    {
        // Reset per-attempt state. Cross-attempt bookkeeping is preserved.
        $this->pending               = $pending;
        $this->exitcode              = -1;
        $this->workers               = [];
        $this->batches               = [];
        $this->statusFiles           = [];
        $this->progressFiles         = [];
        $this->unexpectedOutputFiles = [];
        $this->resultCacheFiles      = [];
        $this->testResultFiles       = [];
        $this->coverageFiles         = [];
        $this->junitFiles            = [];
        $this->teamcityFiles         = [];
        $this->testdoxFiles          = [];

        $this->startWorkers();
        $this->assignAllPendingTests();
        $this->waitForAllToFinish();

        // Aggregate this attempt's per-worker TestResult files into a single
        // `TestResult` — mirrors the first half of `complete()` but scoped to
        // this attempt's files only. The final-attempt copy of this aggregate
        // also flows into `complete()` as the primary test result.
        $attemptTestResult = TestResultFacade::result();
        foreach ($this->testResultFiles as $testresultFile) {
            if (! $testresultFile->isFile()) {
                continue;
            }

            $contents = file_get_contents($testresultFile->getPathname());
            assert($contents !== false);
            $workerResult = unserialize($contents);
            assert($workerResult instanceof TestResult);

            $attemptTestResult = $this->mergeTestResults($attemptTestResult, $workerResult);
        }

        $outcome = new AttemptOutcome(
            $attempt,
            $pending,
            $this->junitFiles,
            $this->coverageFiles,
            $this->testResultFiles,
            $this->testdoxFiles,
            $this->teamcityFiles,
            $this->resultCacheFiles,
            $this->progressFiles,
            $this->unexpectedOutputFiles,
            $this->statusFiles,
            $this->exitcode,
            $attemptTestResult,
        );

        // Clear per-attempt arrays so the next attempt starts clean.
        $this->statusFiles           = [];
        $this->progressFiles         = [];
        $this->unexpectedOutputFiles = [];
        $this->resultCacheFiles      = [];
        $this->testResultFiles       = [];
        $this->coverageFiles         = [];
        $this->junitFiles            = [];
        $this->teamcityFiles         = [];
        $this->testdoxFiles          = [];

        return $outcome;
    }

    private function isAnyStopOnFlagSet(): bool
    {
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

    private function startWorkers(): void
    {
        for ($token = 1; $token <= $this->options->processes; ++$token) {
            $this->startWorker($token);
        }
    }

    private function assignAllPendingTests(): void
    {
        $batchSize = $this->options->maxBatchSize;

        while (count($this->pending) > 0 && count($this->workers) > 0) {
            foreach ($this->workers as $token => $worker) {
                if (! $worker->isRunning()) {
                    throw $worker->getWorkerCrashedException();
                }

                if (! $worker->isFree()) {
                    continue;
                }

                $this->flushWorker($worker);

                if ($batchSize !== 0 && $this->batches[$token] === $batchSize) {
                    $this->destroyWorker($token);
                    $worker = $this->startWorker($token);
                }

                if (
                    $this->exitcode > 0
                    && $this->options->configuration->stopOnFailure()
                ) {
                    $this->pending = [];
                } elseif (($pending = array_shift($this->pending)) !== null) {
                    $worker->assign($pending);
                    $this->batches[$token]++;
                }
            }

            usleep(self::CYCLE_SLEEP);
        }
    }

    private function flushWorker(WrapperWorker $worker): void
    {
        if ($worker->hasExecutedTests()) {
            $testResultFile = $worker->testResultFile->getPathname();
            if ($testResultFile !== '') {
                $this->requiredTestResultFiles[$testResultFile] = true;
            }

            if (isset($worker->coverageFile) && $worker->coverageFile->getPathname() !== '') {
                $this->requiredCoverageFiles[$worker->coverageFile->getPathname()] = true;
            }
        }

        $this->exitcode = max($this->exitcode, $worker->getExitCode());
        $this->printer->printFeedback(
            $worker->progressFile,
            $worker->unexpectedOutputFile,
            $worker->teamcityFile ?? null,
        );
        $worker->reset();
    }

    private function waitForAllToFinish(): void
    {
        $stopped = [];
        while (count($this->workers) > 0) {
            foreach ($this->workers as $index => $worker) {
                if ($worker->isRunning()) {
                    if (! isset($stopped[$index]) && $worker->isFree()) {
                        $worker->stop();
                        $stopped[$index] = true;
                    }

                    continue;
                }

                if (! $worker->isFree()) {
                    throw $worker->getWorkerCrashedException();
                }

                $this->flushWorker($worker);
                unset($this->workers[$index]);
            }

            usleep(self::CYCLE_SLEEP);
        }
    }

    /** @param positive-int $token */
    private function startWorker(int $token): WrapperWorker
    {
        $worker = new WrapperWorker(
            $this->output,
            $this->options,
            $this->parameters,
            $token,
        );
        $worker->start();
        $this->batches[$token] = 0;

        $this->statusFiles[]           = $worker->statusFile;
        $this->progressFiles[]         = $worker->progressFile;
        $this->unexpectedOutputFiles[] = $worker->unexpectedOutputFile;
        $this->testResultFiles[]       = $worker->testResultFile;

        if (isset($worker->resultCacheFile)) {
            $this->resultCacheFiles[] = $worker->resultCacheFile;
        }

        if (isset($worker->junitFile)) {
            $this->junitFiles[] = $worker->junitFile;
        }

        if (isset($worker->coverageFile)) {
            $this->coverageFiles[] = $worker->coverageFile;
        }

        if (isset($worker->teamcityFile)) {
            $this->teamcityFiles[] = $worker->teamcityFile;
        }

        if (isset($worker->testdoxFile)) {
            $this->testdoxFiles[] = $worker->testdoxFile;
        }

        return $this->workers[$token] = $worker;
    }

    private function destroyWorker(int $token): void
    {
        $this->workers[$token]->stop();
        // We need to wait for ApplicationForWrapperWorker::end to end
        while ($this->workers[$token]->isRunning()) {
            usleep(self::CYCLE_SLEEP);
        }

        unset($this->workers[$token]);
    }

    /**
     * Aggregates every attempt's artifacts into final reports (coverage, JUnit,
     * TestDox, TeamCity), computes the shell exit code, and cleans up tmp files.
     *
     * `$attempts` is the list of per-attempt snapshots. With retry=0 it is a
     * single-element list and behavior is bit-identical to pre-refactor.
     * With retry>0, coverage aggregates every attempt's files (R6); JUnit
     * uses all attempts when `--junit-retry-metadata` is on and final-attempt
     * only otherwise (R1); TestDox and TeamCity always read final-attempt only.
     *
     * @param list<AttemptOutcome> $attemptOutcomesInput
     */
    private function complete(array $attemptOutcomesInput, ?RetryResult $retryResult): int
    {
        // Rebuild required-file lists from attempt outcomes: the orchestrator
        // moves per-attempt files to `{tmpDir}/attempt-N/` via rename(), so the
        // paths recorded during flushWorker() point at non-existent locations.
        // Outcomes carry post-archival paths (or original paths for final).
        /** @var array<non-empty-string, true> $rebuiltTestResult */
        $rebuiltTestResult = [];
        /** @var array<non-empty-string, true> $rebuiltCoverage */
        $rebuiltCoverage = [];
        foreach ($attemptOutcomesInput as $outcome) {
            foreach ($outcome->testResultFiles as $f) {
                $path = $f->getPathname();
                if ($path === '') {
                    continue;
                }

                $rebuiltTestResult[$path] = true;
            }

            foreach ($outcome->coverageFiles as $f) {
                $path = $f->getPathname();
                if ($path === '') {
                    continue;
                }

                $rebuiltCoverage[$path] = true;
            }
        }

        $this->requiredTestResultFiles = $rebuiltTestResult;
        $this->requiredCoverageFiles   = $rebuiltCoverage;

        // Validate test result files for workers that executed tests (across
        // every attempt — workers that crashed mid-attempt would be flagged here).
        /** @var list<non-empty-string> $missingTestResultFiles */
        $missingTestResultFiles = [];
        foreach ($this->requiredTestResultFiles as $filePath => $true) {
            if (is_file($filePath)) {
                continue;
            }

            $missingTestResultFiles[] = $filePath;
        }

        if ($missingTestResultFiles !== []) {
            throw MissingResultsException::create($missingTestResultFiles, 'test_result');
        }

        assert($attemptOutcomesInput !== []);
        $finalAttempt = end($attemptOutcomesInput);

        // The final attempt's aggregate determines pass/fail — R2 "exit 0 iff
        // last attempt passed". Issues from prior attempts are deliberately not
        // summed in because a passed retry should not inherit attempt-1 errors.
        $testResultSum = $finalAttempt->testResultAggregate;

        // Replay the last attempt's per-worker test result files into the
        // baseline TestResultFacade sum so this code path stays byte-identical
        // to the pre-refactor behavior when retry=0 (there's only one attempt
        // and its aggregate already reflects the worker files).
        // For retry > 0, finalAttempt->testResultAggregate is the correct
        // authoritative value since it was built from the final attempt's
        // worker result files at runAttempt() time.

        // Pool per-attempt result-cache files so cache-aware consumers
        // (e.g., `--order-by=defects`) still see the final outcome.
        if ($this->options->configuration->cacheResult()) {
            $resultCacheSum = new DefaultResultCache($this->options->configuration->testResultCacheFile());
            foreach ($attemptOutcomesInput as $attempt) {
                foreach ($attempt->resultCacheFiles as $resultCacheFile) {
                    if (! $resultCacheFile->isFile()) {
                        continue;
                    }

                    $resultCache = new DefaultResultCache($resultCacheFile->getPathname());
                    $resultCache->load();

                    $resultCacheSum->mergeWith($resultCache);
                }
            }

            // H2 — tests that failed then passed (flaky) must be re-written as
            // FAILURE in the cache so `--order-by=defects` prioritizes them on
            // the next run. See docs/retry-feature-devils-advocate.md H2.
            if ($retryResult !== null && $retryResult->flakyTests !== []) {
                foreach ($retryResult->flakyTests as $flakyKey) {
                    if (! str_contains($flakyKey, '::')) {
                        continue;
                    }

                    [$class, $method] = explode('::', $flakyKey, 2);
                    if ($class === '' || $method === '') {
                        continue;
                    }

                    // `$class` came from parsed JUnit XML written by PHPUnit itself —
                    // it is a real loaded test-case class name. We assert the
                    // `class-string<TestCase>` contract with `is_a()` so phpstan is
                    // satisfied and unexpected values (e.g. PHPT discriminator)
                    // are skipped gracefully rather than poisoning the cache.
                    if (! is_a($class, \PHPUnit\Framework\TestCase::class, true)) {
                        continue;
                    }

                    $resultCacheSum->setStatus(
                        ResultCacheId::fromTestClassAndMethodName($class, $method),
                        TestStatus::failure('Flaky: failed at least once during --retry run'),
                    );
                }
            }

            $resultCacheSum->persist();
        }

        // TestDox — final attempt only. See design §3.6.
        $testdoxResults = (new TestDoxResultsMerger())->getResultsFromTestdoxFiles($finalAttempt->testdoxFiles);

        $this->printer->printResults(
            $testResultSum,
            $finalAttempt->teamcityFiles,
            $testdoxResults,
            $retryResult !== null ? $retryResult->flakyTests : [],
        );
        $this->generateCodeCoverageReports($attemptOutcomesInput);
        $this->generateJunitLog($attemptOutcomesInput);
        $this->generateTestDoxLogs($testdoxResults);

        $exitcode = (new ShellExitCodeCalculator())->calculate(
            $this->options->configuration,
            $testResultSum,
        );

        // Clean up artifacts from every attempt (retry=0 → single attempt).
        foreach ($attemptOutcomesInput as $attempt) {
            $this->clearFiles($attempt->statusFiles);
            $this->clearFiles($attempt->progressFiles);
            $this->clearFiles($attempt->unexpectedOutputFiles);
            $this->clearFiles($attempt->testResultFiles);
            $this->clearFiles($attempt->resultCacheFiles);
            $this->clearFiles($attempt->coverageFiles);
            $this->clearFiles($attempt->junitFiles);
            $this->clearFiles($attempt->teamcityFiles);
            $this->clearFiles($attempt->testdoxFiles);
        }

        return $exitcode;
    }

    /**
     * Merges two `TestResult` snapshots identically to the prior in-line
     * aggregation in `complete()`. Extracted for reuse by `runAttempt()`.
     */
    private function mergeTestResults(TestResult $left, TestResult $right): TestResult
    {
        return new TestResult(
            (int) $left->hasTests() + (int) $right->hasTests(),
            $left->numberOfTestsRun() + $right->numberOfTestsRun(),
            $left->numberOfAssertions() + $right->numberOfAssertions(),
            array_merge_recursive($left->testErroredEvents(), $right->testErroredEvents()),
            array_merge_recursive($left->testFailedEvents(), $right->testFailedEvents()),
            array_merge_recursive($left->testConsideredRiskyEvents(), $right->testConsideredRiskyEvents()),
            array_merge_recursive($left->testSuiteSkippedEvents(), $right->testSuiteSkippedEvents()),
            array_merge_recursive($left->testSkippedEvents(), $right->testSkippedEvents()),
            array_merge_recursive($left->testMarkedIncompleteEvents(), $right->testMarkedIncompleteEvents()),
            array_merge_recursive($left->testTriggeredPhpunitDeprecationEvents(), $right->testTriggeredPhpunitDeprecationEvents()),
            array_merge_recursive($left->testTriggeredPhpunitErrorEvents(), $right->testTriggeredPhpunitErrorEvents()),
            array_merge_recursive($left->testTriggeredPhpunitNoticeEvents(), $right->testTriggeredPhpunitNoticeEvents()),
            array_merge_recursive($left->testTriggeredPhpunitWarningEvents(), $right->testTriggeredPhpunitWarningEvents()),
            array_merge_recursive($left->testRunnerTriggeredDeprecationEvents(), $right->testRunnerTriggeredDeprecationEvents()),
            array_merge_recursive($left->testRunnerTriggeredNoticeEvents(), $right->testRunnerTriggeredNoticeEvents()),
            array_merge_recursive($left->testRunnerTriggeredWarningEvents(), $right->testRunnerTriggeredWarningEvents()),
            array_merge_recursive($left->errors(), $right->errors()),
            array_merge_recursive($left->deprecations(), $right->deprecations()),
            array_merge_recursive($left->notices(), $right->notices()),
            array_merge_recursive($left->warnings(), $right->warnings()),
            array_merge_recursive($left->phpDeprecations(), $right->phpDeprecations()),
            array_merge_recursive($left->phpNotices(), $right->phpNotices()),
            array_merge_recursive($left->phpWarnings(), $right->phpWarnings()),
            $left->numberOfIssuesIgnoredByBaseline() + $right->numberOfIssuesIgnoredByBaseline(),
        );
    }

    /** @param list<AttemptOutcome> $attempts */
    protected function generateCodeCoverageReports(array $attempts): void
    {
        // R6 — coverage is unioned across every attempt's coverage files.
        // `Merger::merge()` handles multi-file merge natively.
        $allCoverageFiles = [];
        foreach ($attempts as $attempt) {
            foreach ($attempt->coverageFiles as $file) {
                $allCoverageFiles[] = $file;
            }
        }

        if ($allCoverageFiles === []) {
            return;
        }

        // H6 — coverage union may inflate coverage with lines only exercised in
        // failing attempts. Warn loudly.
        if ($this->options->retry > 0) {
            $this->output->writeln(
                '<comment>Warning: --retry>0 produces union coverage; lines exercised only in failing '
                . 'attempts appear covered.</comment>',
            );
        }

        // Validate coverage files for workers that executed tests
        $missingCoverageFiles = [];
        foreach ($this->requiredCoverageFiles as $filePath => $true) {
            if (is_file($filePath) && filesize($filePath) !== 0) {
                continue;
            }

            $missingCoverageFiles[] = $filePath;
        }

        if ($missingCoverageFiles !== []) {
            throw MissingResultsException::create($missingCoverageFiles, 'coverage');
        }

        $coverageManager = new CodeCoverage();
        $coverageManager->init(
            $this->options->configuration,
            $this->codeCoverageFilterRegistry,
            false,
        );
        $coverageFiles      = array_map(static function (SplFileInfo $fileInfo): false|string {
            return $fileInfo->getRealPath();
        }, $allCoverageFiles);
        $coverageFiles      = array_filter($coverageFiles, static function (false|string $file): bool {
            return $file !== false;
        });
        $serializedCoverage = (new Merger())->merge($coverageFiles);

        $report       = (new Builder(new FileAnalyser(new ParsingSourceAnalyser(), false, false)))->build(
            $serializedCoverage['codeCoverage'],
            $serializedCoverage['testResults'],
            $serializedCoverage['basePath'],
        );
        $codeCoverage = $coverageManager->codeCoverage();
        $codeCoverage->excludeUncoveredFiles();
        $codeCoverageData = clone $serializedCoverage['codeCoverage'];
        if ($serializedCoverage['basePath'] !== '') {
            foreach ($codeCoverageData->coveredFiles() as $file) {
                if (! self::isRelativePath($file)) {
                    continue;
                }

                $codeCoverageData->renameFile($file, $serializedCoverage['basePath'] . DIRECTORY_SEPARATOR . $file);
            }
        }

        $codeCoverage->setData($codeCoverageData);
        $codeCoverage->setTests($serializedCoverage['testResults']);
        (new ReflectionProperty(\SebastianBergmann\CodeCoverage\CodeCoverage::class, 'cachedReport'))->setValue($codeCoverage, $report);

        $coverageManager->generateReports(
            $this->printer->printer,
            $this->options->configuration,
        );
    }

    private static function isRelativePath(string $path): bool
    {
        return ! str_starts_with($path, 'phar://')
            && ! str_starts_with($path, DIRECTORY_SEPARATOR)
            && preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) !== 1;
    }

    /** @param list<AttemptOutcome> $attempts */
    private function generateJunitLog(array $attempts): void
    {
        // JUnit XML is written only when the user configured `--log-junit`.
        // Per-worker junit files may still exist (they are always generated
        // when `--retry > 0` so the retry extractor can identify failures),
        // but we must not try to serialize back to a destination path that
        // was never configured — `logfileJunit()` throws in that case.
        if (! $this->options->configuration->hasLogfileJunit()) {
            return;
        }

        $finalAttempt = end($attempts);
        assert($finalAttempt instanceof AttemptOutcome);

        if ($finalAttempt->junitFiles === []) {
            return;
        }

        // R1 — with `--junit-retry-metadata` off, JUnit XML contains only the
        // final attempt's data (bit-identical to pre-retry behavior). When on,
        // cross-attempt Surefire-convention elements are emitted by
        // `LogMerger::mergeAcrossAttempts()`.
        $logMerger = new LogMerger();

        if ($this->options->junitRetryMetadata && count($attempts) > 1) {
            /** @var array<int, list<SplFileInfo>> $perAttemptJunitFiles */
            $perAttemptJunitFiles = [];
            foreach ($attempts as $attempt) {
                if ($attempt->junitFiles === []) {
                    continue;
                }

                $perAttemptJunitFiles[$attempt->attemptNumber] = $attempt->junitFiles;
            }

            $testSuite = $logMerger->mergeAcrossAttempts($perAttemptJunitFiles);
        } else {
            $testSuite = $logMerger->merge($finalAttempt->junitFiles);
        }

        if ($testSuite === null) {
            return;
        }

        (new Writer())->write(
            $testSuite,
            $this->options->configuration->logfileJunit(),
        );
    }

    /** @param array<class-string, TestDoxTestResultCollection> $testdoxResults */
    private function generateTestDoxLogs(array $testdoxResults): void
    {
        if ($this->options->configuration->hasLogfileTestdoxText()) {
            $testdoxTextContent = (new TestDoxPlainTextRenderer())->render($testdoxResults);
            DefaultPrinter::from($this->options->configuration->logfileTestdoxText())->print($testdoxTextContent);
        }

        if (! $this->options->configuration->hasLogfileTestdoxHtml()) {
            return;
        }

        $testdoxHtmlContent = (new TestDoxHtmlRenderer())->render($testdoxResults);
        DefaultPrinter::from($this->options->configuration->logfileTestdoxHtml())->print($testdoxHtmlContent);
    }

    /** @param list<SplFileInfo> $files */
    private function clearFiles(array $files): void
    {
        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }

            unlink($file->getPathname());
        }
    }
}
