<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleOccurrenceSpawn;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleStatus;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Support\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The paths of the fire command before and around the spawn: a row that is gone or no longer active, a schedule that
 * expired, a claim that was lost, a spawn that throws, and a one-time schedule that is late. How a skipped occurrence
 * is counted and printed is in {@see AgentFireDueSchedulesCommandTest}.
 */
class AgentFireDueSchedulesCommandPathsTest extends UnitTestCase
{
    protected const string TALLY_OF_A_PASS_THAT_DID_NOTHING = 'Schedule firing: 0 fired, 0 skipped (previous run in progress), 0 skipped (budget pot exhausted),'
        . ' 0 skipped (agent space/account not eligible), 0 skipped (other reason), 0 closed (no agent space), 0 expired, 0 claim-lost, 0 failed.';

    public function testARowThatIsGoneOrNoLongerActiveIsPassedOverWithoutALine(): void
    {
        $schedulesServiceDouble = new SchedulesServiceWithScriptedFirePathsDouble();
        $schedulesServiceDouble->dueScheduleIdsWithoutARow = [1];
        // paused between the scan and the load
        $schedulesServiceDouble->dueSchedulesById[2] = static::buildDueSchedule(2, AIConversationScheduleStatus::PAUSED);
        $schedulesServiceDouble->dueSchedulesById[3] = static::buildDueSchedule(3, AIConversationScheduleStatus::CANCELLED);

        $commandTester = new CommandTester(new AgentFireDueSchedulesProbeCommand($schedulesServiceDouble));
        $exitCode = $commandTester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(static::TALLY_OF_A_PASS_THAT_DID_NOTHING, trim($commandTester->getDisplay()));
        self::assertSame([], $schedulesServiceDouble->claimedScheduleIds);
        self::assertSame([], $schedulesServiceDouble->recordedStatusTransitions);
    }

    #[DataProvider('provideExpiredSchedules')]
    public function testAnExpiredScheduleIsTransitionedAndItsOccurrenceNeverClaimed(?string $ttlAt, ?int $maxIterations, int $iterationsUsed): void
    {
        $schedulesServiceDouble = new SchedulesServiceWithScriptedFirePathsDouble();
        $schedulesServiceDouble->dueSchedulesById[4] = static::buildDueScheduleWithExpiry(4, $ttlAt, $maxIterations, $iterationsUsed);

        $commandTester = new CommandTester(new AgentFireDueSchedulesProbeCommand($schedulesServiceDouble));
        $commandTester->execute([]);
        $display = $commandTester->getDisplay();

        self::assertStringContainsString('Expired: schedule 4 passed its ttl/maxIterations — transitioned to EXPIRED', $display);
        self::assertStringContainsString('0 fired,', $display);
        self::assertStringContainsString(' 1 expired, 0 claim-lost, 0 failed.', $display);
        self::assertSame(['4:' . AIConversationScheduleStatus::EXPIRED], $schedulesServiceDouble->recordedStatusTransitions);
        self::assertSame([], $schedulesServiceDouble->claimedScheduleIds);
        self::assertSame([], $schedulesServiceDouble->spawnedScheduleIds);
    }

    /**
     * @return array<string, array{0: string|null, 1: int|null, 2: int}>
     */
    public static function provideExpiredSchedules(): array
    {
        return [
            'its ttl passed' => ['2020-01-01 00:00:00', null, 0],
            'it used the runs it was given' => [null, 3, 3],
        ];
    }

    public function testADryRunWritesNoExpiryAndSaysItWouldExpire(): void
    {
        $schedulesServiceDouble = new SchedulesServiceWithScriptedFirePathsDouble();
        $schedulesServiceDouble->dueSchedulesById[4] = static::buildDueScheduleWithExpiry(4, '2020-01-01 00:00:00', null, 0);

        $commandTester = new CommandTester(new AgentFireDueSchedulesProbeCommand($schedulesServiceDouble));
        $commandTester->execute(['--dry-run' => true]);

        $display = $commandTester->getDisplay();
        self::assertSame([], $schedulesServiceDouble->recordedStatusTransitions);
        self::assertSame([], $schedulesServiceDouble->claimedScheduleIds);
        // the dry-run wording, as a due schedule's "would fire"; counted as nothing, as nothing was written
        self::assertStringContainsString('Expired (dry-run): schedule 4 passed its ttl/maxIterations — would expire', $display);
        self::assertStringNotContainsString('transitioned to EXPIRED', $display);
        self::assertStringNotContainsString('would fire', $display);
        self::assertStringContainsString(' 0 expired, 0 claim-lost, 0 failed.', $display);
    }

