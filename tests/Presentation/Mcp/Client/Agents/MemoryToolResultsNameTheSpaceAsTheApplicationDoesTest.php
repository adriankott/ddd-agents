<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\Client\Agents;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Libs\Config;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentMemoryListRequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentMemorySearchRequestDto;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Memories\ShopLabelAgentSpacesService;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Memories\HostBackedAgentMemoryStoreService;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Memories\HostMemoryNoteRow;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Memories\HostMemoryNoteRows;
use DDD\Tests\Agents\Support\IntegrationTestCase;

/**
 * The results of the recall tools `listMemories` and `searchMemory` reach the model as memory lines
 * ({@see \DDD\Presentation\Mcp\Client\Agents\Dtos\AgentMemoriesMcpResponseDto::toLlmMarkdown()}): a memory of the space
 * carries the application's label of the space, a memory of the account reads `account`.
 */
class MemoryToolResultsNameTheSpaceAsTheApplicationDoesTest extends IntegrationTestCase
{
    protected ShopLabelAgentSpacesService $shopLabelAgentSpacesService;

    protected function setUp(): void
    {
        parent::setUp();
        if (Config::getEnv('LAZYLOAD_DEFAULT_REPO_TYPE') === null) {
            LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
        }
        $this->shopLabelAgentSpacesService = new ShopLabelAgentSpacesService();
        $this->shopLabelAgentSpacesService->shopIdsByAgentSpaceId = [2 => 4711];
        $this->replaceService(AgentSpacesService::class, $this->shopLabelAgentSpacesService);
    }

    public function testListMemoriesNamesTheSpaceOfAMemoryWithTheApplicationsLabel(): void
    {
        $agentMemoriesMcpResponseDto = (new AgentMemoriesMCPPackageSwitchedOn())->listMemories(
            new AgentMemoryListRequestDto(),
            $this->buildHostBackedStore(),
            $this->buildCallProvenance()
        );
        $toolResultText = $agentMemoriesMcpResponseDto->toLlmMarkdown();

        self::assertStringContainsString('- #7 [DIRECTIVE/account] "answer in short sentences"', $toolResultText);
        self::assertStringContainsString('- #8 [FACT/shop:4711] "the shop opens at nine"', $toolResultText);
        self::assertStringNotContainsString('space:', $toolResultText);
        self::assertSame([2], $this->shopLabelAgentSpacesService->askedAgentSpaceIds);
    }

    public function testSearchMemoryNamesTheSpaceOfAMemoryWithTheApplicationsLabel(): void
    {
        $agentMemorySearchRequestDto = new AgentMemorySearchRequestDto();
        $agentMemorySearchRequestDto->query = 'opens';

        $toolResultText = (new AgentMemoriesMCPPackageSwitchedOn())->searchMemory(
            $agentMemorySearchRequestDto,
            $this->buildHostBackedStore(),
            $this->buildCallProvenance()
        )->toLlmMarkdown();

        self::assertStringStartsWith('- #8 [FACT/shop:4711] "the shop opens at nine" (USER_STATED; ', $toolResultText);
    }

    protected function buildHostBackedStore(): HostBackedAgentMemoryStoreService
    {
        $hostMemoryNoteRows = new HostMemoryNoteRows();
        $accountRule = new HostMemoryNoteRow(7, 1, null, true, 'answer in short sentences');
        $hostMemoryNoteRows->add($accountRule);
        $shopFact = new HostMemoryNoteRow(8, 1, 2, false, 'the shop opens at nine');
        $hostMemoryNoteRows->add($shopFact);
        return new HostBackedAgentMemoryStoreService($hostMemoryNoteRows);
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
