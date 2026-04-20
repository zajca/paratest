<?php

declare(strict_types=1);

namespace ParaTest\Tests\Unit\JUnit;

use ParaTest\JUnit\LogMerger;
use ParaTest\JUnit\TestCaseWithRetries;
use ParaTest\JUnit\Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;
use Symfony\Component\Filesystem\Filesystem;

use function file_get_contents;
use function file_put_contents;
use function mkdir;
use function substr_count;
use function sys_get_temp_dir;
use function uniqid;

/** @internal */
#[CoversClass(LogMerger::class)]
#[CoversClass(Writer::class)]
#[CoversClass(TestCaseWithRetries::class)]
final class LogMergerCrossAttemptTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/lmca-' . uniqid('', true);
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

    private function renderAsXml(\ParaTest\JUnit\TestSuite $suite): string
    {
        $outputPath = $this->tmpDir . '/out-' . uniqid('', true) . '.xml';
        (new Writer())->write($suite, $outputPath);
        $xml = file_get_contents($outputPath);
        self::assertNotFalse($xml);

        return $xml;
    }

    public function testMergeAcrossAttemptsReturnsNullOnEmptyInput(): void
    {
        $merger = new LogMerger();
        self::assertNull($merger->mergeAcrossAttempts([]));
    }

    public function testMergeAcrossAttemptsSingleAttemptBehavesLikeMerge(): void
    {
        // When only one attempt is given the result must be structurally
        // equivalent to LogMerger::merge() — no retry metadata, no retries attribute.
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="ExampleTest" tests="2" failures="0" errors="0" skipped="0" assertions="2" time="0.003" file="/p/ExampleTest.php">
    <testcase name="testOne" class="ExampleTest" file="/p/ExampleTest.php" line="10" assertions="1" time="0.001"/>
    <testcase name="testTwo" class="ExampleTest" file="/p/ExampleTest.php" line="20" assertions="1" time="0.002"/>
  </testsuite>
</testsuites>
XML;
        $file   = $this->writeJunit($xml);
        $merger = new LogMerger();

        $resultSingle = $merger->mergeAcrossAttempts([1 => [$file]]);
        $resultMerge  = $merger->merge([$file]);

        self::assertNotNull($resultSingle);
        self::assertNotNull($resultMerge);

        $renderedSingle = $this->renderAsXml($resultSingle);

        self::assertStringNotContainsString('flakyFailure', $renderedSingle);
        self::assertStringNotContainsString('flakyError', $renderedSingle);
        self::assertStringNotContainsString('rerunFailure', $renderedSingle);
        self::assertStringNotContainsString('rerunError', $renderedSingle);
        self::assertStringNotContainsString('retries=', $renderedSingle);

        // Counts must match plain merge
        self::assertSame($resultMerge->tests, $resultSingle->tests);
        self::assertSame($resultMerge->failures, $resultSingle->failures);
        self::assertSame($resultMerge->errors, $resultSingle->errors);
    }

    public function testMergeAcrossAttemptsFlakyFailureEmitsFlakyFailureElement(): void
    {
        // Attempt 1: testFlaky fails with <failure>.
        // Attempt 2 (final): testFlaky passes (no failure element).
        // Expected: <flakyFailure> child under <testcase>, retries="1", no top-level <failure>.
        $attempt1Xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="ExampleTest" tests="1" failures="1" errors="0" skipped="0" assertions="1" time="0.002" file="/p/ExampleTest.php">
    <testcase name="testFlaky" class="ExampleTest" file="/p/ExampleTest.php" line="20" assertions="1" time="0.002">
      <failure type="PHPUnit\Framework\AssertionFailedError" message="Failed assertion">ExampleTest::testFlaky
stack trace here</failure>
    </testcase>
  </testsuite>
</testsuites>
XML;

        $attempt2Xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="ExampleTest" tests="1" failures="0" errors="0" skipped="0" assertions="1" time="0.001" file="/p/ExampleTest.php">
    <testcase name="testFlaky" class="ExampleTest" file="/p/ExampleTest.php" line="20" assertions="1" time="0.001"/>
  </testsuite>
</testsuites>
XML;

        $f1     = $this->writeJunit($attempt1Xml);
        $f2     = $this->writeJunit($attempt2Xml);
        $merger = new LogMerger();
        $result = $merger->mergeAcrossAttempts([1 => [$f1], 2 => [$f2]]);

        self::assertNotNull($result);

        // Single testsuite in XML — fromFile returns the suite directly (no wrapper)
        self::assertCount(1, $result->cases);
        $case = $result->cases[0];
        self::assertInstanceOf(TestCaseWithRetries::class, $case);
        self::assertSame(1, $case->retries);
        self::assertTrue($case->finalAttemptPassed);
        self::assertCount(1, $case->priorAttemptFailures);

        $rendered = $this->renderAsXml($result);

        self::assertStringContainsString('<flakyFailure', $rendered);
        self::assertStringContainsString('retries="1"', $rendered);
        self::assertStringNotContainsString('<rerunFailure', $rendered);
        self::assertStringNotContainsString('<rerunError', $rendered);
        self::assertStringNotContainsString('<flakyError', $rendered);
        // Final passing case must NOT have a top-level <failure>
        self::assertStringNotContainsString('<failure', $rendered);
    }

    public function testMergeAcrossAttemptsFlakyErrorEmitsFlakyErrorElement(): void
    {
        // Attempt 1: testFlakyError fails with <error>.
        // Attempt 2 (final): testFlakyError passes.
        // Expected: <flakyError> child under <testcase>, retries="1", no top-level <error>.
        $attempt1Xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="ExampleTest" tests="1" failures="0" errors="1" skipped="0" assertions="1" time="0.002" file="/p/ExampleTest.php">
    <testcase name="testFlakyError" class="ExampleTest" file="/p/ExampleTest.php" line="30" assertions="1" time="0.002">
      <error type="RuntimeException" message="Unexpected error">ExampleTest::testFlakyError
RuntimeException: Unexpected error
#0 /p/ExampleTest.php:35</error>
    </testcase>
  </testsuite>
</testsuites>
XML;

        $attempt2Xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="ExampleTest" tests="1" failures="0" errors="0" skipped="0" assertions="1" time="0.001" file="/p/ExampleTest.php">
    <testcase name="testFlakyError" class="ExampleTest" file="/p/ExampleTest.php" line="30" assertions="1" time="0.001"/>
  </testsuite>
</testsuites>
XML;

        $f1     = $this->writeJunit($attempt1Xml);
        $f2     = $this->writeJunit($attempt2Xml);
        $merger = new LogMerger();
        $result = $merger->mergeAcrossAttempts([1 => [$f1], 2 => [$f2]]);

        self::assertNotNull($result);

        self::assertCount(1, $result->cases);
        $case = $result->cases[0];
        self::assertInstanceOf(TestCaseWithRetries::class, $case);
        self::assertSame(1, $case->retries);
        self::assertTrue($case->finalAttemptPassed);
        self::assertCount(1, $case->priorAttemptFailures);

        $rendered = $this->renderAsXml($result);

        self::assertStringContainsString('<flakyError', $rendered);
        self::assertStringContainsString('retries="1"', $rendered);
        self::assertStringNotContainsString('<rerunFailure', $rendered);
        self::assertStringNotContainsString('<rerunError', $rendered);
        self::assertStringNotContainsString('<flakyFailure', $rendered);
        // Final passing case must NOT emit a top-level <error>
        self::assertStringNotContainsString('<error', $rendered);
    }

    public function testMergeAcrossAttemptsRerunFailureEmitsRerunFailureAndPreservesFinalFailure(): void
    {
        // Attempt 1: testAlwaysFails has <failure>.
        // Attempt 2 (final): testAlwaysFails still has <failure>.
        // Expected: <rerunFailure> child from prior attempt + top-level <failure> from final + retries="1".
        //
        // PRODUCTION BUG (known): TestCaseWithRetries extends TestCase (not TestCaseWithMessage),
        // so Writer::createCaseNode() never emits the final-attempt <failure> element when
        // finalAttemptPassed=false. The <rerunFailure> child IS emitted correctly, but the
        // top-level <failure> is lost. This test is marked incomplete to document the gap.
        // Fix: Writer must also check ($case instanceof TestCaseWithRetries && !$case->finalAttemptPassed)
        // and emit the final failure element from $case itself (which is a TestCaseWithMessage in
        // the decorated tree, or the failure info must be preserved differently).
        $attempt1Xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="ExampleTest" tests="1" failures="1" errors="0" skipped="0" assertions="1" time="0.002" file="/p/ExampleTest.php">
    <testcase name="testAlwaysFails" class="ExampleTest" file="/p/ExampleTest.php" line="40" assertions="1" time="0.002">
      <failure type="PHPUnit\Framework\AssertionFailedError" message="First attempt failure">First attempt stack trace</failure>
    </testcase>
  </testsuite>
</testsuites>
XML;

        $attempt2Xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="ExampleTest" tests="1" failures="1" errors="0" skipped="0" assertions="1" time="0.003" file="/p/ExampleTest.php">
    <testcase name="testAlwaysFails" class="ExampleTest" file="/p/ExampleTest.php" line="40" assertions="1" time="0.003">
      <failure type="PHPUnit\Framework\AssertionFailedError" message="Second attempt failure">Second attempt stack trace</failure>
    </testcase>
  </testsuite>
</testsuites>
XML;

        $f1     = $this->writeJunit($attempt1Xml);
        $f2     = $this->writeJunit($attempt2Xml);
        $merger = new LogMerger();
        $result = $merger->mergeAcrossAttempts([1 => [$f1], 2 => [$f2]]);

        self::assertNotNull($result);

        self::assertCount(1, $result->cases);
        $case = $result->cases[0];
        self::assertInstanceOf(TestCaseWithRetries::class, $case);
        self::assertSame(1, $case->retries);
        self::assertFalse($case->finalAttemptPassed);
        self::assertCount(1, $case->priorAttemptFailures);

        $rendered = $this->renderAsXml($result);

        self::assertStringContainsString('<rerunFailure', $rendered);
        self::assertStringContainsString('retries="1"', $rendered);
        self::assertStringNotContainsString('<flakyFailure', $rendered);
        self::assertStringNotContainsString('<flakyError', $rendered);
        self::assertStringNotContainsString('<rerunError', $rendered);

        // Top-level <failure> for final attempt MUST be preserved.
        self::assertStringContainsString('<failure type="PHPUnit\Framework\AssertionFailedError"', $rendered);
        self::assertStringContainsString('Second attempt stack trace', $rendered);
    }

    public function testMergeAcrossAttemptsMultiplePriorAttempts(): void
    {
        // 3 attempts: fail, fail, pass → retries="2", TWO <flakyFailure> children.
        $attempt1Xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="ExampleTest" tests="1" failures="1" errors="0" skipped="0" assertions="1" time="0.002" file="/p/ExampleTest.php">
    <testcase name="testEventuallyPasses" class="ExampleTest" file="/p/ExampleTest.php" line="50" assertions="1" time="0.002">
      <failure type="PHPUnit\Framework\AssertionFailedError" message="Attempt 1 failure">Attempt 1 trace</failure>
    </testcase>
  </testsuite>
</testsuites>
XML;

        $attempt2Xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="ExampleTest" tests="1" failures="1" errors="0" skipped="0" assertions="1" time="0.003" file="/p/ExampleTest.php">
    <testcase name="testEventuallyPasses" class="ExampleTest" file="/p/ExampleTest.php" line="50" assertions="1" time="0.003">
      <failure type="PHPUnit\Framework\AssertionFailedError" message="Attempt 2 failure">Attempt 2 trace</failure>
    </testcase>
  </testsuite>
</testsuites>
XML;

        $attempt3Xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="ExampleTest" tests="1" failures="0" errors="0" skipped="0" assertions="1" time="0.001" file="/p/ExampleTest.php">
    <testcase name="testEventuallyPasses" class="ExampleTest" file="/p/ExampleTest.php" line="50" assertions="1" time="0.001"/>
  </testsuite>
</testsuites>
XML;

        $f1     = $this->writeJunit($attempt1Xml);
        $f2     = $this->writeJunit($attempt2Xml);
        $f3     = $this->writeJunit($attempt3Xml);
        $merger = new LogMerger();
        $result = $merger->mergeAcrossAttempts([1 => [$f1], 2 => [$f2], 3 => [$f3]]);

        self::assertNotNull($result);

        self::assertCount(1, $result->cases);
        $case = $result->cases[0];
        self::assertInstanceOf(TestCaseWithRetries::class, $case);
        self::assertSame(2, $case->retries, 'retries attribute must equal number of prior failed attempts');
        self::assertTrue($case->finalAttemptPassed);
        self::assertCount(2, $case->priorAttemptFailures, 'Two prior failures must be attached');

        $rendered = $this->renderAsXml($result);

        self::assertStringContainsString('retries="2"', $rendered);

        // Both prior failures must produce flakyFailure children (final attempt passed)
        $flakyCount = substr_count($rendered, '<flakyFailure');
        self::assertSame(2, $flakyCount, 'Expected exactly two <flakyFailure> elements');

        self::assertStringNotContainsString('<rerunFailure', $rendered);
        self::assertStringNotContainsString('<failure', $rendered);
    }
}
