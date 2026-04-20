<?php

declare(strict_types=1);

namespace ParaTest\Tests\Unit;

use InvalidArgumentException;
use ParaTest\JUnit\MessageType;
use ParaTest\Options;
use ParaTest\Tests\TestBase;
use ParaTest\WrapperRunner\ShardDistribution;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

use function mt_rand;
use function sprintf;
use function uniqid;

use const DIRECTORY_SEPARATOR;
use const PHP_INT_MAX;
use const PHP_INT_MIN;

/** @internal */
#[CoversClass(Options::class)]
final class OptionsTest extends TestBase
{
    private Options $options;
    /** @var array<non-empty-string, non-empty-string|list<non-empty-string>>  */
    private array $unfiltered;

    public function setUpTest(): void
    {
        $this->unfiltered = [
            '--processes' => '5',
            '--group' => ['group1'],
            '--exclude-group' => ['group2'],
            '--bootstrap' => '/path/to/bootstrap',
            'path' => '/path/to/tests',
        ];

        $this->options = $this->createOptionsFromArgv($this->unfiltered);
    }

    public function testFilteredOptionsShouldContainExtraneousOptions(): void
    {
        self::assertEquals(['group1'], $this->options->phpunitOptions['group']);
        self::assertEquals('/path/to/bootstrap', $this->options->phpunitOptions['bootstrap']);
    }

    public function testAutoProcessesMode(): void
    {
        $options = $this->createOptionsFromArgv(['--processes' => 'auto']);

        self::assertEquals(Options::getNumberOfCPUCores(), $options->processes);
    }

    public function testAutoProcessesModeWithMaxProcesses(): void
    {
        $options = $this->createOptionsFromArgv(['--processes' => 'auto', '--max-processes' => '0']);

        self::assertSame(0, $options->processes);
    }

    public function testPassthru(): void
    {
        $argv = ['--passthru-php' => "'-d' 'zend_extension=xdebug.so'"];

        $options = $this->createOptionsFromArgv($argv);

        $expectedPassthruPhp = ['-d', 'zend_extension=xdebug.so'];

        self::assertSame($expectedPassthruPhp, $options->passthruPhp);
    }

    public function testDefaultOptions(): void
    {
        $options = $this->createOptionsFromArgv([], __DIR__);

        self::assertSame(__DIR__, $options->cwd);
        self::assertNotEmpty($options->phpunitOptions);
        self::assertSame(0, $options->maxBatchSize);
        self::assertFalse($options->noTestTokens);
        self::assertSame(['-d', 'zend.assertions=1'], $options->passthruPhp);
        self::assertStringContainsString('phpunit', $options->phpunit);
        self::assertSame(PROCESSES_FOR_TESTS, $options->processes);
        self::assertSame('WrapperRunner', $options->runner);
        self::assertSame($this->tmpDir, $options->tmpDir);
        self::assertFalse($options->verbose);
    }

    public function testProvidedOptions(): void
    {
        $argv = [
            '--max-batch-size' => 5,
            '--no-test-tokens' => true,
            '--passthru-php' => '-d a=1',
            '--processes' => '999',
            '--runner' => 'MYRUNNER',
            '--tmp-dir' => ($tmpDir = uniqid($this->tmpDir . DIRECTORY_SEPARATOR . 't')),
            '--verbose' => true,
            'path' => 'PATH',
        ];

        $options = $this->createOptionsFromArgv($argv, __DIR__);

        self::assertSame(5, $options->maxBatchSize);
        self::assertTrue($options->noTestTokens);
        self::assertSame(['-d', 'a=1'], $options->passthruPhp);
        self::assertSame('PATH', $options->configuration->cliArguments()[0]);
        self::assertSame(999, $options->processes);
        self::assertSame('MYRUNNER', $options->runner);
        self::assertSame($tmpDir, $options->tmpDir);
        self::assertTrue($options->verbose);
    }

    public function testFillEnvWithTokens(): void
    {
        $options = $this->createOptionsFromArgv(['--no-test-tokens' => false]);

        $inc = mt_rand(10, 99);
        $env = $options->fillEnvWithTokens($inc);

        self::assertSame(1, $env['PARATEST']);
        self::assertArrayHasKey(Options::ENV_KEY_TOKEN, $env);
        self::assertSame($inc, $env[Options::ENV_KEY_TOKEN]);
        self::assertArrayHasKey(Options::ENV_KEY_UNIQUE_TOKEN, $env);
        self::assertStringContainsString($inc . '_', $env[Options::ENV_KEY_UNIQUE_TOKEN]);

        $options = $this->createOptionsFromArgv(['--no-test-tokens' => true]);

        $inc = mt_rand(10, 99);
        $env = $options->fillEnvWithTokens($inc);

        self::assertSame(1, $env['PARATEST']);
        self::assertArrayNotHasKey(Options::ENV_KEY_TOKEN, $env);
        self::assertArrayNotHasKey(Options::ENV_KEY_UNIQUE_TOKEN, $env);
    }

