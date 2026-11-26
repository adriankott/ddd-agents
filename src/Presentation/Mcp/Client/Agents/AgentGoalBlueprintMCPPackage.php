<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRights;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPRequiresAccountRights;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolParallelSafe;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolReturnsTrustedContent;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolSummary;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\Conversations\Goals\AgentGoalBlueprintProviderService;
use DDD\Domain\AI\Services\Conversations\Goals\AgentGoalsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentGoalBlueprintGetRequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentGoalBlueprintMcpResponseDto;

/**
 * The standard goal tree of the goal register (`getGoalBlueprint`), bound under the skill `Agent.Goals` beside
 * {@see AgentGoalsMCPPackage}. A package of its own because the tool is offered only while an application binds a
 * blueprint provider ({@see AgentGoalBlueprintProviderService::isBlueprintProviderBound()}) and goals are switched on;
 * otherwise the skill binding leaves it out and a direct call is refused. The package ships no tree.
 */
#[MCPToolReturnsTrustedContent('the application\'s standard goal tree and its own assessment of the space')]
#[MCPRequiresAccountRights(AgentAccountRights::USE_AGENT_GOALS)]
class AgentGoalBlueprintMCPPackage extends MCPPackage
{
    public static ?string $name = 'agent.goals.blueprint';

    public static ?string $description = 'Read the standard goal tree of the business (what a business of this kind should have), assessed against its data: the source of every standard goal.';

    /** @var string The refusal of a direct call while no tree is bound or goals are off */
    public const string NO_BLUEPRINT_MESSAGE = 'This installation has no standard goal tree.';

    public static function isAvailable(): bool
    {
        return static::isGoalsSwitchedOn() && AgentGoalBlueprintProviderService::isBlueprintProviderBound();
    }

    /**
     * Whether goals are switched on ({@see AgentGoalsService::isGoalsEnabled()}).
     *
     * @return bool
     */
    protected static function isGoalsSwitchedOn(): bool
    {
        return AgentGoalsService::isGoalsEnabled();
    }

    /**
     * The standard goal tree — what a business of this kind should have — pruned to this space and assessed against its
     * data: every leaf shows where the business stands (✅ done, ⬜ open, ❔ not yet measured). Read it to build the
     * owner's tree: add the owner's own goals under the fitting area, propose the most valuable open nodes and add the
     * adopted ones with addGoal (`blueprintKey` = the node's key). Standard goals come only from here.
     *
     * @example {}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolParallelSafe('one build of the standard goal tree, nothing written')]
    #[MCPToolSummary('The standard goal tree for this space, assessed (✅ done / ⬜ open / ❔ not yet measured). Read it to propose standard goals; adopt a node with addGoal and its key as blueprintKey. Never invent a standard goal.')]
    public function getGoalBlueprint(
        AgentGoalBlueprintGetRequestDto $requestDto,
        AgentGoalBlueprintProviderService $agentGoalBlueprintProviderService,
        AgentToolCallProvenance $callProvenance
    ): AgentGoalBlueprintMcpResponseDto {
        if (!static::isAvailable()) {
            throw new BadRequestException(static::NO_BLUEPRINT_MESSAGE);
        }
        $actingAccount = $this->requireActingAccount($callProvenance);
        return AgentGoalBlueprintMcpResponseDto::forBlueprint($agentGoalBlueprintProviderService->buildAssessedBlueprintForAgentSpace(
            $actingAccount,
            $this->findAgentSpaceOfCall($requestDto->agentSpaceId, $callProvenance)
        ));
    }

    /**
     * The account the call acts for: the owner of the conversation, else the signed-in account.
     *
     * @param AgentToolCallProvenance $callProvenance
     * @return Account
     * @throws ForbiddenException
     */
    protected function requireActingAccount(AgentToolCallProvenance $callProvenance): Account
    {
        $conversation = $callProvenance->conversation;
        $actingAccount = ($conversation !== null ? ($conversation->account ?? null) : null) ?? AuthService::instance()->getAccount();
        if (!$actingAccount instanceof Account || !isset($actingAccount->id)) {
            throw new ForbiddenException('No authenticated account');
        }
        return $actingAccount;
    }

    /**
     * The space the call names (its use by the account checked), else the conversation's space; null at account level.
     *
     * @param int|null $agentSpaceId
     * @param AgentToolCallProvenance $callProvenance
     * @return AgentSpace|null
     */
    protected function findAgentSpaceOfCall(?int $agentSpaceId, AgentToolCallProvenance $callProvenance): ?AgentSpace
    {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        $agentSpace = $agentSpacesService->findAgentSpaceOfToolCall([AgentSpacesService::TOOL_ARGUMENT_AGENT_SPACE_ID => $agentSpaceId], $callProvenance);
        return $agentSpace instanceof AgentSpace && isset($agentSpace->id) ? $agentSpace : null;
    }
}
