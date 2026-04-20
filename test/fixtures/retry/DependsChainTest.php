<?php

declare(strict_types=1);

namespace ParaTest\Tests\fixtures\retry;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function getenv;
use function intval;

/**
 * Chain of three tests: testA passes always, testB is flaky (passes on second+ attempt),
 * testC depends on testB. Used to verify that @depends chain is properly handled on retry.
 *
 * Uses a counter file whose path is injected via PARATEST_RETRY_CHAIN_COUNTER_FILE env var.
 *
 * @internal
 */
final class DependsChainTest extends TestCase
{
    public function testA(): string
    {
        self::assertTrue(true);

        return 'A';
    }

    #[Depends('testA')]
    public function testB(string $a): string
    {
        self::assertSame('A', $a);

        $counterFile = (string) getenv('PARATEST_RETRY_CHAIN_COUNTER_FILE');

        $count = 0;
        if ($counterFile !== '' && @file_get_contents($counterFile) !== false) {
            $count = intval(file_get_contents($counterFile));
        }

        $count++;
        if ($counterFile !== '') {
            file_put_contents($counterFile, (string) $count);
        }

        self::assertGreaterThan(1, $count, 'testB passes only on second or later call.');

        return 'B';
    }

    #[Depends('testB')]
    public function testC(string $b): void
    {
        self::assertSame('B', $b);
    }
}
