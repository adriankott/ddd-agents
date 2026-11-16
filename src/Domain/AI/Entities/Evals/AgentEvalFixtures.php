<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The fixture files of an evaluation suite ({@see AgentEvalFixture}), in the order they were added, one per path.
 *
 * @property AgentEvalFixture[] $elements
 * @method AgentEvalFixture[] getElements()
 * @method AgentEvalFixture|null first()
 * @method AgentEvalFixture|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AgentEvalFixtures extends ObjectSet
{
    /**
     * The fixtures whose file name (without `.json`) is one of `$fixtureNames`, in their order.
     *
     * @param string[] $fixtureNames
     * @return static
     */
    public function buildFixturesNamed(array $fixtureNames): static
    {
        $namedFixtures = static::newInstance();
        foreach ($this->getElements() as $agentEvalFixture) {
            if (in_array($agentEvalFixture->getName(), $fixtureNames, true)) {
                $namedFixtures->add($agentEvalFixture);
            }
        }
        return $namedFixtures;
    }
}
