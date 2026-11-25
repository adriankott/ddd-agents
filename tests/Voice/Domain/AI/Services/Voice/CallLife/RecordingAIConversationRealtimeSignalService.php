<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\CallLife;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\A2A\AIConversationTaskPushNotificationConfigMemo;
use DDD\Domain\AI\Services\Conversations\AIConversationRealtimeSignalService;

/** The change signal of the package, recording the conversation and the status of each signal. */
class RecordingAIConversationRealtimeSignalService extends AIConversationRealtimeSignalService
{
    /** @var array<int, array{conversationId: int, runStatus: string}> */
    public array $publishedSignals = [];

    public function publishChangeSignal(AIConversation $conversation, string $runStatus, ?AIConversationTaskPushNotificationConfigMemo $pushConfigMemo = null): void
    {
        $this->publishedSignals[] = ['conversationId' => (int)$conversation->id, 'runStatus' => $runStatus];
    }
}
