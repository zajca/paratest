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
        // Kill the worker with an immediate exit — PHPUnit has no chance to
        // record the result, so paratest sees a missing test_result file and
        // raises WorkerCrashedException (which R3 requires NOT to be retried).
        exit(139);
    }
}
