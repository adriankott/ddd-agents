<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilitiesEvictionSnapshot;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * One owner turn of an eviction trajectory (several owner turns on one continuing conversation), taken right after the
 * turn: the owner's prompt, the eviction snapshot of the loaded capabilities, what was loaded and what was evicted
 * since the turn before, and the turn's figures. No I/O.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalEvictionTurn extends ValueObject
{
    /** @var string The owner's prompt of the turn */
    public string $prompt = '';

    /** @var LoadedCapabilitiesEvictionSnapshot The eviction snapshot of the loaded capabilities after the turn */
    public LoadedCapabilitiesEvictionSnapshot $snapshot;

    /** @var string[] The capabilities loaded after the turn that were not loaded after the turn before, highest score first */
    public array $newlyLoadedCapabilityNames = [];

    /** @var string[] The capabilities loaded after the turn before that are no longer loaded, in the order of the turn before */
    public array $evictedCapabilityNames = [];

    /** @var AgentEvalEvictionTurnFigures The figures of the turn */
    public AgentEvalEvictionTurnFigures $figures;

    /**
     * The turn whose snapshot is `$snapshot`, compared with the capabilities loaded after the turn before.
     *
     * @param string $prompt
     * @param LoadedCapabilitiesEvictionSnapshot $snapshot
     * @param string[] $capabilityNamesOfTurnBefore the capabilities loaded after the turn before (none for the first turn)
     * @param AgentEvalEvictionTurnFigures $figures
     * @return static
     */
    public static function forTurn(
        string $prompt,
        LoadedCapabilitiesEvictionSnapshot $snapshot,
        array $capabilityNamesOfTurnBefore,
        AgentEvalEvictionTurnFigures $figures
    ): static {
        $agentEvalEvictionTurn = static::newInstance();
        $agentEvalEvictionTurn->prompt = $prompt;
        $agentEvalEvictionTurn->snapshot = $snapshot;
        $capabilityNames = $snapshot->getCapabilityNames();
        $agentEvalEvictionTurn->newlyLoadedCapabilityNames = array_values(array_diff($capabilityNames, $capabilityNamesOfTurnBefore));
        $agentEvalEvictionTurn->evictedCapabilityNames = array_values(array_diff($capabilityNamesOfTurnBefore, $capabilityNames));
        $agentEvalEvictionTurn->figures = $figures;
        return $agentEvalEvictionTurn;
    }

    /** Whether a capability was evicted in this turn. */
    public function hasEvicted(): bool
    {
        return $this->evictedCapabilityNames !== [];
    }

    /** The resident tokens of every capability loaded after the turn. */
    public function getTotalResidentTokens(): int
    {
        return $this->snapshot->totalResidentTokens;
    }

    /**
     * The turn as a row of the deprecated reader {@see AgentEvalRunOutcome::getEvictionTurns()}: `prompt`, `snapshot`
     * ({@see LoadedCapabilitiesEvictionSnapshot::toArray()}), `newlyLoaded`, `evicted`, `metrics`
     * ({@see AgentEvalEvictionTurnFigures::toArray()}), in that order.
     *
     * @return array{prompt: string, snapshot: array<string, mixed>, newlyLoaded: string[], evicted: string[], metrics: array<string, int|float>}
     */
    public function toArray(): array
    {
        return [
            'prompt' => $this->prompt,
            'snapshot' => $this->snapshot->toArray(),
            'newlyLoaded' => $this->newlyLoadedCapabilityNames,
            'evicted' => $this->evictedCapabilityNames,
            'metrics' => $this->figures->toArray(),
        ];
    }
}
