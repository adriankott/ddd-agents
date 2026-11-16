<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The repetitions per case of a tools evaluation ({@see AgentEvalCaseRepetition}), in the order the cases first
 * occur in the rows.
 *
 * @property AgentEvalCaseRepetition[] $elements
 * @method AgentEvalCaseRepetition[] getElements()
 * @method AgentEvalCaseRepetition|null first()
 * @method AgentEvalCaseRepetition|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AgentEvalCaseRepetitions extends ObjectSet
{
    /** Counts the run of the row `$agentEvalCaseRow` on its case, adding the case at its first run. */
    public function countRunOfRow(AgentEvalCaseRow $agentEvalCaseRow): void
    {
        $heldRepetition = $this->getByUniqueKey(AgentEvalCaseRepetition::uniqueKeyForCaseId($agentEvalCaseRow->getCaseId()));
        if ($heldRepetition instanceof AgentEvalCaseRepetition) {
            $heldRepetition->countRun($agentEvalCaseRow);
            return;
        }
        $agentEvalCaseRepetition = AgentEvalCaseRepetition::forCase($agentEvalCaseRow->getCaseId());
        $agentEvalCaseRepetition->countRun($agentEvalCaseRow);
        $this->add($agentEvalCaseRepetition);
    }

    /**
     * The list a tools summary stores under `metrics.repetitions`: part of the stored document of `<run>.json` /
     * `<run>/_summary.json`.
     *
     * @return array the list of `{case, runs, passed, errors, passRate}` entries
     */
    public function toArrays(): array
    {
        return array_map(static fn (AgentEvalCaseRepetition $agentEvalCaseRepetition): array => $agentEvalCaseRepetition->toArray(), $this->getElements());
    }
}
