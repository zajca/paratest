<?php

declare(strict_types=1);

namespace ParaTest\WrapperRunner;

/**
 * Value object returned by {@see RetryOrchestrator::orchestrate()}.
 *
 * Carries every attempt's outcome (for coverage union + JUnit cross-attempt merge)
 * and the list of tests that failed at least once then passed in the final attempt
 * (for the R4 flaky summary and H2 result-cache rewrite).
 *
 * @internal
 *
 * @immutable
 */
final readonly class RetryResult
{
    /**
     * @param list<AttemptOutcome> $allAttempts Attempts in execution order (1..N).
     * @param list<string>         $flakyTests  `"Class::method"` identifiers; deduped, stable order.
     */
    public function __construct(
        public array $allAttempts,
        public array $flakyTests,
    ) {
    }
}
