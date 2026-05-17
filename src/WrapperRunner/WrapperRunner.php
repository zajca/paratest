<?php

declare(strict_types=1);

namespace ParaTest\WrapperRunner;

use ParaTest\JUnit\FailedTestExtractor;
use ParaTest\JUnit\LogMerger;
use ParaTest\JUnit\MessageType;
use ParaTest\JUnit\TestCase as JUnitTestCase;
use ParaTest\JUnit\TestCaseWithMessage;
use ParaTest\JUnit\TestSuite as JUnitTestSuite;
use ParaTest\JUnit\Writer;
use ParaTest\Options;
use ParaTest\RunnerInterface;
use ParaTest\TestDox\TestDoxResultsMerger;
use PHPUnit\Event\Code\Test as CodeTest;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\ConsideredRisky;
use PHPUnit\Event\Test\MarkedIncomplete;
use PHPUnit\Event\Test\PhpunitDeprecationTriggered;
use PHPUnit\Event\Test\PhpunitErrorTriggered;
use PHPUnit\Event\Test\PhpunitNoticeTriggered;
use PHPUnit\Event\Test\PhpunitWarningTriggered;
use PHPUnit\Event\Test\Skipped as TestSkipped;
use PHPUnit\Event\TestSuite\Skipped as TestSuiteSkipped;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestStatus\TestStatus;
use PHPUnit\Logging\TestDox\HtmlRenderer as TestDoxHtmlRenderer;
use PHPUnit\Logging\TestDox\PlainTextRenderer as TestDoxPlainTextRenderer;
use PHPUnit\Logging\TestDox\TestResultCollection as TestDoxTestResultCollection;
use PHPUnit\Runner\CodeCoverage;
use PHPUnit\Runner\ResultCache\DefaultResultCache;
use PHPUnit\Runner\ResultCache\ResultCacheId;
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
use function array_replace;
use function array_shift;
use function array_values;
use function assert;
use function count;
use function dirname;
use function end;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function filesize;
use function is_a;
use function is_file;
use function max;
use function preg_match;
use function realpath;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function uniqid;
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

        $extractor    = new FailedTestExtractor(
            $retryOn,
            $suiteLoader->dependsMap,
            $this->options->functional,
            $suiteLoader->testFileMap,
        );
        $orchestrator = new RetryOrchestrator(
            $this->options,
            $this->output,
            $suiteLoader,
            $extractor,
        );

        $retryResult = $orchestrator->orchestrate(
            $suiteLoader->tests,
            function (int $attempt, array $pending): AttemptOutcome {
                // TeamCity retry output is replayed after the orchestrator knows
                // which attempt ended the run. Suppressing live output for every
                // retry attempt avoids consuming the final attempt's tmp log when
                // the run finishes before the maximum attempt count.
                $this->printer->setSuppressTeamcityStdout(true);

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
     * @param list<non-empty-string> $pending
     */
    private function runAttempt(int $attempt, array $pending): AttemptOutcome
    {
        if ($attempt > 1) {
            $this->printer->resetProgress();
        }

        $this->pending                 = $pending;
        $this->exitcode                = -1;
        $this->workers                 = [];
        $this->batches                 = [];
        $this->statusFiles             = [];
        $this->progressFiles           = [];
        $this->unexpectedOutputFiles   = [];
        $this->resultCacheFiles        = [];
        $this->testResultFiles         = [];
        $this->coverageFiles           = [];
        $this->junitFiles              = [];
        $this->teamcityFiles           = [];
        $this->testdoxFiles            = [];
        $this->requiredTestResultFiles = [];
        $this->requiredCoverageFiles   = [];

        $this->startWorkers();
        $this->assignAllPendingTests();
        $this->waitForAllToFinish();

        // Aggregate this attempt's per-worker TestResult files into a single
        // `TestResult` — mirrors the first half of `complete()` but scoped to
        // this attempt's files only. The final-attempt copy of this aggregate
        // also flows into `complete()` as the primary test result.
        $attemptTestResult = $this->emptyTestResult();
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
            $this->toSplFileInfoList($this->requiredTestResultFiles),
            $this->toSplFileInfoList($this->requiredCoverageFiles),
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
        /** @var array<non-empty-string, true> $rebuiltTestResult */
        $rebuiltTestResult = [];
        /** @var array<non-empty-string, true> $rebuiltCoverage */
        $rebuiltCoverage = [];
        foreach ($attemptOutcomesInput as $outcome) {
            foreach ($outcome->requiredTestResultFiles as $f) {
                $path = $f->getPathname();
                if ($path === '') {
                    continue;
                }

                $rebuiltTestResult[$path] = true;
            }

            foreach ($outcome->requiredCoverageFiles as $f) {
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

        $effectiveSuite = count($attemptOutcomesInput) > 1
            ? $this->effectiveJunitSuite($attemptOutcomesInput)
            : null;

        $testResultSum = $effectiveSuite === null
            ? $finalAttempt->testResultAggregate
            : $this->effectiveTestResult($attemptOutcomesInput, $effectiveSuite);

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
            // the next run.
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
                    if (! is_a($class, TestCase::class, true)) {
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

        $testdoxResults = (new TestDoxResultsMerger())->getResultsFromTestdoxFiles($this->collectTestDoxFiles($attemptOutcomesInput));

        $teamcityFiles = $this->collectTeamcityFiles($attemptOutcomesInput, $effectiveSuite);

        $this->printer->printResults(
            $testResultSum,
            $teamcityFiles,
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

        $this->clearSyntheticTeamcityFiles($teamcityFiles, $attemptOutcomesInput);

        return $exitcode;
    }

    /**
     * @param array<non-empty-string, true> $files
     *
     * @return list<SplFileInfo>
     */
    private function toSplFileInfoList(array $files): array
    {
        $result = [];
        foreach ($files as $path => $true) {
            $result[] = new SplFileInfo($path);
        }

        return $result;
    }

    /** @param list<AttemptOutcome> $attempts */
    private function effectiveJunitSuite(array $attempts): ?JUnitTestSuite
    {
        /** @var array<int, list<SplFileInfo>> $perAttemptJunitFiles */
        $perAttemptJunitFiles = [];
        foreach ($attempts as $attempt) {
            if ($attempt->junitFiles === []) {
                continue;
            }

            $perAttemptJunitFiles[$attempt->attemptNumber] = $attempt->junitFiles;
        }

        if ($perAttemptJunitFiles === []) {
            return null;
        }

        return (new LogMerger())->mergeAcrossAttempts($perAttemptJunitFiles, false);
    }

    /** @param list<AttemptOutcome> $attempts */
    private function effectiveTestResult(array $attempts, JUnitTestSuite $effectiveSuite): TestResult
    {
        $finalAttempt = end($attempts);
        assert($finalAttempt instanceof AttemptOutcome);

        $merged = $this->emptyTestResult();
        /** @var array<string, list<ConsideredRisky>> $testConsideredRiskyEvents */
        $testConsideredRiskyEvents = [];
        /** @var array<string, list<PhpunitDeprecationTriggered>> $testTriggeredPhpunitDeprecationEvents */
        $testTriggeredPhpunitDeprecationEvents = [];
        /** @var array<string, list<PhpunitErrorTriggered>> $testTriggeredPhpunitErrorEvents */
        $testTriggeredPhpunitErrorEvents = [];
        /** @var array<string, list<PhpunitNoticeTriggered>> $testTriggeredPhpunitNoticeEvents */
        $testTriggeredPhpunitNoticeEvents = [];
        /** @var array<string, list<PhpunitWarningTriggered>> $testTriggeredPhpunitWarningEvents */
        $testTriggeredPhpunitWarningEvents = [];
        /** @var array<string, true> $effectiveSkippedKeys */
        $effectiveSkippedKeys = [];
        /** @var array<string, true> $effectiveIncompleteKeys */
        $effectiveIncompleteKeys = [];
        $this->collectEffectiveIssueKeys($effectiveSuite, $effectiveSkippedKeys, $effectiveIncompleteKeys);

        foreach ($attempts as $attempt) {
            $merged                                = $this->mergeTestResults($merged, $attempt->testResultAggregate);
            $testConsideredRiskyEvents             = array_replace($testConsideredRiskyEvents, $attempt->testResultAggregate->testConsideredRiskyEvents());
            $testTriggeredPhpunitDeprecationEvents = array_replace($testTriggeredPhpunitDeprecationEvents, $attempt->testResultAggregate->testTriggeredPhpunitDeprecationEvents());
            $testTriggeredPhpunitErrorEvents       = array_replace($testTriggeredPhpunitErrorEvents, $attempt->testResultAggregate->testTriggeredPhpunitErrorEvents());
            $testTriggeredPhpunitNoticeEvents      = array_replace($testTriggeredPhpunitNoticeEvents, $attempt->testResultAggregate->testTriggeredPhpunitNoticeEvents());
            $testTriggeredPhpunitWarningEvents     = array_replace($testTriggeredPhpunitWarningEvents, $attempt->testResultAggregate->testTriggeredPhpunitWarningEvents());
        }

        return new TestResult(
            $effectiveSuite->tests,
            $effectiveSuite->tests,
            $effectiveSuite->assertions,
            $finalAttempt->testResultAggregate->testErroredEvents(),
            $finalAttempt->testResultAggregate->testFailedEvents(),
            $testConsideredRiskyEvents,
            $this->effectiveTestSuiteSkippedEvents($attempts, $effectiveSkippedKeys),
            $this->effectiveTestSkippedEvents($attempts, $effectiveSkippedKeys),
            $this->effectiveTestMarkedIncompleteEvents($attempts, $effectiveIncompleteKeys),
            $testTriggeredPhpunitDeprecationEvents,
            $testTriggeredPhpunitErrorEvents,
            $testTriggeredPhpunitNoticeEvents,
            $testTriggeredPhpunitWarningEvents,
            $merged->testRunnerTriggeredDeprecationEvents(),
            $merged->testRunnerTriggeredNoticeEvents(),
            $merged->testRunnerTriggeredWarningEvents(),
            $merged->errors(),
            $merged->deprecations(),
            $merged->notices(),
            $merged->warnings(),
            $merged->phpDeprecations(),
            $merged->phpNotices(),
            $merged->phpWarnings(),
            $merged->numberOfIssuesIgnoredByBaseline(),
        );
    }

    /**
     * @param array<string, true> $skippedKeys
     * @param array<string, true> $incompleteKeys
     */
    private function collectEffectiveIssueKeys(
        JUnitTestSuite $suite,
        array &$skippedKeys,
        array &$incompleteKeys
    ): void {
        foreach ($suite->suites as $child) {
            $this->collectEffectiveIssueKeys($child, $skippedKeys, $incompleteKeys);
        }

        foreach ($suite->cases as $case) {
            if (! $case instanceof TestCaseWithMessage) {
                continue;
            }

            $key = $this->junitCaseKey($case);
            if ($case->xmlTagName !== MessageType::skipped) {
                continue;
            }

            $skippedKeys[$key]    = true;
            $incompleteKeys[$key] = true;
        }
    }

    /**
     * @param list<AttemptOutcome> $attempts
     * @param array<string, true>  $effectiveSkippedKeys
     *
     * @return list<TestSuiteSkipped>
     */
    private function effectiveTestSuiteSkippedEvents(array $attempts, array $effectiveSkippedKeys): array
    {
        if ($effectiveSkippedKeys === []) {
            return [];
        }

        $events = [];
        foreach ($attempts as $attempt) {
            foreach ($attempt->testResultAggregate->testSuiteSkippedEvents() as $event) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * @param list<AttemptOutcome> $attempts
     * @param array<string, true>  $effectiveSkippedKeys
     *
     * @return list<TestSkipped>
     */
    private function effectiveTestSkippedEvents(array $attempts, array $effectiveSkippedKeys): array
    {
        if ($effectiveSkippedKeys === []) {
            return [];
        }

        /** @var array<string, TestSkipped> $events */
        $events = [];
        foreach ($attempts as $attempt) {
            foreach ($attempt->testResultAggregate->testSkippedEvents() as $event) {
                $key = $this->codeTestKey($event->test());
                if (! isset($effectiveSkippedKeys[$key])) {
                    continue;
                }

                $events[$key] = $event;
            }
        }

        return array_values($events);
    }

    /**
     * @param list<AttemptOutcome> $attempts
     * @param array<string, true>  $effectiveIncompleteKeys
     *
     * @return list<MarkedIncomplete>
     */
    private function effectiveTestMarkedIncompleteEvents(array $attempts, array $effectiveIncompleteKeys): array
    {
        if ($effectiveIncompleteKeys === []) {
            return [];
        }

        /** @var array<string, MarkedIncomplete> $events */
        $events = [];
        foreach ($attempts as $attempt) {
            foreach ($attempt->testResultAggregate->testMarkedIncompleteEvents() as $event) {
                $key = $this->codeTestKey($event->test());
                if (! isset($effectiveIncompleteKeys[$key])) {
                    continue;
                }

                $events[$key] = $event;
            }
        }

        return array_values($events);
    }

    /**
     * @param list<AttemptOutcome> $attempts
     *
     * @return list<SplFileInfo>
     */
    private function collectTeamcityFiles(array $attempts, ?JUnitTestSuite $effectiveSuite): array
    {
        if ($effectiveSuite !== null && $this->options->needsTeamcity) {
            return [$this->writeEffectiveTeamcityFile($effectiveSuite)];
        }

        $files = [];
        foreach ($attempts as $attempt) {
            foreach ($attempt->teamcityFiles as $file) {
                $files[] = $file;
            }
        }

        return $files;
    }

    private function writeEffectiveTeamcityFile(JUnitTestSuite $suite): SplFileInfo
    {
        $path     = $this->options->tmpDir . DIRECTORY_SEPARATOR . 'teamcity-effective-' . uniqid('', true) . '.log';
        $content  = sprintf(
            "##teamcity[testCount count='%d']\n",
            $suite->tests,
        );
        $content .= $this->teamcityMessage('testSuiteStarted', ['name' => $suite->name]);
        $content .= $this->teamcityCases($suite);
        $content .= $this->teamcityMessage('testSuiteFinished', ['name' => $suite->name]);

        $result = file_put_contents($path, $content);
        assert($result !== false);

        return new SplFileInfo($path);
    }

    private function teamcityCases(JUnitTestSuite $suite): string
    {
        $content = '';
        foreach ($suite->suites as $child) {
            $content .= $this->teamcityCases($child);
        }

        foreach ($suite->cases as $case) {
            $content .= $this->teamcityMessage('testStarted', ['name' => $case->name]);

            if ($case instanceof TestCaseWithMessage) {
                $content .= match ($case->xmlTagName) {
                    MessageType::failure, MessageType::error => $this->teamcityMessage('testFailed', [
                        'name' => $case->name,
                        'message' => $case->text,
                        'details' => $case->text,
                    ]),
                    MessageType::skipped => $this->teamcityMessage('testIgnored', [
                        'name' => $case->name,
                        'message' => $case->text,
                    ]),
                };
            }

            $content .= $this->teamcityMessage('testFinished', ['name' => $case->name]);
        }

        return $content;
    }

    /** @param array<non-empty-string, int|string> $parameters */
    private function teamcityMessage(string $eventName, array $parameters): string
    {
        $content = sprintf('##teamcity[%s', $eventName);
        foreach ($parameters as $key => $value) {
            $content .= sprintf(
                " %s='%s'",
                $key,
                $this->escapeTeamcityValue((string) $value),
            );
        }

        return $content . "]\n";
    }

    private function escapeTeamcityValue(string $value): string
    {
        return str_replace(
            ['|', "'", "\n", "\r", ']', '['],
            ['||', "|'", '|n', '|r', '|]', '|['],
            $value,
        );
    }

    /**
     * @param list<SplFileInfo>    $teamcityFiles
     * @param list<AttemptOutcome> $attempts
     */
    private function clearSyntheticTeamcityFiles(array $teamcityFiles, array $attempts): void
    {
        /** @var array<string, true> $attemptPaths */
        $attemptPaths = [];
        foreach ($attempts as $attempt) {
            foreach ($attempt->teamcityFiles as $file) {
                $attemptPaths[$file->getPathname()] = true;
            }
        }

        foreach ($teamcityFiles as $file) {
            if (isset($attemptPaths[$file->getPathname()])) {
                continue;
            }

            $this->clearFiles([$file]);
        }
    }

    private function junitCaseKey(JUnitTestCase $case): string
    {
        return $case->class . '::' . $case->name;
    }

    private function codeTestKey(CodeTest $test): string
    {
        if ($test instanceof TestMethod) {
            return $test->className() . '::' . $test->name();
        }

        return $test->id();
    }

    /**
     * @param list<AttemptOutcome> $attempts
     *
     * @return list<SplFileInfo>
     */
    private function collectTestDoxFiles(array $attempts): array
    {
        $files = [];
        foreach ($attempts as $attempt) {
            foreach ($attempt->testdoxFiles as $file) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * Merges two `TestResult` snapshots identically to the prior in-line
     * aggregation in `complete()`. Extracted for reuse by `runAttempt()`.
     */
    private function emptyTestResult(): TestResult
    {
        return new TestResult(
            0,
            0,
            0,
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            0,
        );
    }

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

        // Retry JUnit output is the effective latest outcome for every test.
        // Optional retry metadata decorates retried cases with Surefire
        // convention children without dropping first-attempt passes.
        $logMerger = new LogMerger();

        if (count($attempts) > 1) {
            /** @var array<int, list<SplFileInfo>> $perAttemptJunitFiles */
            $perAttemptJunitFiles = [];
            foreach ($attempts as $attempt) {
                if ($attempt->junitFiles === []) {
                    continue;
                }

                $perAttemptJunitFiles[$attempt->attemptNumber] = $attempt->junitFiles;
            }

            $testSuite = $logMerger->mergeAcrossAttempts(
                $perAttemptJunitFiles,
                $this->options->junitRetryMetadata,
            );
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
            $path = $file->getPathname();
            if ($path === '' || ! is_file($path)) {
                continue;
            }

            unlink($path);
        }
    }
}
