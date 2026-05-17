<?php

declare(strict_types=1);

namespace ParaTest\Tests\fixtures\retry_cross_file;

use PHPUnit\Framework\TestCase;

/** @internal */
final class DependsProducerTest extends TestCase
{
    public function testProducer(): string
    {
        self::assertTrue(true);

        return 'producer-value';
    }
}
