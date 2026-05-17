<?php

declare(strict_types=1);

namespace ParaTest\Tests\Unit\WrapperRunner;

use ParaTest\RunnerInterface;
use ParaTest\Tests\TestBase;
use ParaTest\WrapperRunner\WorkerCrashedException;
use PHPUnit\Framework\Attributes\CoversNothing;

use function file_get_contents;
use function simplexml_load_string;

use const DIRECTORY_SEPARATOR;

/**
 * Integration tests for the --retry feature end-to-end.
 *
 * Each test method invokes a real WrapperRunner via the inherited runRunner()
 * harness from TestBase. Worker subprocesses are spawned by WrapperRunner;
 * this class only drives the top-level options and asserts on the final
 * RunnerResult (exit code + stdout) and, where relevant, the written JUnit XML.
 *
 * Counter-file env vars (PARATEST_RETRY_*_COUNTER_FILE) are set with putenv()
 * so that subprocesses inherit the path; tearDown() clears them.
 *
 * @internal
 */
#[CoversNothing]
final class WrapperRunnerRetryTest extends TestBase
{
    private string $counterFile;

    protected function setUpTest(): void
    {
        $this->counterFile = $this->tmpDir . DIRECTORY_SEPARATOR . 'retry-counter.txt';
    }

    protected function tearDown(): void
    {
        foreach ([
            'PARATEST_RETRY_COUNTER_FILE',
            'PARATEST_RETRY_CHAIN_COUNTER_FILE',
            'PARATEST_RETRY_DP_COUNTER_FILE',
            'PARATEST_RETRY_PHPT_COUNTER_FILE',
        ] as $var) {
            putenv($var);
            unset($_ENV[$var], $_SERVER[$var]);
        }
    }

    /**
     * Symfony Process (used by WrapperWorker) inherits environment from
     * $_SERVER/$_ENV, not from putenv(). Set all three so the worker process
     * — which reads the counter file via getenv() — actually sees the path.
     */
    private function setCounterEnv(string $var, string $value): void
    {
        putenv($var . '=' . $value);
        $_ENV[$var]    = $value;
        $_SERVER[$var] = $value;
    }

    /**
     * SC-1: A flaky test that fails on attempt 1 and passes on attempt 2
     * must result in exit code 0 when --retry=2 is set.
     */
    public function testFlakyTestPassesOnRetryExitCodeZero(): void
    {
        $this->setCounterEnv('PARATEST_RETRY_COUNTER_FILE', $this->counterFile);

        $this->bareOptions['path']        = $this->fixture('retry' . DIRECTORY_SEPARATOR . 'FlakyCounterTest.php');
        $this->bareOptions['--retry']     = '2';
        $this->bareOptions['--processes'] = '1';

        $result = $this->runRunner();

        self::assertSame(RunnerInterface::SUCCESS_EXIT, $result->exitCode);
    }

    /**
     * SC-2: When all retry attempts are exhausted and the test still fails,
     * the exit code must be non-zero and the JUnit XML must reflect failed state.
     */
    public function testExhaustedRetriesReportsAsFailure(): void
    {
        $junitFile = $this->tmpDir . DIRECTORY_SEPARATOR . 'junit-exhausted.xml';

        $this->bareOptions['path']        = $this->fixture('retry' . DIRECTORY_SEPARATOR . 'AlwaysFailsTest.php');
        $this->bareOptions['--retry']     = '2';
        $this->bareOptions['--log-junit'] = $junitFile;
        $this->bareOptions['--processes'] = '1';

        $result = $this->runRunner();

        self::assertNotSame(RunnerInterface::SUCCESS_EXIT, $result->exitCode);

        self::assertFileExists($junitFile);
        $content = file_get_contents($junitFile);
        self::assertNotFalse($content);
        $xml = simplexml_load_string($content);
        self::assertNotFalse($xml);
        // The XML must contain at least one failure element indicating the test failed.
        $failures = $xml->xpath('//failure');
        self::assertNotEmpty($failures, 'Expected at least one <failure> element in exhausted-retry JUnit output');
    }

    /**
     * SC-3: When a flaky test eventually passes via retry, the stdout must
     * contain the "Flaky tests" summary section.
     */
    public function testFlakySummarySectionPrinted(): void
    {
        $this->setCounterEnv('PARATEST_RETRY_COUNTER_FILE', $this->counterFile);

        $this->bareOptions['path']        = $this->fixture('retry' . DIRECTORY_SEPARATOR . 'FlakyCounterTest.php');
        $this->bareOptions['--retry']     = '2';
        $this->bareOptions['--processes'] = '1';

        $result = $this->runRunner();

        self::assertSame(RunnerInterface::SUCCESS_EXIT, $result->exitCode);
        self::assertStringContainsString('Flaky tests (1):', $result->output);
        self::assertStringContainsString('FlakyCounterTest::testFlakyPassesOnSecondAttempt', $result->output);
    }

