<?php

declare(strict_types=1);

namespace ParaTest\Tests\Unit\WrapperRunner;

use ParaTest\JUnit\FailedTestExtractor;
use ParaTest\JUnit\MessageType;
use ParaTest\Tests\TestBase;
use ParaTest\WrapperRunner\AttemptOutcome;
use ParaTest\WrapperRunner\RetryOrchestrator;
use ParaTest\WrapperRunner\SuiteLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\TestRunner\TestResult\TestResult;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Console\Output\BufferedOutput;

use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function scandir;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

/** @internal */
#[CoversClass(RetryOrchestrator::class)]
final class RetryOrchestratorTest extends TestBase
{
    /** @var non-empty-string */
    private string $junitDir;

    protected function setUpTest(): void
    {
        $this->junitDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rot-' . uniqid('', true);
        mkdir($this->junitDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->junitDir);

        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    /**
     * Builds a minimal Options instance with the given retry count.
     * We reuse TestBase::createOptionsFromArgv() which handles all required defaults.
     */
    private function makeOrchestrator(int $retry): RetryOrchestrator
    {
        $options = $this->createOptionsFromArgv([
            '--retry'    => (string) $retry,
            '--retry-on' => 'failure,error',
        ]);

        // SuiteLoader requires a real configuration; we use a minimal stub via
        // reflection to avoid loading an actual test suite (which would scan the
        // filesystem and take time). We only need testCount to be non-zero for
        // the output message in the orchestrator.
        $loaderReflection = new ReflectionClass(SuiteLoader::class);
        $suiteLoader      = $loaderReflection->newInstanceWithoutConstructor();

        $testCountProp = $loaderReflection->getProperty('testCount');
        $testCountProp->setValue($suiteLoader, 10);

        $testsProp = $loaderReflection->getProperty('tests');
        $testsProp->setValue($suiteLoader, []);

        $dependsMapProp = $loaderReflection->getProperty('dependsMap');
        $dependsMapProp->setValue($suiteLoader, []);

        $extractor = new FailedTestExtractor([MessageType::failure, MessageType::error], [], false);

        return new RetryOrchestrator($options, new BufferedOutput(), $suiteLoader, $extractor);
    }

    /**
     * Creates a minimal TestResult with zero events (all empty).
     */
    private function makeTestResult(): TestResult
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

    /**
     * Writes JUnit XML to a file in the temp directory and returns its path.
     *
     * @return non-empty-string
     */
    private function writeJunit(string $filename, string $xml): string
    {
        $path = $this->junitDir . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($path, $xml);

        return $path;
    }

    /**
     * Builds JUnit XML with no failures (clean pass).
     */
    private function cleanJunit(string $suiteName = 'Suite'): string
    {
        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<testsuites>'
            . '<testsuite name="%s" tests="1" assertions="1" failures="0" errors="0" skipped="0" time="0.001">'
            . '<testcase name="testPass" class="Cls" file="/p/test.php" line="5" assertions="1" time="0.001"/>'
            . '</testsuite>'
            . '</testsuites>',
            $suiteName,
        );
    }

