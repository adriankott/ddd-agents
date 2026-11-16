<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\DoneCriterionRecheck;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService;

/** The turn-events service that keeps every event it is asked to record instead of writing it. */
class RecordingTurnEventsService extends AIConversationTurnEventsService
{
    /** @var array<int, array{kind: string, subject: ?string, payload: array<string, mixed>}> */
    public array $recordedEvents = [];

    public function recordEventOfConversation(
        AIConversation $conversation,
        string $kind,
        ?string $site,
        ?int $initiatorMessageIndex,
        ?string $subject,
        ?string $requestId,
        array $payload,
        ?string $runToken = null,
        ?int $elapsedMs = null,
        ?string $arm = null,
        ?string $subjectFallback = null
    ): void {
        $this->recordedEvents[] = ['kind' => $kind, 'subject' => $subject, 'payload' => $payload];
    }
}
