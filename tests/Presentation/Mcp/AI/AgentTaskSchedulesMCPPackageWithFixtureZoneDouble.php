<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\AI;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpaceTimezone;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Presentation\Mcp\Client\Agents\AgentTaskSchedulesMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\Dtos\TaskScheduleUpdateRequestDto;

/** The task package with the zone of the schedule's space given, its timing edits and scope check opened for the test */
class AgentTaskSchedulesMCPPackageWithFixtureZoneDouble extends AgentTaskSchedulesMCPPackage
{
    public ?string $fixtureTimezoneOfScope = 'Europe/Berlin';

    /** @return string[] */
    public function applyTimingEditsToScheduleForTest(AIConversationSchedule $schedule, TaskScheduleUpdateRequestDto $requestDto, DateTime $now): array
    {
        return $this->applyTimingEditsToSchedule($schedule, $requestDto, $now);
    }

    public function findScheduleByIdForScopeCheckForTest(int $scheduleId, AIConversationSchedulesService $aIConversationSchedulesService): ?AIConversationSchedule
    {
        return $this->findScheduleByIdForScopeCheck($scheduleId, $aIConversationSchedulesService);
    }

    public function isScheduleInScopeOfAccountForTest(AIConversationSchedule $schedule, Account $actingAccount): bool
    {
        return $this->isScheduleInScopeOfAccount($schedule, $actingAccount);
    }

    protected function findTimezoneOfScopeForScheduleWithoutZone(AIConversationSchedule $schedule): ?AgentSpaceTimezone
    {
        return $this->fixtureTimezoneOfScope !== null ? AgentSpaceTimezone::ofKnownIdentifier($this->fixtureTimezoneOfScope, false) : null;
    }
}