    public function testALostClaimIsCountedAndStartsNoRun(): void
    {
        $schedulesServiceDouble = new SchedulesServiceWithScriptedFirePathsDouble();
        $schedulesServiceDouble->dueSchedulesById[5] = static::buildDueSchedule(5, AIConversationScheduleStatus::ACTIVE);
        $schedulesServiceDouble->scheduleIdsWhoseClaimIsLost = [5];

        $commandTester = new CommandTester(new AgentFireDueSchedulesProbeCommand($schedulesServiceDouble));
        $commandTester->execute([]);
        $display = $commandTester->getDisplay();

        self::assertStringContainsString(' 0 expired, 1 claim-lost, 0 failed.', $display);
        self::assertStringNotContainsString('schedule 5', $display);
        self::assertSame([], $schedulesServiceDouble->spawnedScheduleIds);
    }

    public function testASpawnThatThrowsIsCountedAsFailedAndThePassGoesOn(): void
    {
        $schedulesServiceDouble = new SchedulesServiceWithScriptedFirePathsDouble();
        $schedulesServiceDouble->dueSchedulesById[6] = static::buildDueSchedule(6, AIConversationScheduleStatus::ACTIVE);
        $schedulesServiceDouble->scheduleIdsWhoseSpawnThrows = [6];
        $schedulesServiceDouble->dueSchedulesById[7] = static::buildDueSchedule(7, AIConversationScheduleStatus::ACTIVE);
        $schedulesServiceDouble->occurrenceSpawnsByScheduleId[7] = static::buildSpawnedRun(99);

        $commandTester = new CommandTester(new AgentFireDueSchedulesProbeCommand($schedulesServiceDouble));
        $exitCode = $commandTester->execute([]);
        $display = $commandTester->getDisplay();

        // one schedule that fails does not fail the pass
        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Failed: schedule 6: ' . SchedulesServiceWithScriptedFirePathsDouble::SPAWN_FAILURE_MESSAGE, $display);
        self::assertStringContainsString('Fired: schedule 7 spawned run conversation 99', $display);
        self::assertStringContainsString('Schedule firing: 1 fired,', $display);
        self::assertStringContainsString(' 0 claim-lost, 1 failed.', $display);
        self::assertSame([6, 7], $schedulesServiceDouble->spawnedScheduleIds);
    }

    public function testAOneTimeScheduleThatIsLateFiresAndOneThatRanIsExpired(): void
    {
        $schedulesServiceDouble = new SchedulesServiceWithScriptedFirePathsDouble();
        // its moment passed long ago and it never ran: late, not expired
        $neverRan = static::buildDueScheduleWithExpiry(8, null, 1, 0);
        $neverRan->oneTimeAt = new DateTime('2026-01-01 10:00:00');
        $schedulesServiceDouble->dueSchedulesById[8] = $neverRan;
        $schedulesServiceDouble->occurrenceSpawnsByScheduleId[8] = static::buildSpawnedRun(98);
        $ranOnce = static::buildDueScheduleWithExpiry(9, null, 1, 1);
        $ranOnce->oneTimeAt = new DateTime('2026-01-01 10:00:00');
        $schedulesServiceDouble->dueSchedulesById[9] = $ranOnce;

        $commandTester = new CommandTester(new AgentFireDueSchedulesProbeCommand($schedulesServiceDouble));
        $commandTester->execute([]);
        $display = $commandTester->getDisplay();

        self::assertStringContainsString('Fired: schedule 8 spawned run conversation 98', $display);
        self::assertStringContainsString('Expired: schedule 9 passed its ttl/maxIterations', $display);
        self::assertSame([8], $schedulesServiceDouble->claimedScheduleIds);
        self::assertSame(['9:' . AIConversationScheduleStatus::EXPIRED], $schedulesServiceDouble->recordedStatusTransitions);
    }

    protected static function buildDueSchedule(int $scheduleId, string $status): AIConversationSchedule
    {
        $schedule = new AIConversationSchedule();
        $schedule->id = $scheduleId;
        $schedule->accountId = 5;
        $schedule->status = $status;
        return $schedule;
    }

    protected static function buildDueScheduleWithExpiry(int $scheduleId, ?string $ttlAt, ?int $maxIterations, int $iterationsUsed): AIConversationSchedule
    {
        $schedule = static::buildDueSchedule($scheduleId, AIConversationScheduleStatus::ACTIVE);
        if ($ttlAt !== null) {
            $schedule->ttlAt = new DateTime($ttlAt);
        }
        if ($maxIterations !== null) {
            $schedule->maxIterations = $maxIterations;
        }
        $schedule->iterationsUsed = $iterationsUsed;
        return $schedule;
    }

    protected static function buildSpawnedRun(int $runConversationId): AIConversationScheduleOccurrenceSpawn
    {
        $runConversation = new AIConversation();
        $runConversation->id = $runConversationId;
        return AIConversationScheduleOccurrenceSpawn::spawned($runConversation);
    }
}
