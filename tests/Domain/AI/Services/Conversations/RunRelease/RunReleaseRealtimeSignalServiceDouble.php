<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\RunRelease;

use DDD\Domain\AI\Entities\Conversations\A2A\AIConversationTaskPushNotificationConfigMemo;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\AIConversationRealtimeSignalService;

/** The realtime status of the release stage: every published status is recorded, nothing is sent. */
class RunReleaseRealtimeSignalServiceDouble extends AIConversationRealtimeSignalService
{
    public RunReleaseEventLog $eventLog;

    public function __construct(RunReleaseEventLog $eventLog)
    {
        // The parent service is container-managed and holds no constructor state.
        $this->eventLog = $eventLog;
    }

    public function publishChangeSignal(AIConversation $conversation, string $runStatus, ?AIConversationTaskPushNotificationConfigMemo $pushConfigMemo = null): void
    {
        $this->eventLog->record('realtime ' . $runStatus);
    }
}
