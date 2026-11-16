<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The figures per suite of one run of a suite record ({@see AgentEvalFiguresOfSuite}), in the order the suites first
 * occur in the rows.
 *
 * @property AgentEvalFiguresOfSuite[] $elements
 * @method AgentEvalFiguresOfSuite[] getElements()
 * @method AgentEvalFiguresOfSuite|null first()
 * @method AgentEvalFiguresOfSuite|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AgentEvalFiguresBySuite extends ObjectSet
{
    /** The figures per suite of the rows `$agentEvalCaseRows`, each with the performance over its suite's rows. */
    public static function fromCaseRows(AgentEvalCaseRows $agentEvalCaseRows): static
    {
        $agentEvalFiguresBySuite = static::newInstance();
        foreach ($agentEvalCaseRows->getElements() as $agentEvalCaseRow) {
            $agentEvalFiguresBySuite->countRowOfSuite($agentEvalCaseRow);
        }
        foreach ($agentEvalFiguresBySuite->getElements() as $agentEvalFiguresOfSuite) {
            $agentEvalFiguresOfSuite->performanceRollUp = AgentEvalPerformanceRollUp::fromCaseRows($agentEvalCaseRows->buildRowsOfSuite($agentEvalFiguresOfSuite->suite));
        }
        return $agentEvalFiguresBySuite;
    }

    /** Counts the row `$agentEvalCaseRow` on the figures of its suite, adding the suite at its first row. */
    public function countRowOfSuite(AgentEvalCaseRow $agentEvalCaseRow): void
    {
        $heldFigures = $this->getByUniqueKey(AgentEvalFiguresOfSuite::uniqueKeyForSuite($agentEvalCaseRow->getSuite()));
        if ($heldFigures instanceof AgentEvalFiguresOfSuite) {
            $heldFigures->countRow($agentEvalCaseRow);
            return;
        }
        $agentEvalFiguresOfSuite = AgentEvalFiguresOfSuite::forSuite($agentEvalCaseRow->getSuite());
        $agentEvalFiguresOfSuite->countRow($agentEvalCaseRow);
        $this->add($agentEvalFiguresOfSuite);
    }

    /**
     * The figures keyed by suite, as a suite record stores them under `runs.<run>.bySuite`: part of the stored document
     * of `<suite run>/_suite.json`.
     *
     * @return array suite → `{total, notApplicable, passed, performance}`
     */
    public function toArraysBySuite(): array
    {
        $figuresBySuite = [];
        foreach ($this->getElements() as $agentEvalFiguresOfSuite) {
            $figuresBySuite[$agentEvalFiguresOfSuite->suite] = $agentEvalFiguresOfSuite->toArray();
        }
        return $figuresBySuite;
    }
}
