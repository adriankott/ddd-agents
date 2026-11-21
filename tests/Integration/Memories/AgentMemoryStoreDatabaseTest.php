<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Memories;

use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemory;
use DDD\Domain\AI\Repo\DB\Conversations\Memories\DBAgentMemoryModel;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoryStoreService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * The memory store on MariaDB and PostgreSQL: a stored memory without an embedding provider (zero vector, found by the
 * text fallback), the scope of a read (the account level plus one space, never another space), the generated dedup
 * key that keeps one current row per scope and content (a closed duplicate stays as history), and the bitemporal
 * closing of a row.
 */
class AgentMemoryStoreDatabaseTest extends DatabaseTestCase
{
    public const int ACCOUNT_ID = 701;

    public const int AGENT_SPACE_ID = 31;

    public const int OTHER_AGENT_SPACE_ID = 32;

    protected ?Account $accountBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountBefore = AuthService::instance()->getAccount();
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::ACCOUNT_ID, 'email' => 'memory@example.test']);
        foreach ([self::AGENT_SPACE_ID, self::OTHER_AGENT_SPACE_ID] as $agentSpaceId) {
            $this->insertAgentSpaceRow(['id' => $agentSpaceId, 'accountId' => self::ACCOUNT_ID]);
        }
        $account = Account::newInstance();
        $account->id = self::ACCOUNT_ID;
        AuthService::instance()->setAccount($account);
    }

    protected function tearDown(): void
    {
        AuthService::instance()->setAccount($this->accountBefore);
        parent::tearDown();
    }

    public function testAReadHoldsTheAccountLevelAndTheOneSpace(): void
    {
        $this->storeMemory('the owner is called Alex', null);
        $this->storeMemory('has 4 refrigerated counters', self::AGENT_SPACE_ID);
        $this->storeMemory('the other shop sells bread', self::OTHER_AGENT_SPACE_ID);

        $agentMemoryStoreService = $this->getAgentMemoryStoreService();
        self::assertSame(
            ['has 4 refrigerated counters', 'the owner is called Alex'],
            $this->collectContents($agentMemoryStoreService->findCurrentMemoriesForScope(self::ACCOUNT_ID, self::AGENT_SPACE_ID)->getElements())
        );
        self::assertSame(
            ['the owner is called Alex'],
            $this->collectContents($agentMemoryStoreService->findCurrentMemoriesForScope(self::ACCOUNT_ID)->getElements())
        );
        // without an embedding provider the search falls back to a text match
        self::assertSame(
            ['has 4 refrigerated counters'],
            $this->collectContents($agentMemoryStoreService->findSimilarMemories('Refrigerated', self::ACCOUNT_ID, self::AGENT_SPACE_ID)->getElements())
        );
    }

    public function testOneCurrentRowPerScopeAndContentAndAClosedDuplicateStaysAsHistory(): void
    {
        $firstMemory = $this->storeMemory('Has 4 refrigerated counters', self::AGENT_SPACE_ID);
        $agentMemoryStoreService = $this->getAgentMemoryStoreService();
        self::assertSame(
            (int)$firstMemory->id,
            (int)$agentMemoryStoreService->findCurrentDuplicateOfContent(self::ACCOUNT_ID, self::AGENT_SPACE_ID, ' has 4  refrigerated counters')->id
        );
        self::assertNull($agentMemoryStoreService->findCurrentDuplicateOfContent(self::ACCOUNT_ID, self::OTHER_AGENT_SPACE_ID, 'has 4 refrigerated counters'));

        $this->storeMemory('has 4 refrigerated counters', self::OTHER_AGENT_SPACE_ID);
        try {
            $this->insertDuplicateRow('has 4 refrigerated counters', 's:' . self::AGENT_SPACE_ID, null);
            self::fail('a second current row of the same scope and content must be refused');
        } catch (UniqueConstraintViolationException) {
        }
        $this->insertDuplicateRow('has 4 refrigerated counters', 's:' . self::AGENT_SPACE_ID, '2026-01-01 00:00:00');
        self::assertSame(3, (int)$this->getDatabaseConnection()->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->getSqlDialect()->quote(DBAgentMemoryModel::getTableName())
        ));
    }

    public function testSupersedingAndForgettingCloseTheRow(): void
    {
        $agentMemoryStoreService = $this->getAgentMemoryStoreService();
        $oldMemory = $this->storeMemory('has 1 dog', null);
        $newMemory = $this->storeMemory('has 2 dogs', null);

        self::assertSame(1, $agentMemoryStoreService->closeValidityWindow((int)$oldMemory->id, (int)$newMemory->id, new DateTime('now')));
        self::assertSame(0, $agentMemoryStoreService->closeValidityWindow((int)$oldMemory->id, (int)$newMemory->id, new DateTime('now')), 'a closed row stays closed');
        $storedOldRow = $this->findStoredRow(DBAgentMemoryModel::getTableName(), (int)$oldMemory->id);
        self::assertSame((int)$newMemory->id, (int)$storedOldRow['supersededByMemoryId']);

        $forgottenMemory = $agentMemoryStoreService->invalidateMemory((int)$newMemory->id);
        self::assertNotNull($forgottenMemory);
        self::assertFalse($forgottenMemory->isCurrentlyValid());
        self::assertNull($agentMemoryStoreService->invalidateMemory((int)$newMemory->id), 'nothing left to forget');
        self::assertSame(0, $agentMemoryStoreService->findCurrentMemoriesForScope(self::ACCOUNT_ID)->count());
    }

    protected function storeMemory(string $content, ?int $agentSpaceId): AgentMemory
    {
        $agentMemory = AgentMemory::buildStated($content, AgentMemory::MEMORY_TYPE_FACT, null);
        $agentMemory->anchorToScope(self::ACCOUNT_ID, $agentSpaceId);
        return $this->getAgentMemoryStoreService()->persistMemory($agentMemory);
    }

    protected function insertDuplicateRow(string $content, string $scopeKey, ?string $invalidatedAt): void
    {
        $this->insertRow(DBAgentMemoryModel::getTableName(), [
            'accountId' => self::ACCOUNT_ID,
            'agentSpaceId' => self::AGENT_SPACE_ID,
            'memoryType' => AgentMemory::MEMORY_TYPE_FACT,
            'subject' => AgentMemory::SUBJECT_BUSINESS,
            'content' => $content,
            'source' => AgentMemory::SOURCE_USER_STATED,
            'contentHash' => AgentMemory::hashForContent($content),
            'scopeKey' => $scopeKey,
            'validTo' => $invalidatedAt,
            'invalidatedAt' => $invalidatedAt,
        ]);
    }

    /**
     * @param AgentMemory[] $agentMemories
     * @return string[]
     */
    protected function collectContents(array $agentMemories): array
    {
        $contents = [];
        foreach ($agentMemories as $agentMemory) {
            $contents[] = $agentMemory->content;
        }
        return $contents;
    }

    protected function getAgentMemoryStoreService(): AgentMemoryStoreService
    {
        /** @var AgentMemoryStoreService $agentMemoryStoreService */
        $agentMemoryStoreService = DDDService::instance()->getService(AgentMemoryStoreService::class);
        return $agentMemoryStoreService;
    }
}