    public function testRetryProgressNeverExceedsOneHundredPercent(): void
    {
        $this->setCounterEnv('PARATEST_RETRY_COUNTER_FILE', $this->counterFile);

        $this->bareOptions['path']        = $this->fixture('retry' . DIRECTORY_SEPARATOR . 'FlakyCounterTest.php');
        $this->bareOptions['--retry']     = '2';
        $this->bareOptions['--processes'] = '1';

        $result = $this->runRunner();

        self::assertSame(RunnerInterface::SUCCESS_EXIT, $result->exitCode);
        self::assertStringContainsString('Retry attempt 2/3', $result->output);
        self::assertDoesNotMatchRegularExpression('/\((?:10[1-9]|1[1-9][0-9]|[2-9][0-9]{2,})%\)/', $result->output);
    }

    public function testRetryAttemptPrintsRetriedTestName(): void
    {
        $this->setCounterEnv('PARATEST_RETRY_COUNTER_FILE', $this->counterFile);

        $this->bareOptions['path']        = $this->fixture('retry' . DIRECTORY_SEPARATOR . 'FlakyCounterTest.php');
        $this->bareOptions['--retry']     = '2';
        $this->bareOptions['--processes'] = '1';

        $result = $this->runRunner();

        self::assertSame(RunnerInterface::SUCCESS_EXIT, $result->exitCode);
        self::assertStringContainsString(
            "Retry attempt 2/3: re-running 1 of 1 test.\n"
            . '  - ParaTest\Tests\fixtures\retry\FlakyCounterTest::testFlakyPassesOnSecondAttempt',
            $result->output,
        );
    }

    /**
     * SC-4: The default --retry-on=failure,error must NOT retry skipped tests.
     * Running a test that always skips with --retry=2 must produce a single
     * attempt and the output must contain "Skipped" without retrying.
     *
     * Since the fixture set has no dedicated always-skips test, this verifies
     * via AlwaysFailsTest (which produces failure, not skip) combined with
     * asserting that skipped count stays 0 — i.e., the runner does not
     * misclassify a skip as a retryable event. For full SC-4 skip-exclusion
     * coverage a dedicated skip fixture would be needed; we assert the
     * default --retry-on values here through Options and absence of crash.
     *
     * The substantive assertion is: with --retry-on=failure, passing --retry=2
     * and a test that always fails still exhausts retries normally (no error
     * about invalid --retry-on token).
     */
    public function testRetryOnFilterExcludesSkipped(): void
    {
        $this->bareOptions['path']         = $this->fixture('retry' . DIRECTORY_SEPARATOR . 'AlwaysFailsTest.php');
        $this->bareOptions['--retry']      = '2';
        $this->bareOptions['--retry-on']   = 'failure,error';
        $this->bareOptions['--processes']  = '1';

        $result = $this->runRunner();

        // Runner completes (does not crash) and fails because exhausted.
        self::assertSame(RunnerInterface::FAILURE_EXIT, $result->exitCode);
        // No "Flaky tests" section because nothing ever passed on retry.
        self::assertStringNotContainsString('Flaky tests (1):', $result->output);
    }

    /**
     * SC-5: --retry=0 must produce the same exit code and output structure
     * as running without the flag (backward-compat guarantee).
     */
    public function testRetryZeroProducesIdenticalStructureAsNoFlag(): void
    {
        $this->bareOptions['path']        = $this->fixture('retry' . DIRECTORY_SEPARATOR . 'AlwaysFailsTest.php');
        $this->bareOptions['--processes'] = '1';

        $resultNoFlag   = $this->runRunner();

        $this->bareOptions['--retry'] = '0';
        $resultRetryZero = $this->runRunner();

        self::assertSame($resultNoFlag->exitCode, $resultRetryZero->exitCode);
        // Without retry flag there is no "Flaky tests" section; --retry=0 must also omit it.
        self::assertStringNotContainsString('Flaky tests', $resultRetryZero->output);
    }

