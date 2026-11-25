<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Entities\Voice\TraceEvents;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent;
use DDD\Domain\AI\Entities\Conversations\TraceEvents\Summaries\AIConversationTraceAnomaly;
use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\TraceEvents\AgentVoiceCallTraceSummaries;
use DDD\Domain\AI\Entities\Voice\TraceEvents\AgentVoiceCallTraceSummary;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Voice\Support\AgentVoiceCallFixtures;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;

/**
 * The call header of one call in the trace view ({@see AgentVoiceCallTraceSummary::fromTraceEvents()}): every number
 * from its events and null without them, the speech rhythm, the delegation round trips and the anomaly flags. The
 * events carry fixed Unix milliseconds.
 */
class AgentVoiceCallTraceSummaryTest extends AgentVoiceUnitTestCase
{
    protected const int T = 1_800_000_000_000;

    protected int $eventSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->declareAgentPersonaConfiguration(['agentName' => '']);
    }

    public function testWithoutEventsEveryNumberIsNullAndTheIdentityIsTheCalls(): void
    {
        $agentVoiceCall = $this->buildCall();
        $agentVoiceCall->direction = AgentVoiceCall::DIRECTION_OUTBOUND;
        $agentVoiceCall->voiceProvider = 'openAiLive';
        $agentVoiceCall->endReason = AgentVoiceCall::END_REASON_COMPLETED;

        $callTraceSummary = AgentVoiceCallTraceSummary::fromTraceEvents($agentVoiceCall, []);

        static::assertSame(7, $callTraceSummary->agentVoiceCallId);
        static::assertSame('OUTBOUND', $callTraceSummary->direction);
        static::assertSame('APP', $callTraceSummary->channelType);
        static::assertSame('openAiLive', $callTraceSummary->voiceProvider);
        static::assertSame('COMPLETED', $callTraceSummary->endReason);
        static::assertSame(300, $callTraceSummary->durationSeconds);
        foreach (['ringToAnswerMs', 'answerRequestMs', 'openRequestMs', 'briefingMs', 'providerSessionMs', 'workerAttachAfterOpenMs', 'firstAgentWordAfterOpenMs', 'deviceAnswerAppliedToFirstAudioMs', 'deviceTapToFirstAudioMs', 'medianTurnGapMs'] as $numberName) {
            static::assertNull($callTraceSummary->$numberName, $numberName);
        }
        static::assertSame([], $callTraceSummary->delegationRoundTripsMs);
        static::assertFalse($callTraceSummary->traceComplete);
        static::assertSame(0, $callTraceSummary->traceEventCount);
        static::assertSame(0, $callTraceSummary->anomalies->count());
    }

    public function testACallThatWasNeverAnsweredHasNoDuration(): void
    {
        $agentVoiceCall = AgentVoiceCallFixtures::buildCallAt(AgentVoiceCallFixtures::NOW_UNIX_TIME, -100, -10, null, -10);

        static::assertNull(AgentVoiceCallTraceSummary::fromTraceEvents($agentVoiceCall, [])->durationSeconds);
    }

    public function testAnEndBeforeTheAnswerIsNoNegativeDuration(): void
    {
        $agentVoiceCall = $this->buildCall();
        $agentVoiceCall->endedAt = DateTime::fromTimestamp($agentVoiceCall->startedAt->getTimestamp() - 5);

        static::assertSame(0, AgentVoiceCallTraceSummary::fromTraceEvents($agentVoiceCall, [])->durationSeconds);
    }

    public function testTheStoredEventNames(): void
    {
        static::assertSame(
            [
                'call.ring_started', 'call.answer_claimed', 'call.open_answered', 'briefing.composed', 'provider.session_created',
                'worker.attached', 'agent.first_transcript', 'device.answer_applied', 'device.first_audio_played', 'device.call_tapped',
            ],
            [
                AgentVoiceCallTraceSummary::EVENT_TYPE_RING_STARTED, AgentVoiceCallTraceSummary::EVENT_TYPE_ANSWER_CLAIMED,
                AgentVoiceCallTraceSummary::EVENT_TYPE_OPEN_ANSWERED, AgentVoiceCallTraceSummary::EVENT_TYPE_BRIEFING_COMPOSED,
                AgentVoiceCallTraceSummary::EVENT_TYPE_PROVIDER_SESSION_CREATED, AgentVoiceCallTraceSummary::EVENT_TYPE_WORKER_ATTACHED,
                AgentVoiceCallTraceSummary::EVENT_TYPE_FIRST_AGENT_TRANSCRIPT, AgentVoiceCallTraceSummary::EVENT_TYPE_DEVICE_ANSWER_APPLIED,
                AgentVoiceCallTraceSummary::EVENT_TYPE_DEVICE_FIRST_AUDIO_PLAYED, AgentVoiceCallTraceSummary::EVENT_TYPE_DEVICE_CALL_TAPPED,
            ]
        );
    }

    public function testEveryLatencyIsReadFromItsEvents(): void
    {
        $callTraceSummary = AgentVoiceCallTraceSummary::fromTraceEvents($this->buildCall(), [
            $this->buildEvent(AgentVoiceCallTraceSummary::EVENT_TYPE_RING_STARTED, 0),
            $this->buildEvent(AgentVoiceCallTraceSummary::EVENT_TYPE_ANSWER_CLAIMED, 4_000, durationMs: 120),
            $this->buildEvent(AgentVoiceCallTraceSummary::EVENT_TYPE_BRIEFING_COMPOSED, 4_500, durationMs: 300),
            $this->buildEvent(AgentVoiceCallTraceSummary::EVENT_TYPE_PROVIDER_SESSION_CREATED, 5_000, durationMs: 700),
            $this->buildEvent(AgentVoiceCallTraceSummary::EVENT_TYPE_OPEN_ANSWERED, 5_200, durationMs: 1_100),
            $this->buildEvent(AgentVoiceCallTraceSummary::EVENT_TYPE_WORKER_ATTACHED, 5_900),
            $this->buildEvent(AgentVoiceCallTraceSummary::EVENT_TYPE_FIRST_AGENT_TRANSCRIPT, 6_700),
            $this->buildEvent(AgentVoiceCallTraceSummary::EVENT_TYPE_DEVICE_CALL_TAPPED, 3_900),
            $this->buildEvent(AgentVoiceCallTraceSummary::EVENT_TYPE_DEVICE_ANSWER_APPLIED, 5_400),
            $this->buildEvent(AgentVoiceCallTraceSummary::EVENT_TYPE_DEVICE_FIRST_AUDIO_PLAYED, 6_900),
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TRACE_FLUSHED, 300_000),
        ]);

        static::assertSame(4_000, $callTraceSummary->ringToAnswerMs);
        static::assertSame(120, $callTraceSummary->answerRequestMs);
        static::assertSame(1_100, $callTraceSummary->openRequestMs);
        static::assertSame(300, $callTraceSummary->briefingMs);
        static::assertSame(700, $callTraceSummary->providerSessionMs);
        static::assertSame(700, $callTraceSummary->workerAttachAfterOpenMs);
        static::assertSame(1_500, $callTraceSummary->firstAgentWordAfterOpenMs);
        static::assertSame(1_500, $callTraceSummary->deviceAnswerAppliedToFirstAudioMs);
        static::assertSame(3_000, $callTraceSummary->deviceTapToFirstAudioMs);
        static::assertTrue($callTraceSummary->traceComplete);
        static::assertSame(11, $callTraceSummary->traceEventCount);
        static::assertSame(0, $callTraceSummary->anomalies->count());
    }

    public function testTheFirstEventOfATypeCountsAndAWordWithoutOpenCountsFromTheAttach(): void
    {
        $callTraceSummary = AgentVoiceCallTraceSummary::fromTraceEvents($this->buildCall(), [
            $this->buildEvent(AgentVoiceCallTraceSummary::EVENT_TYPE_WORKER_ATTACHED, 1_000),
            $this->buildEvent(AgentVoiceCallTraceSummary::EVENT_TYPE_WORKER_ATTACHED, 2_000),
            $this->buildEvent(AgentVoiceCallTraceSummary::EVENT_TYPE_FIRST_AGENT_TRANSCRIPT, 1_800),
            $this->buildEvent(AgentVoiceCallTraceSummary::EVENT_TYPE_FIRST_AGENT_TRANSCRIPT, 3_000),
        ]);

        static::assertSame(800, $callTraceSummary->firstAgentWordAfterOpenMs);
        static::assertNull($callTraceSummary->workerAttachAfterOpenMs);
    }

    public function testNumbersOutOfOrderAreNotGuessed(): void
    {
        $callTraceSummary = AgentVoiceCallTraceSummary::fromTraceEvents($this->buildCall(), [
            $this->buildEvent(AgentVoiceCallTraceSummary::EVENT_TYPE_RING_STARTED, 5_000),
            $this->buildEvent(AgentVoiceCallTraceSummary::EVENT_TYPE_ANSWER_CLAIMED, 4_000),
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TRACE_FLUSHED, 9_000),
        ]);

        static::assertNull($callTraceSummary->ringToAnswerMs);
        static::assertNull($callTraceSummary->answerRequestMs);
    }

    public function testAnEndedCallWhoseWorkerNeverFlushedIsFlaggedIncomplete(): void
    {
        $attached = [$this->buildEvent(AgentVoiceCallTraceSummary::EVENT_TYPE_WORKER_ATTACHED, 1_000)];
        $runningCall = $this->buildCall();
        $runningCall->endedAt = null;

        static::assertSame(
            [[AIConversationTraceAnomaly::CODE_TRACE_INCOMPLETE, 7, null, 'the call ended but its worker never wrote the final trace flush: events after the last tick may be missing']],
            $this->collectAnomalies(AgentVoiceCallTraceSummary::fromTraceEvents($this->buildCall(), $attached))
        );
        static::assertSame([], $this->collectAnomalies(AgentVoiceCallTraceSummary::fromTraceEvents($runningCall, $attached)));
        static::assertSame([], $this->collectAnomalies(AgentVoiceCallTraceSummary::fromTraceEvents($this->buildCall(), [])));
    }

    public function testTheMedianTurnGapCountsOnlyTheOwnerFollowedByTheAgent(): void
    {
        $odd = AgentVoiceCallTraceSummary::fromTraceEvents($this->buildCall(), [
            $this->buildSentence(AIConversationMessage::ROLE_USER, 0, 1_000, 1),
            $this->buildSentence(AIConversationMessage::ROLE_ASSISTANT, 1_400, 3_000, 2),
            $this->buildSentence(AIConversationMessage::ROLE_ASSISTANT, 3_100, 4_000, 3),
            $this->buildSentence(AIConversationMessage::ROLE_USER, 5_000, 6_000, 4),
            $this->buildSentence(AIConversationMessage::ROLE_ASSISTANT, 6_900, 8_000, 5),
            $this->buildSentence(AIConversationMessage::ROLE_USER, 9_000, 10_000, 6),
            $this->buildSentence(AIConversationMessage::ROLE_ASSISTANT, 9_800, 11_000, 7),
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TRACE_FLUSHED, 20_000),
        ]);
        $even = AgentVoiceCallTraceSummary::fromTraceEvents($this->buildCall(), [
            $this->buildSentence(AIConversationMessage::ROLE_ASSISTANT, 1_300, 2_000, 2),
            $this->buildSentence(AIConversationMessage::ROLE_USER, 0, 1_000, 1),
            $this->buildSentence(AIConversationMessage::ROLE_USER, 3_000, 4_000, 3),
            $this->buildSentence(AIConversationMessage::ROLE_ASSISTANT, 4_600, 5_000, 4),
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TRACE_FLUSHED, 20_000),
        ]);

        static::assertSame(400, $odd->medianTurnGapMs);
        static::assertSame(450, $even->medianTurnGapMs);
    }

    public function testEachDelegationRoundTripIsMatchedToItsStartByTheDelegationId(): void
    {
        $callTraceSummary = AgentVoiceCallTraceSummary::fromTraceEvents($this->buildCall(), [
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TASK_STARTED, 1_000, payload: ['correlationIds' => ['delegationId' => 'a']]),
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TASK_STARTED, 2_000, payload: ['correlationIds' => ['delegationId' => 'b']]),
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TASK_OUTCOME, 9_000, payload: ['correlationIds' => ['delegationId' => 'a']]),
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TASK_OUTCOME, 12_000, payload: []),
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TASK_OUTCOME, 13_000, payload: []),
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TRACE_FLUSHED, 20_000),
        ]);

        static::assertSame([8_000, 10_000], $callTraceSummary->delegationRoundTripsMs);
    }

    public function testTheAnswerHandedToTheVoiceModelIsFlaggedWhenCutOrCarryingAWidget(): void
    {
        $callTraceSummary = AgentVoiceCallTraceSummary::fromTraceEvents($this->buildCall(), [
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TASK_OUTCOME, 1_000, payload: ['widgetInCommentary' => true, 'answerChars' => 500, 'commentaryChars' => 200], messageIndex: 9),
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TASK_OUTCOME, 2_000, payload: ['answerChars' => 200, 'commentaryChars' => 200], messageIndex: 10),
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TRACE_FLUSHED, 20_000),
        ]);

        static::assertSame([
            [AIConversationTraceAnomaly::CODE_WIDGET_IN_COMMENTARY, 7, 9, 'the answer handed to the voice model carried a widget block'],
            [AIConversationTraceAnomaly::CODE_ANSWER_CUT, 7, 9, 'the agent answered 500 characters, the voice model got 200'],
        ], $this->collectAnomalies($callTraceSummary));
    }

    public function testAShortOwnerRowIsFlaggedAsNoise(): void
    {
        $callTraceSummary = AgentVoiceCallTraceSummary::fromTraceEvents($this->buildCall(), [
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TRANSCRIPT_SENTENCE_PERSISTED, 1_000, payload: ['role' => AIConversationMessage::ROLE_USER, 'chars' => 2], messageIndex: 3),
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TRANSCRIPT_SENTENCE_PERSISTED, 2_000, payload: ['role' => AIConversationMessage::ROLE_USER, 'chars' => 3], messageIndex: 4),
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TRANSCRIPT_SENTENCE_PERSISTED, 3_000, payload: ['role' => AIConversationMessage::ROLE_ASSISTANT, 'chars' => 1], messageIndex: 5),
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TRACE_FLUSHED, 20_000),
        ]);

        static::assertSame(
            [[AIConversationTraceAnomaly::CODE_NOISE_ROW, 7, 3, 'a caller row of 2 characters, most likely transcription noise']],
            $this->collectAnomalies($callTraceSummary)
        );
    }

    public function testADelegationWithoutAReadBackIsFlaggedWithThePersonasName(): void
    {
        $this->declareAgentPersonaConfiguration(['agentName' => 'Mira']);
        $withoutReadBack = AgentVoiceCallTraceSummary::fromTraceEvents($this->buildCall(), [
            $this->buildSentence(AIConversationMessage::ROLE_USER, 0, 1_000, 1, occurredAtMs: 1_000),
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TASK_STARTED, 1_500, messageIndex: 2),
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TRACE_FLUSHED, 20_000),
        ]);
        $withReadBack = AgentVoiceCallTraceSummary::fromTraceEvents($this->buildCall(), [
            $this->buildSentence(AIConversationMessage::ROLE_USER, 0, 1_000, 1, occurredAtMs: 1_000),
            $this->buildSentence(AIConversationMessage::ROLE_ASSISTANT, 1_200, 2_000, 2, occurredAtMs: 2_000),
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TASK_STARTED, 2_500, messageIndex: 3),
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TRACE_FLUSHED, 20_000),
        ]);

        static::assertSame(
            [[AIConversationTraceAnomaly::CODE_NO_READ_BACK, 7, 2, 'the delegation started without Mira reading the request back']],
            $this->collectAnomalies($withoutReadBack)
        );
        static::assertSame([], $this->collectAnomalies($withReadBack));
    }

    public function testAReadBackHeldInTheBacklogIsFlagged(): void
    {
        $callTraceSummary = AgentVoiceCallTraceSummary::fromTraceEvents($this->buildCall(), [
            $this->buildSentence(AIConversationMessage::ROLE_USER, 0, 1_000, 1, occurredAtMs: 1_000),
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TASK_STARTED, 2_000, messageIndex: 2),
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TRANSCRIPT_BACKLOG_PUSHED, 3_000, payload: ['role' => AIConversationMessage::ROLE_ASSISTANT, 'startMs' => 1_000, 'endMs' => 2_500]),
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TRACE_FLUSHED, 20_000),
        ]);

        static::assertSame(
            [[AIConversationTraceAnomaly::CODE_READ_BACK_IN_BACKLOG, 7, 2, 'the read-back of the agent began before the delegation and was held in the backlog while the turn ran']],
            $this->collectAnomalies($callTraceSummary)
        );
    }

    public function testABacklogRowSpokenBeforeTheRowAboveItIsFlagged(): void
    {
        $callTraceSummary = AgentVoiceCallTraceSummary::fromTraceEvents($this->buildCall(), [
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TRANSCRIPT_SENTENCE_PERSISTED, 1_000, payload: ['role' => AIConversationMessage::ROLE_ASSISTANT, 'spokenFromMs' => 5_000, 'spokenUntilMs' => 6_000], messageIndex: 4),
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TRANSCRIPT_SENTENCE_PERSISTED, 2_000, payload: ['role' => AIConversationMessage::ROLE_ASSISTANT, 'spokenFromMs' => 3_000, 'spokenUntilMs' => 4_000, 'fromBacklog' => true], messageIndex: 5),
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TRANSCRIPT_SENTENCE_PERSISTED, 3_000, payload: ['role' => AIConversationMessage::ROLE_ASSISTANT, 'spokenFromMs' => 7_000, 'spokenUntilMs' => 8_000, 'fromBacklog' => true], messageIndex: 6),
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TRACE_FLUSHED, 20_000),
        ]);

        static::assertSame(
            [[AIConversationTraceAnomaly::CODE_ROW_ORDER_INVERTED, 7, 5, 'this row came from the backlog and was spoken before the row above it']],
            $this->collectAnomalies($callTraceSummary)
        );
    }

    public function testASlowPickupOfTheDelegationsTurnIsFlagged(): void
    {
        $slowTurnEnd = $this->buildTurnEnd(2, 1_001);
        $fastTurnEnd = $this->buildTurnEnd(2, 1_000);
        $otherTurnEnd = $this->buildTurnEnd(3, 5_000);
        $events = [
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TASK_STARTED, 2_000, messageIndex: 2),
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TRACE_FLUSHED, 20_000),
        ];

        static::assertSame(
            [[AIConversationTraceAnomaly::CODE_SLOW_PICKUP, 7, 2, 'the turn worker picked the turn up 1001 ms after its dispatch']],
            $this->collectAnomalies(AgentVoiceCallTraceSummary::fromTraceEvents($this->buildCall(), $events, [$slowTurnEnd, $otherTurnEnd]))
        );
        static::assertSame([], $this->collectAnomalies(AgentVoiceCallTraceSummary::fromTraceEvents($this->buildCall(), $events, [$fastTurnEnd])));
    }

    public function testTheHeadersAreAnObjectSet(): void
    {
        $callTraceSummaries = new AgentVoiceCallTraceSummaries();
        $callTraceSummary = AgentVoiceCallTraceSummary::fromTraceEvents($this->buildCall(), []);
        $callTraceSummaries->add($callTraceSummary);

        static::assertSame($callTraceSummary, $callTraceSummaries->first());
    }

    /** @return array<int, array{?string, ?int, ?int, ?string}> */
    protected function collectAnomalies(AgentVoiceCallTraceSummary $callTraceSummary): array
    {
        return array_map(
            static fn (AIConversationTraceAnomaly $anomaly): array => [$anomaly->code, $anomaly->agentVoiceCallId, $anomaly->messageIndex, $anomaly->detail],
            $callTraceSummary->anomalies->getElements()
        );
    }

    /** A call answered at the fixed now minus 400 s and ended 300 s later. */
    protected function buildCall(): AgentVoiceCall
    {
        $agentVoiceCall = AgentVoiceCallFixtures::buildCallAt(AgentVoiceCallFixtures::NOW_UNIX_TIME, -500, null, -400, -100);
        $agentVoiceCall->aiConversationId = 30;

        return $agentVoiceCall;
    }

    /** @param array<string, mixed> $payload */
    protected function buildEvent(string $eventType, int $occurredAtMs, ?int $durationMs = null, array $payload = [], ?int $messageIndex = null): AIConversationTraceEvent
    {
        $this->eventSequence++;

        return AIConversationTraceEvent::create(
            source: AIConversationTraceEvent::SOURCE_VOICE_WORKER,
            eventType: $eventType,
            eventKey: 'call:7:' . $eventType . ':' . $this->eventSequence,
            aiConversationId: 30,
            agentVoiceCallId: 7,
            occurredAtUnixMs: static::T + $occurredAtMs,
            payload: $payload,
            durationMs: $durationMs,
            messageIndex: $messageIndex
        );
    }

    protected function buildSentence(string $role, int $spokenFromMs, int $spokenUntilMs, int $messageIndex, ?int $occurredAtMs = null): AIConversationTraceEvent
    {
        return $this->buildEvent(
            AIConversationTraceEvent::EVENT_TYPE_TRANSCRIPT_SENTENCE_PERSISTED,
            $occurredAtMs ?? $spokenUntilMs,
            payload: ['role' => $role, 'spokenFromMs' => $spokenFromMs, 'spokenUntilMs' => $spokenUntilMs, 'chars' => 40],
            messageIndex: $messageIndex
        );
    }

    protected function buildTurnEnd(int $initiatorMessageIndex, int $sendToJobMs): AIConversationTurnEvent
    {
        $turnEndEvent = AIConversationTurnEvent::newInstance();
        $turnEndEvent->kind = AIConversationTurnEvent::KIND_TURN_END;
        $turnEndEvent->initiatorMessageIndex = $initiatorMessageIndex;
        $turnEndEvent->setPayloadArray(['turnStartMs' => ['sendToJob' => $sendToJobMs]]);

        return $turnEndEvent;
    }
}
