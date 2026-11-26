<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\Client\Agents;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\Goals\AgentGoalsService;
use DDD\Domain\AI\Services\Conversations\Goals\AgentGoalStoreService;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Libs\Config;
use DDD\Presentation\Mcp\Client\Agents\AgentGoalsMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentGoalAddRequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentGoalsMcpResponseDto;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Goals\AgentGoalsSwitch;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Goals\CopyingAgentGoalStoreService;
use DDD\Tests\Agents\Support\IntegrationTestCase;

/**
 * `addGoal` over a host store that writes a copy and leaves the passed goal without an id: a new goal is answered as
 * saved, its exact restatement as already saved.
 */
class AgentGoalsOverACopyingStoreTest extends IntegrationTestCase
{
    protected CopyingAgentGoalStoreService $copyingAgentGoalStoreService;

    protected AgentGoalsSwitch $agentGoalsSwitch;

    protected function setUp(): void
    {
        parent::setUp();
        if (Config::getEnv('LAZYLOAD_DEFAULT_REPO_TYPE') === null) {
            LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
        }
        $this->copyingAgentGoalStoreService = new CopyingAgentGoalStoreService();
        $this->replaceService(AgentGoalStoreService::class, $this->copyingAgentGoalStoreService);
        $this->agentGoalsSwitch = new AgentGoalsSwitch();
        $this->agentGoalsSwitch->switchGoals(true);
    }

    protected function onTearDown(): void
    {
        $this->agentGoalsSwitch->restore();
        AgentGoalStoreService::forgetGoalTableCheck();
    }

    public function testANewGoalIsSavedAndItsRestatementIsAlreadySaved(): void
    {
        $addRequestDto = new AgentGoalAddRequestDto();
        $addRequestDto->statement = 'The terrace opens in May.';

        $savedResponseDto = (new AgentGoalsMCPPackage())->addGoal($addRequestDto, new AgentGoalsService(), $this->buildCallProvenance());
        self::assertSame(AgentGoalsMcpResponseDto::NOTE_SAVED, $savedResponseDto->note);

        $againResponseDto = (new AgentGoalsMCPPackage())->addGoal($addRequestDto, new AgentGoalsService(), $this->buildCallProvenance());
        self::assertSame(AgentGoalsMcpResponseDto::NOTE_ALREADY_SAVED, $againResponseDto->note);
        self::assertSame(1, count($this->copyingAgentGoalStoreService->findWriteCalls()));
    }

    protected function buildCallProvenance(): AgentToolCallProvenance
    {
        $account = new Account();
        $account->id = 1;
        $conversation = new AIConversation();
        $conversation->accountId = 1;
        $conversation->account = $account;
        $agentSpace = new AgentSpace();
        $agentSpace->id = 2;
        return new AgentToolCallProvenance(agentSpace: $agentSpace, conversation: $conversation);
    }
}
