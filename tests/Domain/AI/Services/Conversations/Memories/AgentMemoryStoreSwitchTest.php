<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Memories;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoriesService;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoryStoreService;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Libs\Config;
use DDD\Presentation\Mcp\Client\Agents\AgentMemoriesMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\AgentTaskSchedulesMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentMemorySearchRequestDto;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The switch of the package's memory store (`AI.agentMemory.enabled`, off in the package's config): off, the core's
 * memory questions are answered "no memory" without touching the table, the memory tools are left out of the skill
 * binding and a direct call is refused; on, the port answers from the store.
 */
class AgentMemoryStoreSwitchTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (Config::getEnv('LAZYLOAD_DEFAULT_REPO_TYPE') === null) {
            LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
        }
    }

    public function testThePackageShipsTheStoreSwitchedOff(): void
    {
        $agentMemoryConfig = require dirname(__DIR__, 6) . '/config/app/AI/agentMemory.php';

        self::assertFalse($agentMemoryConfig['enabled']);
        self::assertFalse(AgentMemoriesService::isMemoryStoreEnabled());
        self::assertFalse(AgentMemoriesMCPPackage::isAvailable());
    }

    public function testSwitchedOffThePortAnswersNoMemory(): void
    {
        $agentMemoriesService = new AgentMemoriesService();
        $agentSpace = new AgentSpace();
        $agentSpace->id = 2;

        self::assertNull($agentMemoriesService->findStandingDirectivesForAgentSpace(1, $agentSpace));
        self::assertSame(0, $agentMemoriesService->findSimilarMemories('query', 1, $agentSpace, 5)->count());
        self::assertSame(0, $agentMemoriesService->getEmbeddingRepairTargets()->count());
    }

    public function testSwitchedOffTheMemorySkillLeavesTheBindingAndOtherSkillsStay(): void
    {
        $filteredBinding = AISkill::withoutUnavailablePackages([
            AISkill::AGENT_MEMORY => [AgentMemoriesMCPPackage::class],
            AISkill::AGENT_TASKS => [AgentTaskSchedulesMCPPackage::class],
            'Host.Mixed' => [AgentMemoriesMCPPackage::class, AgentTaskSchedulesMCPPackage::class],
            'Host.WithoutPackages' => [],
        ]);

        self::assertArrayNotHasKey(AISkill::AGENT_MEMORY, $filteredBinding);
        self::assertSame([AgentTaskSchedulesMCPPackage::class], $filteredBinding[AISkill::AGENT_TASKS]);
        self::assertSame([AgentTaskSchedulesMCPPackage::class], $filteredBinding['Host.Mixed']);
        self::assertSame([], $filteredBinding['Host.WithoutPackages']);
    }

    public function testSwitchedOffADirectToolCallIsRefused(): void
    {
        $agentMemorySearchRequestDto = new AgentMemorySearchRequestDto();
        $agentMemorySearchRequestDto->query = 'anything';

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage(AgentMemoriesMCPPackage::MEMORY_STORE_OFF_MESSAGE);
        (new AgentMemoriesMCPPackage())->searchMemory($agentMemorySearchRequestDto, new AgentMemoryStoreService(), new AgentToolCallProvenance());
    }

    public function testSwitchedOnThePortAnswersTheDirectivesOfTheStore(): void
    {
        $agentMemoriesService = new AgentMemoriesServiceSwitchedOnDouble();
        $agentSpace = new AgentSpace();
        $agentSpace->id = 2;

        $agentStandingDirectives = $agentMemoriesService->findStandingDirectivesForAgentSpace(1, $agentSpace);

        self::assertSame([1, 2], $agentMemoriesService->agentMemoryStoreServiceDouble->askedScope);
        self::assertNotNull($agentStandingDirectives);
        self::assertStringContainsString('- answer briefly', $agentStandingDirectives->directivesBlock);
        self::assertSame('2026-01-01', $agentStandingDirectives->newestValidFrom->format('Y-m-d'));
        self::assertSame(1, $agentMemoriesService->getEmbeddingRepairTargets()->count());
    }
}
