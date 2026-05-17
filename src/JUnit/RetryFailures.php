<?php

declare(strict_types=1);

namespace ParaTest\JUnit;

/**
 * @internal
 *
 * @immutable
 */
final readonly class RetryFailures
{
    /**
     * @param list<non-empty-string> $workItems
     * @param list<non-empty-string> $testNames
     */
    public function __construct(
        public array $workItems,
        public array $testNames,
    ) {
    }
}
