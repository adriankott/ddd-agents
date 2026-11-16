<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\DecisionFixtures;

use DDD\Domain\Base\Entities\ObjectSet;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The cases of a decision fixture ({@see AgentDecisionFixtureCase}), in the order the fixture lists them.
 *
 * A plain object of one command run; no container service.
 *
 * @property AgentDecisionFixtureCase[] $elements
 * @method AgentDecisionFixtureCase[] getElements()
 * @method AgentDecisionFixtureCase|null first()
 * @phpstan-consistent-constructor
 */
#[Exclude]
class AgentDecisionFixtureCases extends ObjectSet
{
    /**
     * The cases whose id is `$caseId`, in their order; every case when `$caseId` is null.
     *
     * @param string|null $caseId
     * @return static
     */
    public function buildWithId(?string $caseId): static
    {
        if ($caseId === null) {
            return $this;
        }
        $agentDecisionFixtureCases = static::newInstance();
        foreach ($this->getElements() as $agentDecisionFixtureCase) {
            if ($agentDecisionFixtureCase->getText('id') === $caseId) {
                $agentDecisionFixtureCases->add($agentDecisionFixtureCase);
            }
        }
        return $agentDecisionFixtureCases;
    }
}
