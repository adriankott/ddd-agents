<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\RunRelease;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService;

/** The turn events of the release stage: the turn-end event is recorded with its outcome; the chunk event is not written. */
class RunReleaseTurnEventsServiceDouble extends AIConversationTurnEventsService
{
    public RunReleaseEventLog $eventLog;

    public function __construct(RunReleaseEventLog $eventLog)
    {
        // The parent service is container-managed and holds no constructor state.
        $this->eventLog = $eventLog;
    }

    public function recordTurnEndEventOfRun(AgentRun $agentRun, AIConversation $conversation, ?string $runToken, string $outcome, bool $recordedLate = false): void
    {
        $this->eventLog->record('turnEnd ' . $outcome . ($recordedLate ? ' (late)' : ''));
    }

    public function recordChunkEventOfConversation(AIConversation $conversation, ?string $runToken): void
    {
    }
}
