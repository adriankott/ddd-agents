<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent;
use DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService;

/**
 * The turn-events table of the decision sites, recorded instead of written: the real `recordEventOfConversation()`
 * builds the event and hands it to {@see recordEvent()}, which logs `event <kind> <site>[ <subject>] decidedBy=<json>`
 * (the subject left out when it is null or a generated request id). The "already decided this turn" read
 * ({@see hasEventFor()}) answers from {@see $existingDecisionEvents}; a catalog snapshot is stored nowhere.
 */
class RecordingTurnEventsServiceDouble extends AIConversationTurnEventsService
{
    public const string CATALOG_SNAPSHOT_HASH = 'test-catalog-snapshot';

    /** @var AIConversationTurnEvent[] every recorded event, in call order */
    public array $recordedEvents = [];

    /** @var string[] `site@initiatorIndex` of the decision events that exist already */
    public array $existingDecisionEvents = [];

    /** @var string[] every `hasEventFor` read, `kind site@initiatorIndex`, in call order */
    public array $hasEventForReads = [];

    public function __construct(public DecisionSiteWorldLog $worldLog)
    {
    }

    public function recordEvent(AIConversationTurnEvent $event, ?string $runToken = null): bool
    {
        $this->recordedEvents[] = $event;
        $payload = json_decode((string)($event->payload ?? 'null'), true);
        $decidedBy = is_array($payload) && array_key_exists('decidedBy', $payload) ? json_encode($payload['decidedBy']) : '-';
        $subject = (string)($event->subject ?? '');
        $subjectPart = $subject === '' || preg_match('/^[0-9a-f]{16}$/', $subject) === 1 ? '' : ' ' . $subject;
        $this->worldLog->record('event ' . ($event->kind ?? 'null') . ' ' . ($event->site ?? 'null') . $subjectPart . ' decidedBy=' . $decidedBy);
        return true;
    }

    public function updateEventPayload(AIConversationTurnEvent $event): void
    {
        $this->worldLog->record('event payload update ' . ($event->site ?? 'null'));
    }

    public function hasEventFor(int $conversationId, int $initiatorMessageIndex, string $kind, string $site): bool
    {
        $this->hasEventForReads[] = $kind . ' ' . $site . '@' . $initiatorMessageIndex;
        return in_array($site . '@' . $initiatorMessageIndex, $this->existingDecisionEvents, true);
    }

    public function storeCatalogSnapshot(array $snapshot): string
    {
        return self::CATALOG_SNAPSHOT_HASH;
    }
}
