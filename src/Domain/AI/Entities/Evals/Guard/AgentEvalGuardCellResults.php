<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\Guard;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The probes of a guard probe run ({@see AgentEvalGuardCellResult}), in the order they were made or merged; two equal
 * probes both stay.
 *
 * @property AgentEvalGuardCellResult[] $elements
 * @method AgentEvalGuardCellResult[] getElements()
 * @method AgentEvalGuardCellResult|null first()
 * @phpstan-consistent-constructor
 */
class AgentEvalGuardCellResults extends ObjectSet
{
    /**
     * The probes a child process printed: the `cells` of the JSON document that starts at the first `{` of its
     * standard output; none when there is no such document.
     *
     * @param string $childStandardOutput
     * @return static
     */
    public static function fromChildOutput(string $childStandardOutput): static
    {
        $agentEvalGuardCellResults = static::newInstance();
        $documentStart = strpos($childStandardOutput, '{');
        $decodedDocument = $documentStart !== false ? json_decode(substr($childStandardOutput, $documentStart), true) : null;
        if (!is_array($decodedDocument) || !is_array($decodedDocument['cells'] ?? null)) {
            return $agentEvalGuardCellResults;
        }
        foreach ($decodedDocument['cells'] as $storedRow) {
            if (is_array($storedRow)) {
                $agentEvalGuardCellResult = AgentEvalGuardCellResult::fromStoredRow($storedRow);
                $agentEvalGuardCellResults->add($agentEvalGuardCellResult);
            }
        }
        return $agentEvalGuardCellResults;
    }

    /**
     * Adds every probe of `$agentEvalGuardCellResults` after the ones held.
     *
     * @param AgentEvalGuardCellResults $agentEvalGuardCellResults
     * @return void
     */
    public function addResultsOf(AgentEvalGuardCellResults $agentEvalGuardCellResults): void
    {
        foreach ($agentEvalGuardCellResults->getElements() as $agentEvalGuardCellResult) {
            $this->add($agentEvalGuardCellResult);
        }
    }

    /**
     * The report of the run on the model `$modelName`: the figures per guard-prompt variant.
     *
     * @param string $modelName
     * @return AgentEvalGuardReport
     */
    public function buildReport(string $modelName): AgentEvalGuardReport
    {
        return AgentEvalGuardReport::forCellResults($this, $modelName);
    }

    /**
     * The cell rows of the stored document of the run ({@see AgentEvalGuardCellResult::toArray()} each).
     *
     * @return array[]
     */
    public function toArray(): array
    {
        $storedRows = [];
        foreach ($this->getElements() as $agentEvalGuardCellResult) {
            $storedRows[] = $agentEvalGuardCellResult->toArray();
        }
        return $storedRows;
    }
}