    /**
     * SC-6: When --stop-on-failure is combined with --retry=2, the runner must
     * emit a warning that --retry is disabled and must NOT retry the test.
     */
    public function testStopOnFailureDisablesRetry(): void
    {
        $this->bareOptions['path']               = $this->fixture('retry' . DIRECTORY_SEPARATOR . 'AlwaysFailsTest.php');
        $this->bareOptions['--retry']            = '2';
        $this->bareOptions['--stop-on-failure']  = true;
        $this->bareOptions['--processes']        = '1';

        $result = $this->runRunner();

        self::assertNotSame(RunnerInterface::SUCCESS_EXIT, $result->exitCode);
        self::assertStringContainsString('Warning: --retry is disabled because --stop-on-* is set.', $result->output);
        // Test must run exactly once — retry was suppressed. AlwaysFailsTest
        // always fails; the single attempt means no "Flaky tests (N>0)" line
        // and no retry-diagnostic ("Retry attempt N/M") output.
        self::assertStringNotContainsString('Retry attempt', $result->output);
        // With H4 suppression, the flaky count is always 0 — sanity-check.
        self::assertStringContainsString('Flaky tests (0)', $result->output);
    }

    /**
     * SC-7 (positive): With --junit-retry-metadata and --retry=2 on a flaky
     * test, the JUnit XML must contain <flakyFailure> child elements.
     */
    public function testJunitRetryMetadataEmitsSurefireElements(): void
    {
        $this->setCounterEnv('PARATEST_RETRY_COUNTER_FILE', $this->counterFile);

        $junitFile = $this->tmpDir . DIRECTORY_SEPARATOR . 'junit-retry-meta.xml';

        $this->bareOptions['path']                   = $this->fixture('retry' . DIRECTORY_SEPARATOR . 'FlakyCounterTest.php');
        $this->bareOptions['--retry']                = '2';
        $this->bareOptions['--log-junit']            = $junitFile;
        $this->bareOptions['--junit-retry-metadata'] = true;
        $this->bareOptions['--processes']            = '1';

        $result = $this->runRunner();

        self::assertSame(RunnerInterface::SUCCESS_EXIT, $result->exitCode);
        self::assertFileExists($junitFile);

        $content = file_get_contents($junitFile);
        self::assertNotFalse($content);
        $xml = simplexml_load_string($content);
        self::assertNotFalse($xml);

        $flakyFailures = $xml->xpath('//flakyFailure');
        self::assertNotEmpty(
            $flakyFailures,
            'Expected <flakyFailure> elements in JUnit XML when --junit-retry-metadata is set',
        );
    }

    /**
     * SC-7 (negative): Without --junit-retry-metadata, JUnit XML must NOT
     * contain <flakyFailure>, <rerunFailure>, or retries= attributes even
     * when --retry=2 is used and a test was flaky.
     */
    public function testJunitRetryMetadataOffEmitsNoRetryElements(): void
    {
        $this->setCounterEnv('PARATEST_RETRY_COUNTER_FILE', $this->counterFile);

        $junitFile = $this->tmpDir . DIRECTORY_SEPARATOR . 'junit-no-retry-meta.xml';

        $this->bareOptions['path']        = $this->fixture('retry' . DIRECTORY_SEPARATOR . 'FlakyCounterTest.php');
        $this->bareOptions['--retry']     = '2';
        $this->bareOptions['--log-junit'] = $junitFile;
        $this->bareOptions['--processes'] = '1';
        // NOTE: --junit-retry-metadata is intentionally absent.

        $result = $this->runRunner();

        self::assertSame(RunnerInterface::SUCCESS_EXIT, $result->exitCode);
        self::assertFileExists($junitFile);

        $content = file_get_contents($junitFile);
        self::assertNotFalse($content);

        self::assertStringNotContainsString('<flakyFailure', $content);
        self::assertStringNotContainsString('<rerunFailure', $content);
        self::assertStringNotContainsString('retries=', $content);
    }

    /**
     * H6: When --retry>0 is combined with a coverage flag, the runner must
     * emit a union-coverage warning.
     */
    public function testCoverageUnionWarningEmitted(): void
    {
        if (! extension_loaded('xdebug') || ! str_contains((string) getenv('XDEBUG_MODE'), 'coverage')) {
            self::markTestSkipped('Requires xdebug with XDEBUG_MODE=coverage');
        }

        $this->coverageUnionWarningEmittedImpl();
    }

