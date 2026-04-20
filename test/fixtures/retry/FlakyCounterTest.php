<?php

declare(strict_types=1);

namespace ParaTest\Tests\fixtures\retry;

use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function getenv;
use function intval;
use function sys_get_temp_dir;

/**
 * A test that fails on the first call and passes on subsequent calls.
 *
 * Uses a counter file whose path is injected via PARATEST_RETRY_COUNTER_FILE env var.
 * If the env var is not set, uses a path in sys_get_temp_dir() — but callers should
 * always set the env var to avoid cross-test-run interference.
 *
 * @internal
 */
final class FlakyCounterTest extends TestCase
{
    public function testFlakyPassesOnSecondAttempt(): void
    {
        $counterFile = (string) getenv('PARATEST_RETRY_COUNTER_FILE');
        if ($counterFile === '') {
            $counterFile = sys_get_temp_dir() . '/paratest_flaky_counter_default.txt';
        }

        $count = 0;
        if (@file_get_contents($counterFile) !== false) {
            $count = intval(file_get_contents($counterFile));
        }

        $count++;
        file_put_contents($counterFile, (string) $count);

        self::assertGreaterThan(1, $count, 'Test passes only on second or later call.');
    }
}