    public function testNeedsTeamcityGetsActivatedBothByLogTeamcityAndTeamcityFlags(): void
    {
        $options = $this->createOptionsFromArgv(['--teamcity' => true], __DIR__);

        self::assertTrue($options->needsTeamcity);

        $options = $this->createOptionsFromArgv(['--log-teamcity' => 'LOG-TEAMCITY'], __DIR__);

        self::assertTrue($options->needsTeamcity);
    }

    public function testNeedsTestdoxGetsActivatedBothByTestdoxOutputOrATestdoxLogFile(): void
    {
        $options = $this->createOptionsFromArgv(['--testdox' => true], __DIR__);

        self::assertTrue($options->needsTestdox);

        $options = $this->createOptionsFromArgv(['--testdox-text' => 'LOG-TESTDOX-TEXT'], __DIR__);

        self::assertTrue($options->needsTestdox);

        $options = $this->createOptionsFromArgv(['--testdox-html' => 'LOG-TESTDOX-HTML'], __DIR__);

        self::assertTrue($options->needsTestdox);
    }

    public function testShardOptionsDefaultValues(): void
    {
        $options = $this->createOptionsFromArgv([], __DIR__);

        self::assertSame(0, $options->currentShard);
        self::assertSame(0, $options->totalShards);
        self::assertFalse($options->hasShard());
    }

    public function testValidShardOption(): void
    {
        $options = $this->createOptionsFromArgv(['--shard' => '2/5'], __DIR__);

        self::assertSame(2, $options->currentShard);
        self::assertSame(5, $options->totalShards);
        self::assertTrue($options->hasShard());
    }

    public function testValidShardOptionLargeNumbers(): void
    {
        $options = $this->createOptionsFromArgv(['--shard' => '42/100'], __DIR__);

        self::assertSame(42, $options->currentShard);
        self::assertSame(100, $options->totalShards);
        self::assertTrue($options->hasShard());
    }

    #[DataProvider('provideInvalidShardOptionFormats')]
    public function testInvalidShardOptionFormats(string $shard): void
    {
        self::assertNotEmpty($shard);
        $this->expectException(InvalidArgumentException::class);

        $this->createOptionsFromArgv(['--shard' => $shard], __DIR__);
    }

    /** @return iterable<list<non-empty-string>> */
    public static function provideInvalidShardOptionFormats(): iterable
    {
        yield ['invalid'];
        yield ['1'];
        yield ['1/'];
        yield ['/5'];
        yield ['1/5/extra'];
        yield ['a/b'];
        yield ['1/0'];
        yield ['0/1'];
        yield ['0/0'];
        yield ['6/5'];
        yield ['1/1'];
    }

    public function testShardOptionNotProvided(): void
    {
        $options = $this->createOptionsFromArgv(['--verbose' => true], __DIR__);

        self::assertSame(0, $options->currentShard);
        self::assertSame(0, $options->totalShards);
        self::assertFalse($options->hasShard());
    }

    public function testShardTestDistributionDefaultsToSequential(): void
    {
        $options = $this->createOptionsFromArgv([], __DIR__);

        self::assertSame(ShardDistribution::Sequential, $options->shardDistribution);
    }

    public function testShardTestDistributionSequentialExplicit(): void
    {
        $options = $this->createOptionsFromArgv(['--shard-test-distribution' => ShardDistribution::Sequential->value], __DIR__);

        self::assertSame(ShardDistribution::Sequential, $options->shardDistribution);
    }

    public function testShardTestDistributionRoundRobin(): void
    {
        $options = $this->createOptionsFromArgv(['--shard-test-distribution' => ShardDistribution::RoundRobin->value], __DIR__);

        self::assertSame(ShardDistribution::RoundRobin, $options->shardDistribution);
    }

    public function testShardTestDistributionInvalidValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid shard-test-distribution value: invalid');

