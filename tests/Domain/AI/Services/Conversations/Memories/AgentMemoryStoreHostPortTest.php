<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Memories;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemory;
use DDD\Domain\AI\Repo\DB\Conversations\Memories\DBAgentMemoryModel;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoryStoreService;
use DDD\Domain\AI\Services\Eval\AIConversationEvalPurgeService;
use DDD\Tests\Agents\Domain\AI\Services\Eval\AIConversationEvalPurgeServiceWithHostMemoryStore;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The memory store is the host port: a host whose memories are rows of its own puts a subclass of
 * {@see AgentMemoryStoreService} in place and switches memory on. The core's directives and search, the embedding
 * repair and the evaluation purge then read through that store — with the host row's id on the carrier — and nothing
 * names the package's table.
 */
class AgentMemoryStoreHostPortTest extends UnitTestCase
{
    protected function buildHostBackedStore(): HostBackedAgentMemoryStoreService
    {
        $hostMemoryNoteRows = new HostMemoryNoteRows();
        $hostMemoryNoteRow1 = new HostMemoryNoteRow(7, 1, null, true, 'answer in short sentences');
        $hostMemoryNoteRows->add($hostMemoryNoteRow1);
        $hostMemoryNoteRow2 = new HostMemoryNoteRow(8, 1, 2, false, 'the shop opens at nine');
        $hostMemoryNoteRows->add($hostMemoryNoteRow2);
        $hostMemoryNoteRow3 = new HostMemoryNoteRow(9, 1, 3, true, 'a rule of another workspace');
        $hostMemoryNoteRows->add($hostMemoryNoteRow3);
        $hostMemoryNoteRow4 = new HostMemoryNoteRow(4, 5, null, true, 'a rule of another account');
        $hostMemoryNoteRows->add($hostMemoryNoteRow4);
        return new HostBackedAgentMemoryStoreService($hostMemoryNoteRows);
    }

    public function testTheDirectivesComeFromTheHostRowsOfTheScope(): void
    {
        $agentMemoriesService = new AgentMemoriesServiceWithHostStoreDouble($this->buildHostBackedStore());
        $agentSpace = new AgentSpace();
        $agentSpace->id = 2;

        $agentStandingDirectives = $agentMemoriesService->findStandingDirectivesForAgentSpace(1, $agentSpace);

        self::assertNotNull($agentStandingDirectives);
        self::assertStringContainsString('answer in short sentences', $agentStandingDirectives->directivesBlock);
        self::assertStringNotContainsString('another workspace', $agentStandingDirectives->directivesBlock);
        self::assertStringNotContainsString('another account', $agentStandingDirectives->directivesBlock);
        self::assertContains('findCurrentMemoriesForScope', $agentMemoriesService->hostBackedAgentMemoryStoreService->askedPortMethods);
    }

    public function testTheSearchAnswersWithTheHostRowIds(): void
    {
        $agentMemoriesService = new AgentMemoriesServiceWithHostStoreDouble($this->buildHostBackedStore());
        $agentSpace = new AgentSpace();
        $agentSpace->id = 2;

        $agentMemoryHits = $agentMemoriesService->findSimilarMemories('opens', 1, $agentSpace, 5);

        self::assertSame(1, $agentMemoryHits->count());
        self::assertSame(8, $agentMemoryHits->first()->memoryId);
        self::assertSame(AgentMemory::MEMORY_TYPE_FACT, $agentMemoryHits->first()->memoryType);
    }

    public function testTheEmbeddingRepairCoversTheTablesTheHostStoreNames(): void
    {
        $agentMemoriesService = new AgentMemoriesServiceWithHostStoreDouble($this->buildHostBackedStore());

        self::assertSame(0, $agentMemoriesService->getEmbeddingRepairTargets()->count());
        self::assertContains('getEmbeddingRepairTargets', $agentMemoriesService->hostBackedAgentMemoryStoreService->askedPortMethods);
    }

    public function testThePackageStoreNamesItsTableForTheRepairAndThePurge(): void
    {
        $agentMemoryStoreService = new AgentMemoryStoreService();
        $memoryTableName = (string)DBAgentMemoryModel::getTableName();

        self::assertSame($memoryTableName, $agentMemoryStoreService->getEmbeddingRepairTargets()->first()->getTableName());
        self::assertArrayHasKey($memoryTableName . '.sourceMessageId', $agentMemoryStoreService->getColumnsNotPurgedByEvalPurge());
        self::assertArrayHasKey($memoryTableName . '.sourceMessageId', (new AIConversationEvalPurgeService())->getColumnsNotPurged());
    }

    public function testTheEvaluationPurgeTakesTheMemoryColumnsOfTheHostStore(): void
    {
        $hostBackedAgentMemoryStoreService = $this->buildHostBackedStore();
        $columnsNotPurged = (new AIConversationEvalPurgeServiceWithHostMemoryStore($hostBackedAgentMemoryStoreService))->getColumnsNotPurged();

        self::assertArrayNotHasKey(DBAgentMemoryModel::getTableName() . '.sourceMessageId', $columnsNotPurged);
        self::assertContains('getColumnsNotPurgedByEvalPurge', $hostBackedAgentMemoryStoreService->askedPortMethods);
    }
}
