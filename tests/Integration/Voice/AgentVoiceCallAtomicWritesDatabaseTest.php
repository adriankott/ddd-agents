<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Voice;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCalls;
use DDD\Domain\AI\Repo\DB\Voice\Calls\DBAgentVoiceCallModel;
use DDD\Domain\AI\Repo\DB\Voice\Calls\DBAgentVoiceCalls;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Tests\Agents\Support\DatabaseTestCase;

/**
 * The three atomic writes of the call store on MariaDB and PostgreSQL: the answer claim of a ringing call, the end
 * claim of a call nobody answered, and the relative booking of seconds. Each is ONE conditional or relative UPDATE, so
 * exactly one of several callers wins a claim and two bookings add up; the quoted table and column names hold on
 * both engines.
 */
class AgentVoiceCallAtomicWritesDatabaseTest extends DatabaseTestCase
{
    public const int ACCOUNT_ID = 811;

    public const int NOW_UNIX_TIME = 1795000000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::ACCOUNT_ID, 'email' => 'voice@example.test']);
    }

    public function testExactlyOneCallerWinsTheAnswerOfARingingCall(): void
    {
        $this->insertCallRow(1, ['ringDeadlineAt' => $this->formatUnixTime(self::NOW_UNIX_TIME + 30)]);
        $callsRepository = $this->getCallsRepository();

        self::assertTrue($callsRepository->claimRingingCallAnswer(1, self::NOW_UNIX_TIME));
        self::assertFalse($callsRepository->claimRingingCallAnswer(1, self::NOW_UNIX_TIME + 1), 'an answered call is not answered twice');
        self::assertSame($this->formatUnixTime(self::NOW_UNIX_TIME), $this->readDateTimeColumn(1, 'startedAt'));
    }

    public function testTheAnswerClaimIsRefusedForAnEndedCallAndPastTheRingDeadline(): void
    {
        $this->insertCallRow(1, ['endedAt' => $this->formatUnixTime(self::NOW_UNIX_TIME - 5), 'endReason' => 'DECLINED']);
        $this->insertCallRow(2, ['ringDeadlineAt' => $this->formatUnixTime(self::NOW_UNIX_TIME)]);
        $callsRepository = $this->getCallsRepository();

        self::assertFalse($callsRepository->claimRingingCallAnswer(1, self::NOW_UNIX_TIME), 'an ended call');
        self::assertFalse($callsRepository->claimRingingCallAnswer(2, self::NOW_UNIX_TIME), 'the deadline itself has passed');
        self::assertNull($this->readDateTimeColumn(1, 'startedAt'));
        self::assertNull($this->readDateTimeColumn(2, 'startedAt'));
    }

    public function testACallWithoutARingDeadlineCanBeAnswered(): void
    {
        $this->insertCallRow(1, []);

        self::assertTrue($this->getCallsRepository()->claimRingingCallAnswer(1, self::NOW_UNIX_TIME));
    }

    public function testExactlyOneCallerEndsACallNobodyAnswered(): void
    {
        $this->insertCallRow(1, ['ringDeadlineAt' => $this->formatUnixTime(self::NOW_UNIX_TIME - 1)]);
        $callsRepository = $this->getCallsRepository();

        self::assertTrue($callsRepository->claimUnansweredCallEnd(1, 'RING_EXPIRED', self::NOW_UNIX_TIME));
        self::assertFalse($callsRepository->claimUnansweredCallEnd(1, 'DECLINED', self::NOW_UNIX_TIME + 1), 'the second end loses');
        $storedRow = $this->findStoredRow(DBAgentVoiceCallModel::getTableName(), 1);
        self::assertSame('RING_EXPIRED', $storedRow['endReason']);
        self::assertSame($this->formatUnixTime(self::NOW_UNIX_TIME), $this->readDateTimeColumn(1, 'endedAt'));
    }

    public function testTheEndClaimIsRefusedOnceTheCallWasAnswered(): void
    {
        $this->insertCallRow(1, ['startedAt' => $this->formatUnixTime(self::NOW_UNIX_TIME - 10)]);

        self::assertFalse($this->getCallsRepository()->claimUnansweredCallEnd(1, 'WITHDRAWN', self::NOW_UNIX_TIME));
        self::assertNull($this->readDateTimeColumn(1, 'endedAt'));
    }

    public function testTwoBookingsAddUpAndNoOtherColumnChanges(): void
    {
        $this->insertCallRow(1, ['startedAt' => $this->formatUnixTime(self::NOW_UNIX_TIME - 60), 'endReason' => null]);
        $this->insertCallRow(2, []);
        $storedRowBefore = $this->findStoredRow(DBAgentVoiceCallModel::getTableName(), 1);
        $callsRepository = $this->getCallsRepository();

        $callsRepository->addBookedSeconds(1, 30);
        $callsRepository->addBookedSeconds(1, 12);

        $storedRowAfter = $this->findStoredRow(DBAgentVoiceCallModel::getTableName(), 1);
        self::assertSame(42, (int)$storedRowAfter['bookedSeconds']);
        unset($storedRowBefore['bookedSeconds'], $storedRowAfter['bookedSeconds']);
        self::assertSame($storedRowBefore, $storedRowAfter);
        self::assertSame(0, (int)$this->findStoredRow(DBAgentVoiceCallModel::getTableName(), 2)['bookedSeconds'], 'another call is untouched');
    }

    /**
     * @param array<string, mixed> $columnValues the columns beyond the defaults of a ringing call of the account
     */
    protected function insertCallRow(int $callId, array $columnValues): void
    {
        $this->insertRow(DBAgentVoiceCallModel::getTableName(), array_merge([
            'id' => $callId,
            'accountId' => self::ACCOUNT_ID,
            'voiceProvider' => 'test',
            'direction' => 'INBOUND',
            'bookedSeconds' => 0,
        ], $columnValues));
    }

    protected function readDateTimeColumn(int $callId, string $columnName): ?string
    {
        $value = $this->findStoredRow(DBAgentVoiceCallModel::getTableName(), $callId)[$columnName] ?? null;

        return $value === null ? null : substr((string)$value, 0, 19);
    }

    protected function formatUnixTime(int $unixTime): string
    {
        return date('Y-m-d H:i:s', $unixTime);
    }

    protected function getCallsRepository(): DBAgentVoiceCalls
    {
        /** @var DBAgentVoiceCalls $callsRepository */
        $callsRepository = AgentVoiceCalls::getRepoClassInstance(LazyLoadRepo::DB);

        return $callsRepository;
    }
}
