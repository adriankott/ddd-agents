<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The owner turns of one eviction trajectory ({@see AgentEvalEvictionTurn}), in the order they ran, and what the
 * trajectory as a whole shows: whether eviction fired, the peak of the carried load and the figures summed over the
 * turns. Each turn is held under the key of its own object: two turns with the same values both stay.
 *
 * @property AgentEvalEvictionTurn[] $elements
 * @method AgentEvalEvictionTurn[] getElements()
 * @method AgentEvalEvictionTurn|null first()
 * @phpstan-consistent-constructor
 */
class AgentEvalEvictionTurns extends ObjectSet
{
    /** Whether a capability was evicted in any turn: the footprint layer reclaimed load on real data. */
    public function hasEviction(): bool
    {
        foreach ($this->getElements() as $agentEvalEvictionTurn) {
            if ($agentEvalEvictionTurn->hasEvicted()) {
                return true;
            }
        }
        return false;
    }

    /** The highest resident tokens of every loaded capability after a turn; 0 without turns. */
    public function getPeakTotalResidentTokens(): int
    {
        $peakTotalResidentTokens = 0;
        foreach ($this->getElements() as $agentEvalEvictionTurn) {
            $peakTotalResidentTokens = max($peakTotalResidentTokens, $agentEvalEvictionTurn->getTotalResidentTokens());
        }
        return $peakTotalResidentTokens;
    }

    /**
     * The figures of every turn added up (the seconds as the sum of each turn's rounded seconds, not rounded again).
     *
     * @return AgentEvalEvictionTurnFigures
     */
    public function buildTotalFigures(): AgentEvalEvictionTurnFigures
    {
        $totalFigures = AgentEvalEvictionTurnFigures::newInstance();
        foreach ($this->getElements() as $agentEvalEvictionTurn) {
            $turnFigures = $agentEvalEvictionTurn->figures;
            $totalFigures->modelCalls += $turnFigures->modelCalls;
            $totalFigures->loadToolHops += $turnFigures->loadToolHops;
            $totalFigures->refusedReads += $turnFigures->refusedReads;
            $totalFigures->inputTokens += $turnFigures->inputTokens;
            $totalFigures->cachedInputTokens += $turnFigures->cachedInputTokens;
            $totalFigures->seconds += $turnFigures->seconds;
        }
        return $totalFigures;
    }

    /**
     * The turns as the rows of the deprecated reader {@see AgentEvalRunOutcome::getEvictionTurns()}, one
     * {@see AgentEvalEvictionTurn::toArray()} per turn.
     *
     * @return array[]
     */
    public function toArray(): array
    {
        $turnRows = [];
        foreach ($this->getElements() as $agentEvalEvictionTurn) {
            $turnRows[] = $agentEvalEvictionTurn->toArray();
        }
        return $turnRows;
    }
}
