<?php

declare(strict_types=1);

namespace ParaTest\Tests\fixtures\retry;

use PHPUnit\Framework\TestCase;

/**
 * Two tests: testPasses always passes, testCrashes kills the worker process with a fatal error.
 * Used to verify that paratest retries a test whose worker crashed unexpectedly.
 *
 * @internal
 */
final class WorkerCrashTest extends TestCase
{
    public function testPasses(): void
    {
        self::assertTrue(true);
    }

    public function testCrashes(): void
    {
        // Call an undefined function to trigger a PHP fatal error (E_ERROR).
        // PHPUnit cannot catch this — the worker process terminates immediately.
        /** @phpstan-ignore-next-line */
        \paratest_undefined_function_to_crash_the_worker();
    }
}
