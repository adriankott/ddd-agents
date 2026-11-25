<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Repo\DB\Voice\Calls\StoreAdditions;

use DDD\Domain\AI\Repo\DB\Voice\Calls\DBAgentVoiceCall;
use DDD\Domain\AI\Repo\DB\Voice\Calls\DBAgentVoiceCallModel;
use DDD\Domain\AI\Repo\DB\Voice\Calls\DBAgentVoiceCalls;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Tests\Agents\Voice\Support\AgentVoiceCallFixtures;
use DDD\Tests\Agents\Voice\Support\AgentVoiceIntegrationTestCase;

/**
 * The read of the open calls ({@see DBAgentVoiceCalls::findOpenCalls()}) and the ring window of a row without a
 * deadline of its own, pinned without a database through the query the read hands to `find()`: the base reads the
 * configured ring length; an application that answers the window itself changes exactly that bound, and the query,
 * the answered-call bound and the deadline bound stay as they are.
 */
class DBAgentVoiceCallsOpenCallsRingWindowTest extends AgentVoiceIntegrationTestCase
{
    public const int RING_SECONDS = 45;
    public const int MAXIMUM_CALL_SECONDS = 600;

    protected string $alias;

    protected function setUp(): void
    {
        parent::setUp();
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith([
            'enabled' => true,
            'callStore' => 'entity',
            'limits' => ['ringSeconds' => static::RING_SECONDS, 'maximumCallSeconds' => static::MAXIMUM_CALL_SECONDS],
        ]));
        $this->alias = DBAgentVoiceCall::getBaseModelAlias();
    }

    public function testWithoutAnOverrideTheRingWindowIsTheConfiguredRingLength(): void
    {
        $queryBuilder = $this->findOpenCallsQuery(new QueryRecordingDBAgentVoiceCalls());

        static::assertSame($this->expectedOpenCallsDql(), $queryBuilder->getDQL());
        static::assertSame($this->formatted(AgentVoiceCallFixtures::NOW_UNIX_TIME - static::RING_SECONDS), $queryBuilder->getParameter('openCallRingFloor')->getValue());
        $this->assertTheOtherBoundsAreUnchanged($queryBuilder);
    }

    public function testAnOverrideChangesExactlyTheRingWindowOfARowWithoutADeadline(): void
    {
        $queryBuilder = $this->findOpenCallsQuery(new LongerRingQueryRecordingDBAgentVoiceCalls());

        static::assertSame($this->expectedOpenCallsDql(), $queryBuilder->getDQL(), 'the same query');
        static::assertSame(
            $this->formatted(AgentVoiceCallFixtures::NOW_UNIX_TIME - LongerRingQueryRecordingDBAgentVoiceCalls::LONGER_RING_SECONDS),
            $queryBuilder->getParameter('openCallRingFloor')->getValue()
        );
        $this->assertTheOtherBoundsAreUnchanged($queryBuilder);
    }

    protected function findOpenCallsQuery(QueryRecordingDBAgentVoiceCalls $callsRepository): DoctrineQueryBuilder
    {
        $callsRepository->findOpenCalls(AgentVoiceCallFixtures::NOW_UNIX_TIME, 20, 5);
        static::assertCount(1, $callsRepository->recordedQueries);
        static::assertFalse($callsRepository->recordedQueries[0]['useEntityRegistrCache']);

        return $callsRepository->recordedQueries[0]['queryBuilder'];
    }

    protected function assertTheOtherBoundsAreUnchanged(DoctrineQueryBuilder $queryBuilder): void
    {
        static::assertCount(4, $queryBuilder->getParameters());
        static::assertSame(5, $queryBuilder->getParameter('ownerScopeAccountId')->getValue());
        static::assertSame($this->formatted(AgentVoiceCallFixtures::NOW_UNIX_TIME - static::MAXIMUM_CALL_SECONDS), $queryBuilder->getParameter('openCallAnsweredFloor')->getValue());
        static::assertSame($this->formatted(AgentVoiceCallFixtures::NOW_UNIX_TIME), $queryBuilder->getParameter('openCallNow')->getValue());
        static::assertSame(20, $queryBuilder->getMaxResults());
    }

    protected function expectedOpenCallsDql(): string
    {
        $alias = $this->alias;

        return 'SELECT ' . $alias . ' FROM ' . DBAgentVoiceCallModel::class . ' ' . $alias
            . ' WHERE ' . $alias . '.accountId = :ownerScopeAccountId'
            . ' AND ' . $alias . '.endedAt IS NULL'
            . ' AND ((' . $alias . '.startedAt IS NOT NULL AND ' . $alias . '.startedAt >= :openCallAnsweredFloor)'
            . ' OR (' . $alias . '.startedAt IS NULL AND ' . $alias . '.ringDeadlineAt > :openCallNow)'
            . ' OR (' . $alias . '.startedAt IS NULL AND ' . $alias . '.ringDeadlineAt IS NULL AND ' . $alias . '.created >= :openCallRingFloor))'
            . ' ORDER BY ' . $alias . '.id DESC';
    }

    protected function formatted(int $unixTime): string
    {
        return date('Y-m-d H:i:s', $unixTime);
    }
}
