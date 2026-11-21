<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\AI;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleOneTimeMoment;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Libs\Config;
use DDD\Presentation\Mcp\Client\AI\AgentTaskSchedulesMCPPackage;
use DDD\Presentation\Mcp\Client\AI\Dtos\TaskScheduleDeleteMcpResponseDto;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The pure parts of the schedule tools: the timing rule (exactly one of runInMinutes / runAtLocalTime, a local time read
 * in the schedule's zone), the echo of the resolved moment in that zone, the scope filter of the reads, and the wording
 * of the archive-policy delete.
 */
class AgentTaskSchedulesMCPPackageNotesTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (Config::getEnv('LAZYLOAD_DEFAULT_REPO_TYPE') === null) {
            LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
        }
    }

    public function testNeitherTimingFieldIsRefused(): void
    {
        $this->expectException(BadRequestException::class);
        AIConversationScheduleOneTimeMoment::resolveFor(null, null, 'Europe/Berlin', new DateTime('2026-08-07 10:00:00'));
    }

    public function testZeroMinutesAndABlankLocalTimeCountAsNotSet(): void
    {
        $this->expectException(BadRequestException::class);
        AIConversationScheduleOneTimeMoment::resolveFor(0, '', 'Europe/Berlin', new DateTime('2026-08-07 10:00:00'));
    }

    public function testALocalTimeIsReadInTheZoneOfTheSchedule(): void
    {
        // 15:30 in Berlin (summer time, +02:00) is 13:30 UTC
        $moment = AIConversationScheduleOneTimeMoment::resolveFor(null, '2026-08-07 15:30', 'Europe/Berlin', new DateTime('2026-08-07 10:00:00'))->moment;

        self::assertSame(strtotime('2026-08-07 13:30:00 UTC'), $moment->getTimestamp());
    }

    public function testWhenBothAreSetTheLocalTimeWins(): void
    {
        $moment = AIConversationScheduleOneTimeMoment::resolveFor(10, '2026-08-07 15:30', 'Europe/Berlin', new DateTime('2026-08-07 10:00:00'))->moment;

        self::assertSame(strtotime('2026-08-07 13:30:00 UTC'), $moment->getTimestamp());
    }

    public function testTheEchoNamesTheLocalMomentAndItsDay(): void
    {
        $now = DateTime::fromTimestamp(strtotime('2026-08-07 10:00:00 UTC'));

        self::assertSame(
            'One-time task scheduled for 2026-08-07 15:30 local time — that is TODAY. ',
            AgentTaskSchedulesMCPPackage::composeOneTimeMomentNote(DateTime::fromTimestamp(strtotime('2026-08-07 13:30:00 UTC')), 'Europe/Berlin', $now)
        );
        self::assertSame(
            'One-time task scheduled for 2026-08-08 09:00 local time — that is TOMORROW. ',
            AgentTaskSchedulesMCPPackage::composeOneTimeMomentNote(DateTime::fromTimestamp(strtotime('2026-08-08 07:00:00 UTC')), 'Europe/Berlin', $now)
        );
        self::assertSame(
            'One-time task scheduled for 2026-08-10 09:00 local time — that is Monday. ',
            AgentTaskSchedulesMCPPackage::composeOneTimeMomentNote(DateTime::fromTimestamp(strtotime('2026-08-10 07:00:00 UTC')), 'Europe/Berlin', $now)
        );
    }

    public function testTheEchoCountsTheDayInTheZoneOfTheSchedule(): void
    {
        // 23:30 UTC on the 7th is already the 8th in Berlin: "today" there
        $now = DateTime::fromTimestamp(strtotime('2026-08-07 22:30:00 UTC'));

        self::assertSame(
            'One-time task scheduled for 2026-08-08 08:00 local time — that is TODAY. ',
            AgentTaskSchedulesMCPPackage::composeOneTimeMomentNote(DateTime::fromTimestamp(strtotime('2026-08-08 06:00:00 UTC')), 'Europe/Berlin', $now)
        );
    }

    public function testTheScopeIsTheSpaceElseTheAccountsSchedulesWithoutASpace(): void
    {
        $account = new Account();
        $account->id = 581;
        $agentSpace = new AgentSpace();
        $agentSpace->id = 17;

        self::assertSame("agentSpaceId eq '17'", AgentTaskSchedulesMCPPackage::buildScopeFilterString($agentSpace, $account));
        self::assertSame("agentSpaceId eq 'NULL' and accountId eq '581'", AgentTaskSchedulesMCPPackage::buildScopeFilterString(null, $account));
    }

    public function testTheDeleteNamesWhatHappened(): void
    {
        self::assertSame(
            TaskScheduleDeleteMcpResponseDto::NOTE_HARD_DELETE,
            TaskScheduleDeleteMcpResponseDto::forActionTaken(AIConversationSchedule::DELETE_ACTION_HARD_DELETE)->note
        );
        self::assertSame(
            TaskScheduleDeleteMcpResponseDto::NOTE_ARCHIVE,
            TaskScheduleDeleteMcpResponseDto::forActionTaken(AIConversationSchedule::DELETE_ACTION_ARCHIVE)->note
        );
        $keptHistory = TaskScheduleDeleteMcpResponseDto::forActionTaken(AIConversationSchedule::DELETE_ACTION_KEEP_HISTORY);
        self::assertSame(TaskScheduleDeleteMcpResponseDto::NOTE_KEEP_HISTORY, $keptHistory->note);
        self::assertSame(AIConversationSchedule::DELETE_ACTION_KEEP_HISTORY, $keptHistory->actionTaken);
    }
}
