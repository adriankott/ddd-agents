<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\AI;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Presentation\Mcp\Client\Agents\AgentTaskSchedulesMCPPackage;

/**
 * The task package with a fixed acting account, no space, and — when set — another account as the payer of the
 * call's budget pot; the budget line opened for the test.
 */
class AgentTaskSchedulesMCPPackageWithBudgetPayerDouble extends AgentTaskSchedulesMCPPackage
{
    public Account $fixtureActingAccount;

    /** @var Account|null null = the package's default payer (the acting account) */
    public ?Account $fixtureBudgetPayerAccount = null;

    public function composeScheduleBudgetPotNoteOfCallForTest(
        AIConversationSchedulesService $aIConversationSchedulesService,
        AgentToolCallProvenance $callProvenance
    ): string {
        return $this->composeScheduleBudgetPotNoteOfCall($aIConversationSchedulesService, null, $this->fixtureActingAccount, $callProvenance);
    }

    protected function findBudgetPayerOfCall(?AgentSpace $agentSpace, Account $actingAccount, AgentToolCallProvenance $callProvenance): Account
    {
        return $this->fixtureBudgetPayerAccount ?? parent::findBudgetPayerOfCall($agentSpace, $actingAccount, $callProvenance);
    }

    protected function findAgentSpaceOfCall(?int $agentSpaceId, AgentToolCallProvenance $callProvenance): ?AgentSpace
    {
        return null;
    }

    protected function requireActingAccount(AgentToolCallProvenance $callProvenance): Account
    {
        return $this->fixtureActingAccount;
    }
}