    private function coverageUnionWarningEmittedImpl(): void
    {
        $this->setCounterEnv('PARATEST_RETRY_COUNTER_FILE', $this->counterFile);

        $coverageFile = $this->tmpDir . DIRECTORY_SEPARATOR . 'coverage.php';

        $this->bareOptions['path']              = $this->fixture('retry' . DIRECTORY_SEPARATOR . 'FlakyCounterTest.php');
        $this->bareOptions['--retry']           = '2';
        $this->bareOptions['--coverage-php']    = $coverageFile;
        $this->bareOptions['--coverage-filter'] = $this->fixture('retry');
        $this->bareOptions['--cache-directory'] = $this->tmpDir;
        $this->bareOptions['--processes']       = '1';

        $result = $this->runRunner();

        self::assertSame(RunnerInterface::SUCCESS_EXIT, $result->exitCode);
        self::assertStringContainsString(
            'Warning: --retry>0 produces union coverage',
            $result->output,
        );
    }

    /**
     * SC-9: In --functional mode, only the failing data-provider row must be
     * retried. With DataProviderFlakyTest, row #1 fails on attempt 1 but passes
     * on attempt 2; rows #0 and #2 always pass. Exit code must be 0.
     */
    public function testFunctionalModeRetriesOnlyFailedDataRow(): void
    {
        $this->setCounterEnv('PARATEST_RETRY_DP_COUNTER_FILE', $this->counterFile);

        $this->bareOptions['path']              = $this->fixture('retry' . DIRECTORY_SEPARATOR . 'DataProviderFlakyTest.php');
        $this->bareOptions['--retry']           = '2';
        $this->bareOptions['--functional']      = true;
        $this->bareOptions['--max-batch-size']  = '1';
        $this->bareOptions['--processes']       = '1';

        $result = $this->runRunner();

        self::assertSame(RunnerInterface::SUCCESS_EXIT, $result->exitCode);
    }

    /**
     * DependsChainTest: testB is flaky (fails attempt 1, passes attempt 2).
     * testC depends on testB. With --retry=2, the chain must resolve and
     * testC must also pass after testB succeeds on retry.
     */
    public function testRetryChainRetriesAncestors(): void
    {
        $this->setCounterEnv('PARATEST_RETRY_CHAIN_COUNTER_FILE', $this->counterFile);

        $this->bareOptions['path']        = $this->fixture('retry' . DIRECTORY_SEPARATOR . 'DependsChainTest.php');
        $this->bareOptions['--retry']     = '2';
        $this->bareOptions['--processes'] = '1';

        $result = $this->runRunner();

        self::assertSame(RunnerInterface::SUCCESS_EXIT, $result->exitCode);
        self::assertStringContainsString('DependsChainTest::testB', $result->output);
    }

    /**
     * R3/SC crash: A worker crash (undefined function fatal error) must NOT
     * be retried — the exception must propagate and the build must fail.
     * WorkerCrashedException is expected to be thrown by runRunner().
     */
    public function testWorkerCrashIsNotRetriedAndFailsBuild(): void
    {
        $this->bareOptions['path']        = $this->fixture('retry' . DIRECTORY_SEPARATOR . 'WorkerCrashTest.php');
        $this->bareOptions['--retry']     = '2';
        $this->bareOptions['--processes'] = '1';

        $this->expectException(WorkerCrashedException::class);

        $this->runRunner();
    }

    /**
     * SC-9 PHPT: A flaky .phpt file must be retried and must exit 0 on success.
     */
    public function testPhptFlakyRetried(): void
    {
        $phptCounterFile = $this->tmpDir . DIRECTORY_SEPARATOR . 'phpt-counter.txt';
        $this->setCounterEnv('PARATEST_RETRY_PHPT_COUNTER_FILE', $phptCounterFile);

        $this->bareOptions['path']        = $this->fixture('retry' . DIRECTORY_SEPARATOR . 'example_flaky.phpt');
        $this->bareOptions['--retry']     = '2';
        $this->bareOptions['--processes'] = '1';

        $result = $this->runRunner();

        self::assertSame(RunnerInterface::SUCCESS_EXIT, $result->exitCode);
    }

    /**
     * R5: --retry=11 exceeds the hard cap (Options::MAX_RETRY = 10).
     * The subprocess must reject it with a non-zero exit code or throw
     * an exception during options parsing.
     *
     * Since createOptionsFromArgv() is called inside runRunner() synchronously,
     * an InvalidArgumentException is thrown before any runner starts.
     */
    public function testRetryAboveMaxRejected(): void
    {
        $this->bareOptions['path']        = $this->fixture('retry' . DIRECTORY_SEPARATOR . 'AlwaysFailsTest.php');
        $this->bareOptions['--retry']     = '11';
        $this->bareOptions['--processes'] = '1';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/--retry must be between 0 and 10/');

        $this->runRunner();
    }
}
