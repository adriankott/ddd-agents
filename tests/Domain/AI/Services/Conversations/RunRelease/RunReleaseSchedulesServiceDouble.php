<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\RunRelease;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;

/** The schedules of the release stage: the run-terminal record of a conversation is recorded, nothing is read. */
class RunReleaseSchedulesServiceDouble extends AIConversationSchedulesService
{
    public RunReleaseEventLog $eventLog;

    public function __construct(RunReleaseEventLog $eventLog)
    {
        // The parent service is container-managed and holds no constructor state.
        $this->eventLog = $eventLog;
    }

    public function recordRunTerminalOfConversation(AIConversation $conversation, bool $runCompleted): void
    {
        $this->eventLog->record('scheduleRunTerminal runCompleted=' . ($runCompleted ? 'yes' : 'no'));
    }
}
