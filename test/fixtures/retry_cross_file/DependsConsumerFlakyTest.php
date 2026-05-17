<?php

declare(strict_types=1);

namespace ParaTest\Tests\fixtures\retry_cross_file;

use PHPUnit\Framework\Attributes\DependsExternal;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function getenv;
use function intval;

/** @internal */
final class DependsConsumerFlakyTest extends TestCase
{
    #[DependsExternal(DependsProducerTest::class, 'testProducer')]
    public function testConsumer(string $value): void
    {
        self::assertSame('producer-value', $value);

        $counterFile = (string) getenv('PARATEST_RETRY_CROSS_FILE_COUNTER_FILE');

        $count = 0;
        if ($counterFile !== '' && @file_get_contents($counterFile) !== false) {
            $count = intval(file_get_contents($counterFile));
        }

        $count++;
        if ($counterFile !== '') {
            file_put_contents($counterFile, (string) $count);
        }

        self::assertGreaterThan(1, $count, 'Consumer passes only on second or later call.');
    }
}
