<?php

declare(strict_types=1);

namespace ParaTest\Tests\fixtures\retry;

use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function getenv;
use function intval;

/** @internal */
final class MixedSkippedAndFlakyTest extends TestCase
{
    public function testSkipped(): void
    {
        self::markTestSkipped('This skip is not retried.');
    }

    public function testFlakyPassesOnSecondAttempt(): void
    {
        $counterFile = (string) getenv('PARATEST_RETRY_MIXED_COUNTER_FILE');

        $count = 0;
        if ($counterFile !== '' && @file_get_contents($counterFile) !== false) {
            $count = intval(file_get_contents($counterFile));
        }

        $count++;
        if ($counterFile !== '') {
            file_put_contents($counterFile, (string) $count);
        }

        self::assertGreaterThan(1, $count, 'Flaky test passes only on second or later call.');
    }
}
