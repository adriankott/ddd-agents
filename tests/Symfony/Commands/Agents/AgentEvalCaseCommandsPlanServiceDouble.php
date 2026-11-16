<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Services\Eval\AgentEvalSuitePlanService;

/**
 * The planning service of the evaluation commands' tests: the configured fixture paths are the test's map.
 */
class AgentEvalCaseCommandsPlanServiceDouble extends AgentEvalSuitePlanService
{
    /** @var string[] The fixture file or folder by name */
    public array $fixturePathsByName = [];

    public function findFixturePath(string $fixturePathName): ?string
    {
        return $this->fixturePathsByName[$fixturePathName] ?? null;
    }
}
