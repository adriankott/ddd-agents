<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The suites a suite run can plan ({@see AgentEvalSuiteDefinition}), in the order of the configuration, one per suite
 * name.
 *
 * @property AgentEvalSuiteDefinition[] $elements
 * @method AgentEvalSuiteDefinition[] getElements()
 * @method AgentEvalSuiteDefinition|null first()
 * @method AgentEvalSuiteDefinition|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AgentEvalSuiteDefinitions extends ObjectSet
{
    /** The suite named `$suiteName`; null when there is none. */
    public function getBySuiteName(string $suiteName): ?AgentEvalSuiteDefinition
    {
        return $this->getByUniqueKey(AgentEvalSuiteDefinition::uniqueKeyOfSuiteName($suiteName));
    }

    /**
     * Every suite name, in order.
     *
     * @return string[]
     */
    public function getSuiteNames(): array
    {
        return array_map(static fn (AgentEvalSuiteDefinition $agentEvalSuiteDefinition): string => $agentEvalSuiteDefinition->suiteName, $this->getElements());
    }

    /**
     * The names of the suites a run without a suite list takes, in order.
     *
     * @return string[]
     */
    public function getDefaultSuiteNames(): array
    {
        return array_values(array_map(
            static fn (AgentEvalSuiteDefinition $agentEvalSuiteDefinition): string => $agentEvalSuiteDefinition->suiteName,
            array_filter($this->getElements(), static fn (AgentEvalSuiteDefinition $agentEvalSuiteDefinition): bool => $agentEvalSuiteDefinition->plannedByDefault)
        ));
    }
}