    /**
     * Builds JUnit XML with a single failure on the given class::method.
     */
    private function failingJunit(string $class, string $method, string $suiteName = 'Suite'): string
    {
        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<testsuites>'
            . '<testsuite name="%s" tests="1" assertions="1" failures="1" errors="0" skipped="0" time="0.001">'
            . '<testcase name="%s" class="%s" file="/p/test.php" line="10" assertions="1" time="0.001">'
            . '<failure type="PHPUnit\Framework\AssertionFailedError">Failed</failure>'
            . '</testcase>'
            . '</testsuite>'
            . '</testsuites>',
            $suiteName,
            $method,
            $class,
        );
    }

    private function skippedJunit(string $class, string $method, string $suiteName = 'Suite'): string
    {
        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<testsuites>'
            . '<testsuite name="%s" tests="1" assertions="0" failures="0" errors="0" skipped="1" time="0.001">'
            . '<testcase name="%s" class="%s" file="/p/test.php" line="10" assertions="0" time="0.001">'
            . '<skipped/>'
            . '</testcase>'
            . '</testsuite>'
            . '</testsuites>',
            $suiteName,
            $method,
            $class,
        );
    }

    /**
     * Builds an AttemptOutcome for the given attempt number.
     *
     * @param list<SplFileInfo> $junitFiles
     */
    private function makeOutcome(int $attemptNumber, array $junitFiles, int $exitcode = 0): AttemptOutcome
    {
        return new AttemptOutcome(
            $attemptNumber,
            [],
            $junitFiles,
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
            $exitcode,
            $this->makeTestResult(),
        );
    }

    #[Test]
    public function testOrchestrateReturnsSingleAttemptOnCleanPass(): void
    {
        $orch = $this->makeOrchestrator(retry: 2);

        $callCount = 0;
        $cb        = function (int $attempt, array $pending) use (&$callCount): AttemptOutcome {
            ++$callCount;
            $path = $this->writeJunit(sprintf('attempt-%d.xml', $attempt), $this->cleanJunit());

            return $this->makeOutcome($attempt, [new SplFileInfo($path)]);
        };

        $result = $orch->orchestrate(['/p/test.php'], $cb);

        self::assertSame(1, $callCount);
        self::assertCount(1, $result->allAttempts);
        self::assertSame([], $result->flakyTests);
        self::assertSame([], $orch->getFlakyTestList());
    }

    #[Test]
    public function testOrchestrateRetriesOnceThenStopsWhenPassed(): void
    {
        $orch = $this->makeOrchestrator(retry: 2);

        $callCount = 0;
        $cb        = function (int $attempt, array $pending) use (&$callCount): AttemptOutcome {
            ++$callCount;
            if ($attempt === 1) {
                $path = $this->writeJunit('attempt-1.xml', $this->failingJunit('Cls', 'testFlaky'));

                return $this->makeOutcome($attempt, [new SplFileInfo($path)], exitcode: 1);
            }

            // Attempt 2: all pass
            $path = $this->writeJunit(sprintf('attempt-%d-clean.xml', $attempt), $this->cleanJunit());

            return $this->makeOutcome($attempt, [new SplFileInfo($path)]);
        };

        $result = $orch->orchestrate(['/p/test.php'], $cb);

        self::assertSame(2, $callCount);
        self::assertCount(2, $result->allAttempts);
        self::assertSame(['Cls::testFlaky'], $result->flakyTests);
        self::assertSame(['Cls::testFlaky'], $orch->getFlakyTestList());
    }

    #[Test]
    public function testOrchestrateExhaustsRetries(): void
    {
        $orch = $this->makeOrchestrator(retry: 2);

        $callCount = 0;
        $cb        = function (int $attempt, array $pending) use (&$callCount): AttemptOutcome {
            ++$callCount;
            $path = $this->writeJunit(sprintf('attempt-%d-fail.xml', $attempt), $this->failingJunit('Cls', 'testAlwaysFails'));

            return $this->makeOutcome($attempt, [new SplFileInfo($path)], exitcode: 1);
        };

        $result = $orch->orchestrate(['/p/test.php'], $cb);

        // retry=2 means 3 total attempts (initial + 2 retries)
        self::assertSame(3, $callCount);
        self::assertCount(3, $result->allAttempts);
        // Test always failed in every attempt including the final one → not flaky
        self::assertSame([], $result->flakyTests);
    }

    #[Test]
    public function testOrchestrateEarlyStopsOnEmptyPending(): void
    {
        $orch = $this->makeOrchestrator(retry: 5);

        $callCount = 0;
        $cb        = function (int $attempt, array $pending) use (&$callCount): AttemptOutcome {
            ++$callCount;
            // Always return a clean (no failures) JUnit — extractor finds nothing to retry
            $path = $this->writeJunit(sprintf('early-stop-%d.xml', $attempt), $this->cleanJunit());

            return $this->makeOutcome($attempt, [new SplFileInfo($path)]);
        };

        $result = $orch->orchestrate(['/p/test.php'], $cb);

        // Should stop after first attempt since extractor returns no pending work
        self::assertSame(1, $callCount);
        self::assertCount(1, $result->allAttempts);
    }

    #[Test]
    public function testOrchestrateArchivesArtifactsBetweenAttempts(): void
    {
        $orch = $this->makeOrchestrator(retry: 2);

        $attempt1JunitPath = $this->writeJunit('attempt-1-for-archive.xml', $this->failingJunit('Cls', 'testArchive'));

        $cb = function (int $attempt, array $pending) use ($attempt1JunitPath): AttemptOutcome {
            if ($attempt === 1) {
                return $this->makeOutcome($attempt, [new SplFileInfo($attempt1JunitPath)], exitcode: 1);
            }

            $path = $this->writeJunit(sprintf('attempt-%d-archive-clean.xml', $attempt), $this->cleanJunit());

            return $this->makeOutcome($attempt, [new SplFileInfo($path)]);
        };

        $result = $orch->orchestrate(['/p/test.php'], $cb);

        self::assertCount(2, $result->allAttempts);

        // Attempt 1 should have been archived: its junitFiles should now point
        // inside {tmpDir}/attempt-1/ not the original junitDir.
        $archivedAttempt = $result->allAttempts[0];
        self::assertCount(1, $archivedAttempt->junitFiles);

        $archivedFile = $archivedAttempt->junitFiles[0];
        self::assertStringContainsString(
            DIRECTORY_SEPARATOR . 'attempt-1' . DIRECTORY_SEPARATOR,
            $archivedFile->getPathname(),
            'Attempt 1 JUnit file should have been moved to the attempt-1 archive subdirectory',
        );
        self::assertFileExists($archivedFile->getPathname());
    }

    #[Test]
    public function testOrchestrateEmptyInitialPendingRunsSingleAttempt(): void
    {
        // The orchestrator always runs at least one attempt — the caller passes the
        // initial pending list but the loop does not guard on it being empty before
        // invoking the runner. With no failures extracted, no further retries occur.
        $orch = $this->makeOrchestrator(retry: 2);

        $callCount = 0;
        $cb        = function (int $attempt, array $pending) use (&$callCount): AttemptOutcome {
            ++$callCount;

            // Return a clean (empty) outcome: no junit files → extractor finds nothing
            return $this->makeOutcome($attempt, []);
        };

        $result = $orch->orchestrate([], $cb);

        self::assertSame(1, $callCount, 'Exactly one attempt should run even with an empty initial pending list');
        self::assertCount(1, $result->allAttempts);
        self::assertSame([], $result->flakyTests);
    }

    #[Test]
    public function testFlakyListDeduplicated(): void
    {
        // Test fails in attempts 1 and 2 (both prior), passes in attempt 3 (final)
        $orch = $this->makeOrchestrator(retry: 2);

        $cb = function (int $attempt, array $pending): AttemptOutcome {
            if ($attempt < 3) {
                $path = $this->writeJunit(
                    sprintf('dedup-fail-%d.xml', $attempt),
                    $this->failingJunit('DedupCls', 'testFlaky'),
                );

                return $this->makeOutcome($attempt, [new SplFileInfo($path)], exitcode: 1);
            }

            // Attempt 3: passes
            $path = $this->writeJunit('dedup-pass.xml', $this->cleanJunit());

            return $this->makeOutcome($attempt, [new SplFileInfo($path)]);
        };

        $result = $orch->orchestrate(['/p/test.php'], $cb);

        // Exactly one entry — deduplication must have occurred.
        self::assertSame(['DedupCls::testFlaky'], $result->flakyTests);
    }

    #[Test]
    public function testFlakyListExcludesStablyFailing(): void
    {
        $orch = $this->makeOrchestrator(retry: 2);

        $cb = function (int $attempt, array $pending): AttemptOutcome {
            // Always fails — including the final attempt
            $path = $this->writeJunit(
                sprintf('stable-fail-%d.xml', $attempt),
                $this->failingJunit('StableCls', 'testAlwaysFails'),
            );

            return $this->makeOutcome($attempt, [new SplFileInfo($path)], exitcode: 1);
        };

        $result = $orch->orchestrate(['/p/test.php'], $cb);

        // 3 total attempts (retry=2)
        self::assertCount(3, $result->allAttempts);
        // The test failed in ALL attempts including the final one → it is stably failing, NOT flaky
        self::assertSame([], $result->flakyTests);
    }

    #[Test]
    public function testFlakyListExcludesFinalSkippedTest(): void
    {
        $orch = $this->makeOrchestrator(retry: 2);

        $cb = function (int $attempt, array $pending): AttemptOutcome {
            if ($attempt === 1) {
                $path = $this->writeJunit(
                    'final-skipped-fail.xml',
                    $this->failingJunit('SkippedCls', 'testSkippedAfterRetry'),
                );

                return $this->makeOutcome($attempt, [new SplFileInfo($path)], exitcode: 1);
            }

            $path = $this->writeJunit(
                'final-skipped.xml',
                $this->skippedJunit('SkippedCls', 'testSkippedAfterRetry'),
            );

            return $this->makeOutcome($attempt, [new SplFileInfo($path)]);
        };

        $result = $orch->orchestrate(['/p/test.php'], $cb);

        self::assertCount(2, $result->allAttempts);
        self::assertSame([], $result->flakyTests);
    }

    #[Test]
    public function testOrchestrateWithRetryZeroRunsSingleAttempt(): void
    {
        // When retry=0, maxAttempts=1 and no retry loop should ever run
        $orch = $this->makeOrchestrator(retry: 0);

        $callCount = 0;
        $cb        = function (int $attempt, array $pending) use (&$callCount): AttemptOutcome {
            ++$callCount;
            $path = $this->writeJunit('retry0.xml', $this->cleanJunit());

            return $this->makeOutcome($attempt, [new SplFileInfo($path)]);
        };

        $result = $orch->orchestrate(['/p/test.php'], $cb);

        self::assertSame(1, $callCount);
        self::assertCount(1, $result->allAttempts);
        self::assertSame([], $result->flakyTests);
    }

    #[Test]
    public function testFlakyListContainsMultipleDistinctTests(): void
    {
        // Two different tests both fail in attempt 1 and pass in attempt 2
        $orch = $this->makeOrchestrator(retry: 1);

        $cb = function (int $attempt, array $pending): AttemptOutcome {
            if ($attempt === 1) {
                // Build a JUnit with two failing tests
                $xml  = '<?xml version="1.0" encoding="UTF-8"?>'
                    . '<testsuites>'
                    . '<testsuite name="Suite" tests="2" assertions="2" failures="2" errors="0" skipped="0" time="0.001">'
                    . '<testcase name="testFirst" class="MultiCls" file="/p/test.php" line="10" assertions="1" time="0.001">'
                    . '<failure type="PHPUnit\Framework\AssertionFailedError">Failed</failure>'
                    . '</testcase>'
                    . '<testcase name="testSecond" class="MultiCls" file="/p/test.php" line="20" assertions="1" time="0.001">'
                    . '<failure type="PHPUnit\Framework\AssertionFailedError">Failed</failure>'
                    . '</testcase>'
                    . '</testsuite>'
                    . '</testsuites>';
                $path = $this->writeJunit('multi-fail-1.xml', $xml);

                return $this->makeOutcome($attempt, [new SplFileInfo($path)], exitcode: 1);
            }

            // Attempt 2: both pass
            $path = $this->writeJunit('multi-pass.xml', $this->cleanJunit());

            return $this->makeOutcome($attempt, [new SplFileInfo($path)]);
        };

        $result = $orch->orchestrate(['/p/test.php'], $cb);

        self::assertCount(2, $result->flakyTests);
        self::assertContains('MultiCls::testFirst', $result->flakyTests);
        self::assertContains('MultiCls::testSecond', $result->flakyTests);
    }
}
