<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Goals;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Goals\Blueprints\AgentGoalBlueprint;
use DDD\Domain\AI\Entities\Conversations\Goals\Blueprints\AgentGoalBlueprintNode;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * The port of the standard goal tree: what a business of this kind should have, as the application knows it. The
 * package ships no tree and binds none: this default answers null, and the tool `getGoalBlueprint`
 * ({@see \DDD\Presentation\Mcp\Client\Agents\AgentGoalBlueprintMCPPackage}) is offered only while an application binds
 * a provider ({@see isBlueprintProviderBound()}).
 *
 * An application binds one with a same-path subclass of this service under its root namespace and answers
 * {@see buildAssessedBlueprintForAgentSpace()}: it builds its tree ({@see AgentGoalBlueprint},
 * {@see \DDD\Domain\AI\Entities\Conversations\Goals\Blueprints\AgentGoalBlueprintNode}), prunes it to what applies to the
 * space (its kind of business, the capabilities it has) and assesses every leaf against the space's data
 * (`assess()` with MET, OPEN or UNKNOWN). The read rights of the caller are in force.
 */
class AgentGoalBlueprintProviderService extends Service
{
    /**
     * Whether an application binds a provider: the container resolves this port to a subclass.
     *
     * @return bool
     */
    public static function isBlueprintProviderBound(): bool
    {
        try {
            $agentGoalBlueprintProviderService = DDDService::instance()->getService(AgentGoalBlueprintProviderService::class);
        } catch (Throwable) {
            return false;
        }
        return $agentGoalBlueprintProviderService instanceof AgentGoalBlueprintProviderService
            && $agentGoalBlueprintProviderService::class !== AgentGoalBlueprintProviderService::class;
    }

    /**
     * The standard goal tree for the space (or the account level when `$agentSpace` is empty), pruned to what applies
     * there and assessed against its data; null when the application has no tree for it. The default binds nothing.
     *
     * @param Account $account the account the call acts for
     * @param AgentSpace|null $agentSpace the space of the call
     * @return AgentGoalBlueprint|null
     */
    public function buildAssessedBlueprintForAgentSpace(Account $account, ?AgentSpace $agentSpace): ?AgentGoalBlueprint
    {
        return null;
    }

    /**
     * One node of the standard tree by its key, for `addGoal` to take the node's done criterion as the success signal
     * of a goal that names the node and has none. The default looks the key up in
     * {@see buildAssessedBlueprintForAgentSpace()}; an application may answer from its static tree without the
     * assessment. Null when there is no tree or no such node.
     *
     * @param Account $account the account the call acts for
     * @param AgentSpace|null $agentSpace the space of the call
     * @param string $nodeKey the node's key (`presence.photos`)
     * @return AgentGoalBlueprintNode|null
     */
    public function findStandardNodeByKey(Account $account, ?AgentSpace $agentSpace, string $nodeKey): ?AgentGoalBlueprintNode
    {
        return $this->buildAssessedBlueprintForAgentSpace($account, $agentSpace)?->findNodeByKey($nodeKey);
    }
}
