<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\Client\Agents;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Goals\AgentGoal;
use DDD\Presentation\Mcp\Client\Agents\AgentGoalsMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentGoalAddRequestDto;

/**
 * An application's goal tools that refuse an owner goal naming a competitor (the application's own rule), and record
 * the scope and holder of each goal the hook was asked about.
 */
class AgentGoalsMCPPackageRefusingDouble extends AgentGoalsMCPPackage
{
    public const string REFUSAL_REASON = 'a goal about a competitor is not the owner\'s own goal';

    /** @var string[] The goals the hook was asked about, in order: `<accountId>/<agentSpaceId or none>/<holder>` */
    public array $askedGoalScopes = [];

    protected function findReasonToRefuseGoal(AgentGoal $agentGoal, AgentGoalAddRequestDto $requestDto, AgentToolCallProvenance $callProvenance): ?string
    {
        $this->askedGoalScopes[] = $agentGoal->accountId . '/' . ($agentGoal->agentSpaceId ?? 'none') . '/' . $agentGoal->holder;
        return str_contains(strtolower($agentGoal->statement), 'competitor') ? static::REFUSAL_REASON : null;
    }
}
