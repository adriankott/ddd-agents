<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Memories;

use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemories;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemory;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Infrastructure\Libs\Config;
use DDD\Tests\Agents\Support\IntegrationTestCase;

/**
 * The memory line the model reads names the space of a space-bound memory the way the application's agent spaces
 * service does ({@see AgentSpacesService::buildAgentSpaceLabelForTheModel()}); a memory of the account reads `account`
 * and asks the service nothing. The application's service is put in place for the test and taken away after it.
 */
class AgentMemoryLineNamesTheSpaceAsTheApplicationDoesTest extends IntegrationTestCase
{
    protected ShopLabelAgentSpacesService $shopLabelAgentSpacesService;

    protected function setUp(): void
    {
        parent::setUp();
        if (Config::getEnv('LAZYLOAD_DEFAULT_REPO_TYPE') === null) {
            LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
        }
        $this->shopLabelAgentSpacesService = new ShopLabelAgentSpacesService();
        $this->shopLabelAgentSpacesService->shopIdsByAgentSpaceId = [8 => 4711];
        $this->replaceService(AgentSpacesService::class, $this->shopLabelAgentSpacesService);
    }

    public function testASpaceBoundMemoryCarriesTheApplicationsLabelOfTheSpace(): void
    {
        $agentMemory = AgentMemory::buildStated('always answer briefly', AgentMemory::MEMORY_TYPE_DIRECTIVE, null);
        $agentMemory->id = 42;
        $agentMemory->anchorToScope(3, 8);

        self::assertSame('- #42 [DIRECTIVE/shop:4711] "always answer briefly" (USER_STATED)', $agentMemory->toLlmMarkdown());
        self::assertSame([8], $this->shopLabelAgentSpacesService->askedAgentSpaceIds);
    }

    public function testAMemoryOfTheAccountReadsAccountAndAsksTheServiceNothing(): void
    {
        $agentMemory = AgentMemory::buildStated('the owner is called Sam', AgentMemory::MEMORY_TYPE_FACT, null);
        $agentMemory->id = 43;
        $agentMemory->anchorToScope(3, null);

        self::assertSame('- #43 [FACT/account] "the owner is called Sam" (USER_STATED)', $agentMemory->toLlmMarkdown());
        self::assertSame([], $this->shopLabelAgentSpacesService->askedAgentSpaceIds);
    }

    public function testTheSetAsksOncePerSpaceBoundLine(): void
    {
        $agentMemories = AgentMemories::newInstance();
        $accountMemory = AgentMemory::buildStated('the owner is called Sam', AgentMemory::MEMORY_TYPE_FACT, null);
        $accountMemory->id = 43;
        $accountMemory->anchorToScope(3, null);
        $agentMemories->add($accountMemory);
        $spaceMemory = AgentMemory::buildStated('the shop opens at nine', AgentMemory::MEMORY_TYPE_FACT, null);
        $spaceMemory->id = 44;
        $spaceMemory->anchorToScope(3, 8);
        $agentMemories->add($spaceMemory);

        self::assertSame(
            "- #43 [FACT/account] \"the owner is called Sam\" (USER_STATED)\n- #44 [FACT/shop:4711] \"the shop opens at nine\" (USER_STATED)",
            $agentMemories->toLlmMarkdown()
        );
        self::assertSame([8], $this->shopLabelAgentSpacesService->askedAgentSpaceIds);
    }
}
