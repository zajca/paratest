<?php

declare(strict_types=1);

namespace ParaTest\Tests\fixtures\retry;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function getenv;
use function intval;

/**
 * Data-provider test where only row #1 is flaky (passes on second+ attempt).
 * Rows #0 and #2 always pass immediately.
 *
 * Uses a counter file whose path is injected via PARATEST_RETRY_DP_COUNTER_FILE env var.
 *
 * @internal
 */
final class DataProviderFlakyTest extends TestCase
{
    /** @return iterable<array{0: int}> */
    public static function rowsProvider(): iterable
    {
        yield [0];
        yield [1]; // flaky row — fails on first attempt, passes on second+
        yield [2];
    }

    #[DataProvider('rowsProvider')]
    public function testRow(int $row): void
    {
        if ($row === 1) {
            $counterFile = (string) getenv('PARATEST_RETRY_DP_COUNTER_FILE');

            $count = 0;
            if ($counterFile !== '' && @file_get_contents($counterFile) !== false) {
                $count = intval(file_get_contents($counterFile));
            }

            $count++;
            if ($counterFile !== '') {
                file_put_contents($counterFile, (string) $count);
            }

            self::assertGreaterThan(1, $count, 'Row #1 passes only on second or later call.');

            return;
        }

        self::assertGreaterThanOrEqual(0, $row);
    }
}
