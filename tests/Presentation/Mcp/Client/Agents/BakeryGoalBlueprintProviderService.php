<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\Client\Agents;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Goals\Blueprints\AgentGoalBlueprint;
use DDD\Domain\AI\Services\Conversations\Goals\AgentGoalBlueprintProviderService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Goals\Blueprints\AgentGoalBlueprintTest;

/** An application's standard goal tree for a bakery; records the account and space ids it was asked for. */
class BakeryGoalBlueprintProviderService extends AgentGoalBlueprintProviderService
{
    /** @var string[] `<accountId>/<agentSpaceId or none>`, in order */
    public array $askedScopes = [];

    public function buildAssessedBlueprintForAgentSpace(Account $account, ?AgentSpace $agentSpace): ?AgentGoalBlueprint
    {
        $this->askedScopes[] = $account->id . '/' . ($agentSpace?->id ?? 'none');
        return AgentGoalBlueprintTest::buildBakeryBlueprint();
    }
}
