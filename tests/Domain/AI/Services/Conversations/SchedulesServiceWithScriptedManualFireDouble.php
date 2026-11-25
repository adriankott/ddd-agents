<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleOccurrenceSpawn;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;

/**
 * The schedules service for a fire by hand: the claim of the occurrence and the spawn of its run are scripted and
 * recorded in order; the anchor the schedule carries at the moment of the claim is kept.
 */
class SchedulesServiceWithScriptedManualFireDouble extends AIConversationSchedulesService
{
    public const int STARTED_RUN_CONVERSATION_ID = 881777;

    /** @var bool Whether the claim is won; false stands for a concurrent fire or a pause that took the occurrence */
    public bool $fixtureClaimIsWon = true;

    /** @var string|null The reason the spawn skips the occurrence for; null: it starts a run */
    public ?string $fixtureSpawnSkipReason = null;

    /** @var string[] `claim` and `spawn`, in the order they were asked */
    public array $recordedCalls = [];

    /** @var int|null The fire anchor of the schedule at the moment of the claim, as a timestamp */
    public ?int $anchorAtTheClaim = null;

    public function claimFireOccurrence(AIConversationSchedule $schedule): bool
    {
        $this->recordedCalls[] = 'claim';
        $this->anchorAtTheClaim = isset($schedule->nextRunAt) ? $schedule->nextRunAt->getTimestamp() : null;
        return $this->fixtureClaimIsWon;
    }

    public function spawnRunConversationForClaimedSchedule(AIConversationSchedule $schedule): AIConversationScheduleOccurrenceSpawn
    {
        $this->recordedCalls[] = 'spawn';
        if ($this->fixtureSpawnSkipReason !== null) {
            return AIConversationScheduleOccurrenceSpawn::skipped($this->fixtureSpawnSkipReason);
        }
        $runConversation = new AIConversation();
        $runConversation->id = static::STARTED_RUN_CONVERSATION_ID;
        return AIConversationScheduleOccurrenceSpawn::spawned($runConversation);
    }
}
