<?php

declare(strict_types=1);

namespace ParaTest\Tests\fixtures\retry;

use PHPUnit\Framework\TestCase;

/** @internal */
final class AlwaysFailsTest extends TestCase
{
    public function testAlwaysFails(): void
    {
        self::assertTrue(false, 'This test always fails — used to verify exhausted-retry path.');
    }
}
