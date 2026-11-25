<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Voice;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent;
use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvents;
use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\TraceEvents\AgentVoiceCallTraceSummaries;
use DDD\Domain\AI\Entities\Voice\TraceEvents\AgentVoiceCallTraceSummary;
use DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * The trace events of voice calls: the event of one call step, keyed so that a writer that retries writes it once,
 * and the call headers of a conversation's calls with their latency numbers for the trace view. The agent core keeps
 * only the opaque `agentVoiceCallId` of a trace event; what a call's events mean is read here.
 */
class AgentVoiceCallTraceEventsService extends Service
{
    /**
     * Builds an event of a voice call. The key is `call:{callId}:{eventType}:{discriminator}`, so the same step of the
     * same call is written once however often its writer retries.
     *
     * @param AgentVoiceCall $agentVoiceCall
     * @param string $source One of the `AIConversationTraceEvent::SOURCE_*` constants
     * @param string $eventType
     * @param string $discriminator What keeps two events of one type on one call apart (a sequence, a provider id)
     * @param string|null $summary
     * @param array<string, mixed> $payload
     * @param int|null $durationMs
     * @param int|null $occurredAtUnixMs Defaults to now
     * @param int|null $receivedAtUnixMs
     */
    public function buildTraceEventForCall(
        AgentVoiceCall $agentVoiceCall,
        string $source,
        string $eventType,
        string $discriminator = '0',
        ?string $summary = null,
        array $payload = [],
        ?int $durationMs = null,
        ?int $occurredAtUnixMs = null,
        ?int $receivedAtUnixMs = null
    ): AIConversationTraceEvent {
        $agentVoiceCallId = $agentVoiceCall->id !== null ? (int)$agentVoiceCall->id : null;

        return AIConversationTraceEvent::create(
            source: $source,
            eventType: $eventType,
            eventKey: 'call:' . ($agentVoiceCallId ?? 'none') . ':' . $eventType . ':' . $discriminator,
            aiConversationId: $agentVoiceCall->aiConversationId !== null ? (int)$agentVoiceCall->aiConversationId : null,
            agentVoiceCallId: $agentVoiceCallId,
            occurredAtUnixMs: $occurredAtUnixMs,
            summary: $summary,
            payload: $payload,
            durationMs: $durationMs,
            receivedAtUnixMs: $receivedAtUnixMs
        );
    }

    /**
     * The call headers of a conversation's voice calls with their latency numbers and anomalies, computed from the
     * given trace events and the conversation's turn-end events. Oldest first; a conversation with more calls than the
     * list limit of the call store answers the headers of its newest calls.
     */
    public function buildVoiceCallTraceSummaries(AIConversation $conversation, ?AIConversationTraceEvents $traceEvents): AgentVoiceCallTraceSummaries
    {
        $callTraceSummaries = new AgentVoiceCallTraceSummaries();
        /** @var AgentVoiceCallsService $agentVoiceCallsService */
        $agentVoiceCallsService = DDDService::instance()->getService(AgentVoiceCallsService::class);
        $agentVoiceCalls = $agentVoiceCallsService->findCallsForConversation($conversation);
        if ($agentVoiceCalls->count() === 0) {
            return $callTraceSummaries;
        }
        $traceEventsByCallId = [];
        foreach ($traceEvents?->getElements() ?? [] as $traceEvent) {
            if ($traceEvent->agentVoiceCallId !== null) {
                $traceEventsByCallId[(int)$traceEvent->agentVoiceCallId][] = $traceEvent;
            }
        }
        $turnEndEvents = [];
        try {
            /** @var AIConversationTurnEventsService $aIConversationTurnEventsService */
            $aIConversationTurnEventsService = DDDService::instance()->getService(AIConversationTurnEventsService::class);
            $turnEndEvents = $aIConversationTurnEventsService->findForConversation((int)$conversation->id)
                ?->ofKind(AIConversationTurnEvent::KIND_TURN_END) ?? [];
        } catch (Throwable) {
            // Without the turn events the flag of a slow pickup is simply not computed.
        }
        // The application's subclass of the header, when it has one: its event types and override points decide.
        /** @var class-string<AgentVoiceCallTraceSummary> $callTraceSummaryClassName */
        $callTraceSummaryClassName = AgentVoiceCallTraceSummary::getApplicationClassNameOfValueObject(AgentVoiceCallTraceSummary::class);
        // The store answers the newest calls first; the headers read in the order the calls happened.
        foreach (array_reverse($agentVoiceCalls->getElements()) as $agentVoiceCall) {
            $callTraceSummary = $callTraceSummaryClassName::fromTraceEvents(
                $agentVoiceCall,
                $traceEventsByCallId[(int)$agentVoiceCall->id] ?? [],
                $turnEndEvents
            );
            $callTraceSummaries->add($callTraceSummary);
        }

        return $callTraceSummaries;
    }
}
