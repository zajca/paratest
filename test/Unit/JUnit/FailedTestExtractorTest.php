<?php

declare(strict_types=1);

namespace ParaTest\Tests\Unit\JUnit;

use ParaTest\JUnit\FailedTestExtractor;
use ParaTest\JUnit\MessageType;
use ParaTest\JUnit\RetryFailures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;
use Symfony\Component\Filesystem\Filesystem;

use function file_put_contents;
use function mkdir;
use function preg_quote;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;

/** @internal */
#[CoversClass(FailedTestExtractor::class)]
#[CoversClass(RetryFailures::class)]
final class FailedTestExtractorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/fte-' . uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tmpDir);
    }

    private function writeJunit(string $xml): SplFileInfo
    {
        $path = $this->tmpDir . '/junit-' . uniqid('', true) . '.xml';
        file_put_contents($path, $xml);

        return new SplFileInfo($path);
    }

    public function testExtractFailuresReturnsEmptyForAllPassing(): void
    {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="ExampleTest" tests="1" failures="0" errors="0" skipped="0" assertions="1" time="0.001" file="/path/ExampleTest.php">
    <testcase name="testPass" class="ExampleTest" file="/path/ExampleTest.php" line="10" assertions="1" time="0.001"/>
  </testsuite>
</testsuites>
XML;
        $file      = $this->writeJunit($xml);
        $extractor = new FailedTestExtractor([MessageType::failure, MessageType::error], [], false);

        self::assertSame([], $extractor->extractFailures([$file]));
    }

    public function testExtractFailuresEmitsBareFilePathInNonFunctionalMode(): void
    {
        $testFile = '/path/to/ExampleTest.php';
        $xml      = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="ExampleTest" tests="1" failures="1" errors="0" skipped="0" assertions="1" time="0.001" file="{$testFile}">
    <testcase name="testFoo" class="ExampleTest" file="{$testFile}" line="10" assertions="1" time="0.001">
      <failure type="PHPUnit\Framework\AssertionFailedError">Expected true but got false</failure>
    </testcase>
  </testsuite>
</testsuites>
XML;
        $file      = $this->writeJunit($xml);
        $extractor = new FailedTestExtractor([MessageType::failure, MessageType::error], [], false);

        self::assertSame([$testFile], $extractor->extractFailures([$file]));
    }

    public function testExtractFailuresEmitsPcreWorkItemInFunctionalMode(): void
    {
        $testFile = '/path/to/ExampleTest.php';
        $testName = 'testFoo';
        $xml      = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="ExampleTest" tests="1" failures="1" errors="0" skipped="0" assertions="1" time="0.001" file="{$testFile}">
    <testcase name="{$testName}" class="ExampleTest" file="{$testFile}" line="10" assertions="1" time="0.001">
      <failure type="PHPUnit\Framework\AssertionFailedError">Assertion failed</failure>
    </testcase>
  </testsuite>
</testsuites>
XML;
        $file      = $this->writeJunit($xml);
        $extractor = new FailedTestExtractor([MessageType::failure, MessageType::error], [], true);

        $expected = [sprintf("%s\0/%s\$/", $testFile, preg_quote($testName, '/'))];
        self::assertSame($expected, $extractor->extractFailures([$file]));
    }

    public function testExtractFailuresFunctionalModeWithDataset(): void
    {
        $testFile = '/path/to/ExampleTest.php';
        // JUnit name includes dataset suffix exactly as PHPUnit writes it
        $testName = 'testFoo with data set #0';
        $xml      = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="ExampleTest" tests="1" failures="1" errors="0" skipped="0" assertions="1" time="0.001" file="{$testFile}">
    <testcase name="{$testName}" class="ExampleTest" file="{$testFile}" line="10" assertions="1" time="0.001">
      <failure type="PHPUnit\Framework\AssertionFailedError">Data set 0 failed</failure>
    </testcase>
  </testsuite>
</testsuites>
XML;
        $file      = $this->writeJunit($xml);
        $extractor = new FailedTestExtractor([MessageType::failure, MessageType::error], [], true);

        $result = $extractor->extractFailures([$file]);
        self::assertCount(1, $result);

        // Work item must start with the file path followed by null byte
        self::assertStringStartsWith($testFile . "\0", $result[0]);

        // The PCRE portion must match the full name (including dataset suffix)
        $pcre = substr($result[0], strlen($testFile) + 1);
        self::assertSame(1, preg_match($pcre, $testName), "PCRE '{$pcre}' should match '{$testName}'");

        // And must NOT match a different data-set name
        self::assertSame(0, preg_match($pcre, 'testFoo with data set #1'));
    }

    public function testExtractFailuresFiltersByRetryOnError(): void
    {
        $testFile = '/path/to/ExampleTest.php';
        $xml      = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="ExampleTest" tests="2" failures="1" errors="1" skipped="0" assertions="2" time="0.002" file="{$testFile}">
    <testcase name="testFailure" class="ExampleTest" file="{$testFile}" line="10" assertions="1" time="0.001">
      <failure type="PHPUnit\Framework\AssertionFailedError">Assertion failed</failure>
    </testcase>
    <testcase name="testError" class="ExampleTest" file="{$testFile}" line="20" assertions="1" time="0.001">
      <error type="RuntimeException">Some error occurred</error>
    </testcase>
  </testsuite>
</testsuites>
XML;
        $file = $this->writeJunit($xml);

        // retryOn = [error] only — failure must be excluded
        $extractor = new FailedTestExtractor([MessageType::error], [], false);
        $result    = $extractor->extractFailures([$file]);

        // Non-functional mode deduplicates by file; since both are in the same file,
        // and only the error-typed test is eligible, we get exactly one entry.
        self::assertSame([$testFile], $result);
    }

    public function testExtractFailuresIncludesSkippedWhenConfigured(): void
    {
        $testFile = '/path/to/ExampleTest.php';
        $xml      = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="ExampleTest" tests="1" failures="0" errors="0" skipped="1" assertions="0" time="0.001" file="{$testFile}">
    <testcase name="testSkipped" class="ExampleTest" file="{$testFile}" line="10" assertions="0" time="0.001">
      <skipped/>
    </testcase>
  </testsuite>
</testsuites>
XML;
        $file = $this->writeJunit($xml);

        // Without skipped in retryOn — should NOT emit
        $extractor = new FailedTestExtractor([MessageType::failure, MessageType::error], [], false);
        self::assertSame([], $extractor->extractFailures([$file]));

        // With skipped in retryOn — should emit
        $extractor = new FailedTestExtractor([MessageType::skipped], [], false);
        self::assertSame([$testFile], $extractor->extractFailures([$file]));
    }

    public function testExtractFailuresDeduplicatesSameFile(): void
    {
        $testFile = '/path/to/ExampleTest.php';
        $xml      = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="ExampleTest" tests="2" failures="2" errors="0" skipped="0" assertions="2" time="0.002" file="{$testFile}">
    <testcase name="testOne" class="ExampleTest" file="{$testFile}" line="10" assertions="1" time="0.001">
      <failure type="PHPUnit\Framework\AssertionFailedError">First failure</failure>
    </testcase>
    <testcase name="testTwo" class="ExampleTest" file="{$testFile}" line="20" assertions="1" time="0.001">
      <failure type="PHPUnit\Framework\AssertionFailedError">Second failure</failure>
    </testcase>
  </testsuite>
</testsuites>
XML;
        $file      = $this->writeJunit($xml);
        $extractor = new FailedTestExtractor([MessageType::failure], [], false);

        $result = $extractor->extractFailures([$file]);

        // Two failures in the same file in non-functional mode must produce exactly one item
        self::assertCount(1, $result);
        self::assertSame([$testFile], $result);
    }

    public function testExtractFailureDetailsIncludesDisplayNamesForRetriedTests(): void
    {
        $testFile = '/path/to/ExampleTest.php';
        $xml      = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="ExampleTest" tests="2" failures="2" errors="0" skipped="0" assertions="2" time="0.002" file="{$testFile}">
    <testcase name="testOne" class="ExampleTest" file="{$testFile}" line="10" assertions="1" time="0.001">
      <failure type="PHPUnit\Framework\AssertionFailedError">First failure</failure>
    </testcase>
    <testcase name="testTwo" class="ExampleTest" file="{$testFile}" line="20" assertions="1" time="0.001">
      <failure type="PHPUnit\Framework\AssertionFailedError">Second failure</failure>
    </testcase>
  </testsuite>
</testsuites>
XML;
        $file      = $this->writeJunit($xml);
        $extractor = new FailedTestExtractor([MessageType::failure], [], false);

        $result = $extractor->extractFailureDetails([$file]);

        self::assertSame([$testFile], $result->workItems);
        self::assertSame([
            'ExampleTest::testOne',
            'ExampleTest::testTwo',
        ], $result->testNames);
    }

    public function testExtractFailuresIncludesAncestorsViaDependsMap(): void
    {
        $testFile = '/path/to/ExampleTest.php';
        // Only C fails — but C depends on B which depends on A
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="ExampleTest" tests="3" failures="1" errors="0" skipped="0" assertions="3" time="0.003" file="{$testFile}">
    <testcase name="testA" class="Cls" file="{$testFile}" line="10" assertions="1" time="0.001"/>
    <testcase name="testB" class="Cls" file="{$testFile}" line="20" assertions="1" time="0.001"/>
    <testcase name="testC" class="Cls" file="{$testFile}" line="30" assertions="1" time="0.001">
      <failure type="PHPUnit\Framework\AssertionFailedError">C failed</failure>
    </testcase>
  </testsuite>
</testsuites>
XML;
        $file = $this->writeJunit($xml);

        // dependsMap: C depends transitively on B and A
        $dependsMap = ['Cls::testC' => ['Cls::testB', 'Cls::testA']];
        $extractor  = new FailedTestExtractor([MessageType::failure], $dependsMap, true);

        $result = $extractor->extractFailures([$file]);

        // In functional mode each entry is "$file\0/$pcre$/"
        // We expect three work items: for testC, testB, testA
        self::assertCount(3, $result);

        $expectedNames = ['testC', 'testB', 'testA'];
        foreach ($expectedNames as $name) {
            $expectedItem = sprintf("%s\0/%s\$/", $testFile, preg_quote($name, '/'));
            self::assertContains($expectedItem, $result, "Expected work item for {$name} not found");
        }
    }

    public function testExtractFailuresDetectsPhptByClassAttribute(): void
    {
        $phptFile = '/path/to/example.phpt';
        $xml      = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="PHPT Tests" tests="1" failures="1" errors="0" skipped="0" assertions="1" time="0.001">
    <testcase name="{$phptFile}" class="PHPUnit\Runner\Phpt\TestCase" file="{$phptFile}" line="1" assertions="1" time="0.001">
      <failure type="PHPUnit\Framework\AssertionFailedError">PHPT test failed</failure>
    </testcase>
  </testsuite>
</testsuites>
XML;
        $file = $this->writeJunit($xml);

        // Functional mode: PHPT must still emit bare file path, never a PCRE work item
        $extractor = new FailedTestExtractor([MessageType::failure, MessageType::error], [], true);
        $result    = $extractor->extractFailures([$file]);

        self::assertSame([$phptFile], $result);
    }

    public function testExtractFailuresSkipsNullByteInDatasetFunctional(): void
    {
        $testFile = '/path/to/ExampleTest.php';
        // A failing test C depends on an ancestor whose synthesized name contains a null byte.
        // This exercises the null-byte guard in emitWorkItem() via synthesizeAncestorCase().
        // The dependsMap key uses "Class::method" format; the method name has a null byte.
        $ancestorKey = "ExampleTest::testA\0WithNull";
        $xml         = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="ExampleTest" tests="1" failures="1" errors="0" skipped="0" assertions="1" time="0.001" file="{$testFile}">
    <testcase name="testC" class="ExampleTest" file="{$testFile}" line="30" assertions="1" time="0.001">
      <failure type="PHPUnit\Framework\AssertionFailedError">C failed</failure>
    </testcase>
  </testsuite>
</testsuites>
XML;
        $file = $this->writeJunit($xml);

        // dependsMap: testC depends on an ancestor whose name contains a null byte
        $dependsMap = ['ExampleTest::testC' => [$ancestorKey]];
        $extractor  = new FailedTestExtractor([MessageType::failure], $dependsMap, true);

        // FailedTestExtractor emits a diagnostic via error_log() when it skips a null-byte name.
        // Redirect error_log output to a temp file so the strict-output check stays green.
        $logFile        = $this->tmpDir . '/error.log';
        $previousErrLog = ini_set('error_log', $logFile);

        try {
            $result = $extractor->extractFailures([$file]);
        } finally {
            if ($previousErrLog !== false) {
                ini_set('error_log', $previousErrLog);
            } else {
                ini_restore('error_log');
            }
        }

        // Must not throw; the ancestor with the null-byte name must be silently skipped.
        // testC itself is valid and must appear; the ancestor must be absent.
        self::assertCount(1, $result);
        $expectedC = sprintf("%s\0/%s\$/", $testFile, preg_quote('testC', '/'));
        self::assertSame([$expectedC], $result);
    }
}
