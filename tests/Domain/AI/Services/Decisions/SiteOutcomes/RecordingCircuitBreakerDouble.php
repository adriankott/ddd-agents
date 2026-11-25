<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Services\Decisions\AgentDecisionModelCircuitBreakerService;

/**
 * The shared decision-model circuit breaker, kept in the test: its open state is the row's, every trip and every close
 * REQUEST (a site asking to close it, open or not) is a world line.
 */
class RecordingCircuitBreakerDouble extends AgentDecisionModelCircuitBreakerService
{
    public bool $open = false;

    public function __construct(public DecisionSiteWorldLog $worldLog)
    {
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function trip(int $seconds, string $reason): void
    {
        $this->worldLog->record('breaker trip');
    }

    public function close(): void
    {
        $this->worldLog->record('breaker close');
    }
}