        $this->createOptionsFromArgv(['--shard-test-distribution' => 'invalid'], __DIR__);
    }

    public function testShardTestDistributionRandom(): void
    {
        $options = $this->createOptionsFromArgv([
            '--shard-test-distribution' => ShardDistribution::Random->value,
            '--shard-test-distribution-seed' => '42',
        ], __DIR__);

        self::assertSame(ShardDistribution::Random, $options->shardDistribution);
        self::assertSame(42, $options->shardDistributionSeed);
    }

    public function testShardTestDistributionRandomDefaultsToZeroSeed(): void
    {
        $options = $this->createOptionsFromArgv([
            '--shard-test-distribution' => ShardDistribution::Random->value,
        ], __DIR__);

        self::assertSame(ShardDistribution::Random, $options->shardDistribution);
        self::assertSame(0, $options->shardDistributionSeed);
    }

    public function testShardTestDistributionSeedOnlyWithRandom(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Shard test distribution seed can only be used with random distribution');

        $this->createOptionsFromArgv([
            '--shard-test-distribution' => ShardDistribution::Sequential->value,
            '--shard-test-distribution-seed' => '42',
        ], __DIR__);
    }

    /** @return iterable<list<non-empty-string>> */
    public static function provideInvalidShardTestDistributionSeedValues(): iterable
    {
        yield ['abc'];
        yield ['3.14'];
    }

    /** @param non-empty-string $seed */
    #[DataProvider('provideInvalidShardTestDistributionSeedValues')]
    public function testShardTestDistributionSeedMustBeInteger(string $seed): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('Shard test distribution seed must be an integer between %s and %s, value %s provided', PHP_INT_MIN, PHP_INT_MAX, $seed));

        $this->createOptionsFromArgv([
            '--shard-test-distribution' => ShardDistribution::Random->value,
            '--shard-test-distribution-seed' => $seed,
        ], __DIR__);
    }

    public function testShardTestDistributionSeedAcceptsNegativeValues(): void
    {
        $options = $this->createOptionsFromArgv([
            '--shard-test-distribution' => ShardDistribution::Random->value,
            '--shard-test-distribution-seed' => '-42',
        ], __DIR__);

        self::assertSame(-42, $options->shardDistributionSeed);
    }

    public function testShardTestDistributionSeedDefaultsToZero(): void
    {
        $options = $this->createOptionsFromArgv([], __DIR__);

        self::assertSame(0, $options->shardDistributionSeed);
    }

    public function testRetryDefaultIsZero(): void
    {
        $options = $this->createOptionsFromArgv([], __DIR__);

        self::assertSame(0, $options->retry);
    }

    /** @return iterable<string, list<int>> */
    public static function provideValidRetryValues(): iterable
    {
        yield 'zero' => [0];
        yield 'ten'  => [10];
    }

    #[DataProvider('provideValidRetryValues')]
    public function testRetryAcceptsValidRange(int $value): void
    {
        $options = $this->createOptionsFromArgv(['--retry' => (string) $value], __DIR__);

        self::assertSame($value, $options->retry);
    }

    public function testRetryRejectsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--retry must be between 0 and 10');

        $this->createOptionsFromArgv(['--retry' => '-1'], __DIR__);
    }

    public function testRetryRejectsAboveMaximum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--retry must be between 0 and 10');

        $this->createOptionsFromArgv(['--retry' => '11'], __DIR__);
    }

    public function testRetryOnDefaultIsFailureAndError(): void
    {
        $options = $this->createOptionsFromArgv([], __DIR__);

        self::assertContains(MessageType::failure, $options->retryOn);
        self::assertContains(MessageType::error, $options->retryOn);
        self::assertCount(2, $options->retryOn);
    }

    public function testRetryOnAcceptsSkipped(): void
    {
        $options = $this->createOptionsFromArgv(['--retry-on' => 'skipped'], __DIR__);

        self::assertSame([MessageType::skipped], $options->retryOn);
    }

    public function testRetryOnRejectsCrash(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved');

        $this->createOptionsFromArgv(['--retry-on' => 'crash'], __DIR__);
    }

    public function testRetryOnRejectsUnknownToken(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid --retry-on value');

        $this->createOptionsFromArgv(['--retry-on' => 'banana'], __DIR__);
    }

    public function testRetryOnRejectsEmptyWhenRetryPositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--retry-on must not be empty when --retry > 0');

        $this->createOptionsFromArgv(['--retry' => '1', '--retry-on' => ''], __DIR__);
    }

    public function testJunitRetryMetadataDefaultsToFalse(): void
    {
        $options = $this->createOptionsFromArgv([], __DIR__);

        self::assertFalse($options->junitRetryMetadata);
    }
}
