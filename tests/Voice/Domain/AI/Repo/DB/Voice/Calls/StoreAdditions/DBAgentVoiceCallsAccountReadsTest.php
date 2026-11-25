<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Repo\DB\Voice\Calls\StoreAdditions;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCalls;
use DDD\Domain\AI\Repo\DB\Voice\Calls\DBAgentVoiceCall;
use DDD\Domain\AI\Repo\DB\Voice\Calls\DBAgentVoiceCallModel;
use DDD\Domain\Base\Entities\QueryOptions\OrderByOptions;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Tests\Agents\Voice\Support\AgentVoiceCallFixtures;
use DDD\Tests\Agents\Voice\Support\AgentVoiceIntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The reads of the call table added after its core tests, pinned without a database: each read builds its query and
 * hands it to `find()`, which the double stops and keeps; the test reads the query back as DQL with its parameters.
 * The account scope: null = every account, an empty list = no call (the reads answer it without a query), one account
 * compared with `=`, several with `IN`, duplicates folded first.
 */
class DBAgentVoiceCallsAccountReadsTest extends AgentVoiceIntegrationTestCase
{
    protected QueryRecordingDBAgentVoiceCalls $callsRepository;

    protected string $alias;

    protected bool $defaultQueryOptionsSnapshotTaken = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->callsRepository = new QueryRecordingDBAgentVoiceCalls();
        $this->alias = DBAgentVoiceCall::getBaseModelAlias();
    }

    protected function onTearDown(): void
    {
        if ($this->defaultQueryOptionsSnapshotTaken) {
            AgentVoiceCalls::restoreDefaultQueryOptionsSnapshot();
        }
        parent::onTearDown();
    }

    public function testWithoutAnAccountListTheScopeLeavesTheQueryOverEveryAccount(): void
    {
        $queryBuilder = DBAgentVoiceCall::createQueryBuilder(true);
        $this->callsRepository->restrictQueryToCallsOfAccounts($queryBuilder, null);

        static::assertSame($this->selectAll(), $queryBuilder->getDQL());
        static::assertCount(0, $queryBuilder->getParameters());
    }

    public function testAnEmptyAccountListNarrowsTheScopeToNoCall(): void
    {
        $queryBuilder = DBAgentVoiceCall::createQueryBuilder(true);
        $this->callsRepository->restrictQueryToCallsOfAccounts($queryBuilder, []);

        static::assertSame($this->selectAll() . ' WHERE 1 = 0', $queryBuilder->getDQL());
        static::assertCount(0, $queryBuilder->getParameters());
    }

    /** @param array<int, int|string> $accountIds */
    #[DataProvider('provideOneAccount')]
    public function testOneAccountIsComparedWithEquals(array $accountIds): void
    {
        $queryBuilder = DBAgentVoiceCall::createQueryBuilder(true);
        $this->callsRepository->restrictQueryToCallsOfAccounts($queryBuilder, $accountIds);

        static::assertSame($this->selectAll() . ' WHERE ' . $this->alias . '.accountId = :ownerScopeAccountId', $queryBuilder->getDQL());
        static::assertCount(1, $queryBuilder->getParameters());
        static::assertSame(5, $queryBuilder->getParameter('ownerScopeAccountId')->getValue());
    }

    /** @return array<string, array{array<int, int|string>}> */
    public static function provideOneAccount(): array
    {
        return ['one id' => [[5]], 'one id as text' => [['5']], 'the same id twice' => [[5, '5']]];
    }

    public function testSeveralAccountsAreComparedWithInWithoutDuplicates(): void
    {
        $queryBuilder = DBAgentVoiceCall::createQueryBuilder(true);
        $this->callsRepository->restrictQueryToCallsOfAccounts($queryBuilder, [6, '5', 6, 5]);

        static::assertSame($this->selectAll() . ' WHERE ' . $this->alias . '.accountId IN (:ownerScopeAccountIds)', $queryBuilder->getDQL());
        static::assertCount(1, $queryBuilder->getParameters());
        static::assertSame([6, 5], $queryBuilder->getParameter('ownerScopeAccountIds')->getValue());
    }

    public function testTheCallsOfSeveralAccountsAreReadFreshNewestFirst(): void
    {
        $storedCalls = new AgentVoiceCalls();
        $this->callsRepository->answer = $storedCalls;

        static::assertSame($storedCalls, $this->callsRepository->findCallsOfAccounts([5, 6]));

        $recordedQuery = $this->singleRecordedQuery();
        static::assertFalse($recordedQuery['useEntityRegistrCache']);
        static::assertSame(
            $this->selectAll() . ' WHERE ' . $this->alias . '.accountId IN (:ownerScopeAccountIds) ORDER BY ' . $this->alias . '.id DESC',
            $recordedQuery['queryBuilder']->getDQL()
        );
        static::assertSame([5, 6], $recordedQuery['queryBuilder']->getParameter('ownerScopeAccountIds')->getValue());
        static::assertNull($recordedQuery['queryBuilder']->getMaxResults());
    }

    public function testTheCallsOfEveryAccountAreReadWithoutAnAccountCondition(): void
    {
        static::assertNull($this->callsRepository->findCallsOfAccounts(null));

        $recordedQuery = $this->singleRecordedQuery();
        static::assertSame($this->selectAll() . ' ORDER BY ' . $this->alias . '.id DESC', $recordedQuery['queryBuilder']->getDQL());
        static::assertCount(0, $recordedQuery['queryBuilder']->getParameters());
    }

    public function testARequestedOrderReplacesTheNewestFirstOrder(): void
    {
        AgentVoiceCalls::setDefaultQueryOptionsSnapshot();
        $this->defaultQueryOptionsSnapshotTaken = true;
        $requestedOrder = OrderByOptions::fromString('created asc');
        AgentVoiceCalls::getDefaultQueryOptions()->setOrderBy($requestedOrder, false);

        $this->callsRepository->findCallsOfAccounts([5]);

        static::assertSame(
            $this->selectAll() . ' WHERE ' . $this->alias . '.accountId = :ownerScopeAccountId',
            $this->singleRecordedQuery()['queryBuilder']->getDQL()
        );
    }

    public function testAnEmptyAccountListReadsNoCallWithoutAQuery(): void
    {
        static::assertNull($this->callsRepository->findCallsOfAccounts([]));
        static::assertNull($this->callsRepository->findCallOfAccountsById(9, []));
        static::assertSame([], $this->callsRepository->recordedQueries);
    }

    public function testOneCallOfSeveralAccountsIsReadByIdAsTheFirstOfOneRow(): void
    {
        $storedCall = new AgentVoiceCall();
        $storedCall->id = 9;
        $storedCalls = new AgentVoiceCalls();
        $storedCalls->add($storedCall);
        $this->callsRepository->answer = $storedCalls;

        static::assertSame($storedCall, $this->callsRepository->findCallOfAccountsById(9, [5, 6]));

        $recordedQuery = $this->singleRecordedQuery();
        static::assertFalse($recordedQuery['useEntityRegistrCache']);
        static::assertSame(
            $this->selectAll() . ' WHERE ' . $this->alias . '.accountId IN (:ownerScopeAccountIds) AND ' . $this->alias . '.id = :scopeCallId',
            $recordedQuery['queryBuilder']->getDQL()
        );
        static::assertSame([5, 6], $recordedQuery['queryBuilder']->getParameter('ownerScopeAccountIds')->getValue());
        static::assertSame(9, $recordedQuery['queryBuilder']->getParameter('scopeCallId')->getValue());
        static::assertSame(1, $recordedQuery['queryBuilder']->getMaxResults());
    }

    public function testOneCallOfWhicheverAccountIsReadByIdAlone(): void
    {
        static::assertNull($this->callsRepository->findCallOfAccountsById(9, null));

        $recordedQuery = $this->singleRecordedQuery();
        static::assertSame($this->selectAll() . ' WHERE ' . $this->alias . '.id = :scopeCallId', $recordedQuery['queryBuilder']->getDQL());
        static::assertCount(1, $recordedQuery['queryBuilder']->getParameters());
    }

    #[DataProvider('provideRingDeadlineLimits')]
    public function testTheRingsPastTheirDeadlineAreTheUnansweredOpenCallsOfTheAccountDueByNow(int $limit, int $expectedMaxResults): void
    {
        $this->callsRepository->findUnansweredCallsPastRingDeadline(AgentVoiceCallFixtures::NOW_UNIX_TIME, $limit, 5);

        $recordedQuery = $this->singleRecordedQuery();
        $queryBuilder = $recordedQuery['queryBuilder'];
        static::assertFalse($recordedQuery['useEntityRegistrCache']);
        static::assertSame(
            $this->selectAll() . ' WHERE ' . $this->alias . '.accountId = :ownerScopeAccountId'
            . ' AND ' . $this->alias . '.startedAt IS NULL'
            . ' AND ' . $this->alias . '.endedAt IS NULL'
            . ' AND ' . $this->alias . '.ringDeadlineAt IS NOT NULL'
            . ' AND ' . $this->alias . '.ringDeadlineAt <= :ringDeadlineNow'
            . ' ORDER BY ' . $this->alias . '.id DESC',
            $queryBuilder->getDQL()
        );
        static::assertSame(5, $queryBuilder->getParameter('ownerScopeAccountId')->getValue());
        static::assertSame(date('Y-m-d H:i:s', AgentVoiceCallFixtures::NOW_UNIX_TIME), $queryBuilder->getParameter('ringDeadlineNow')->getValue());
        static::assertSame($expectedMaxResults, $queryBuilder->getMaxResults());
    }

    /** @return array<string, array{int, int}> */
    public static function provideRingDeadlineLimits(): array
    {
        return ['a limit' => [20, 20], 'the smallest limit' => [1, 1], 'no limit reads one' => [0, 1], 'a negative limit reads one' => [-3, 1]];
    }

    #[DataProvider('provideConversationLimits')]
    public function testTheCallsOfAConversationAreReadNewestFirstUpToTheLimit(?int $limit, int $expectedMaxResults): void
    {
        if ($limit === null) {
            $this->callsRepository->findCallsByConversationId(30);
        } else {
            $this->callsRepository->findCallsByConversationId(30, $limit);
        }

        $recordedQuery = $this->singleRecordedQuery();
        $queryBuilder = $recordedQuery['queryBuilder'];
        static::assertFalse($recordedQuery['useEntityRegistrCache']);
        static::assertSame(
            'SELECT WHERE ' . $this->alias . '.aiConversationId = :scopeConversationId ORDER BY ' . $this->alias . '.id DESC',
            $queryBuilder->getDQL()
        );
        static::assertSame(30, $queryBuilder->getParameter('scopeConversationId')->getValue());
        static::assertSame($expectedMaxResults, $queryBuilder->getMaxResults());
    }

    /** @return array<string, array{int|null, int}> */
    public static function provideConversationLimits(): array
    {
        return ['the default limit' => [null, 50], 'a given limit' => [7, 7]];
    }

    protected function selectAll(): string
    {
        return 'SELECT ' . $this->alias . ' FROM ' . DBAgentVoiceCallModel::class . ' ' . $this->alias;
    }

    /** @return array{queryBuilder: DoctrineQueryBuilder, useEntityRegistrCache: bool} */
    protected function singleRecordedQuery(): array
    {
        static::assertCount(1, $this->callsRepository->recordedQueries);

        return $this->callsRepository->recordedQueries[0];
    }
}
