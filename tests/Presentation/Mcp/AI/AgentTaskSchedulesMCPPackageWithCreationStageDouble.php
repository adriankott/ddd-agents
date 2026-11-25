<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\AI;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Presentation\Mcp\Client\Agents\AgentTaskSchedulesMCPPackage;

/**
 * The task package on a stage for its three creating tools: a fixed acting account, a space or none, and the zone the
 * call thinks in given instead of read from a conversation.
 */
class AgentTaskSchedulesMCPPackageWithCreationStageDouble extends AgentTaskSchedulesMCPPackage
{
    public Account $fixtureActingAccount;

    /** @var AgentSpace|null null = the call is at account level */
    public ?AgentSpace $fixtureAgentSpace = null;

    /** @var string|null null = no zone is known for the call */
    public ?string $fixtureTimezoneIdentifierOfCall = 'Europe/Berlin';

    /** @var bool Whether the zone of the call is only a default nobody confirmed */
    public bool $fixtureTimezoneOfCallUnverified = false;

    protected function findAgentSpaceOfCall(?int $agentSpaceId, AgentToolCallProvenance $callProvenance): ?AgentSpace
    {
        return $this->fixtureAgentSpace;
    }

    protected function requireActingAccount(AgentToolCallProvenance $callProvenance): Account
    {
        return $this->fixtureActingAccount;
    }

    protected function findTimezoneIdentifierOfCall(AgentToolCallProvenance $callProvenance, ?AgentSpace $agentSpace): ?string
    {
        return $this->fixtureTimezoneIdentifierOfCall;
    }

    protected function isTimezoneOfCallUnverified(AgentToolCallProvenance $callProvenance, ?AgentSpace $agentSpace): bool
    {
        return $this->fixtureTimezoneOfCallUnverified;
    }
}
