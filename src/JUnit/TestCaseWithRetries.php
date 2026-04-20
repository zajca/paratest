<?php

declare(strict_types=1);

namespace ParaTest\JUnit;

/**
 * @internal
 *
 * @immutable
 */
final readonly class TestCaseWithRetries extends TestCase
{
    /** @param list<TestCaseWithMessage> $priorAttemptFailures */
    public function __construct(
        string $name,
        string $class,
        string $file,
        int $line,
        int $assertions,
        float $time,
        public int $retries,
        public array $priorAttemptFailures,
        public bool $finalAttemptPassed
    ) {
        parent::__construct($name, $class, $file, $line, $assertions, $time);
    }
}
