<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Entities\Voice\TraceEvents;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent;
use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvents;
use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvents;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\TraceEvents\AgentVoiceCallTraceBacklogPush;
use DDD\Domain\AI\Entities\Voice\TraceEvents\AgentVoiceCallTraceBacklogPushes;
use DDD\Domain\AI\Entities\Voice\TraceEvents\AgentVoiceCallTraceFirstEvents;
use DDD\Domain\AI\Entities\Voice\TraceEvents\AgentVoiceCallTraceSpokenSentence;
use DDD\Domain\AI\Entities\Voice\TraceEvents\AgentVoiceCallTraceSpokenSentences;
use DDD\Domain\AI\Entities\Voice\TraceEvents\AgentVoiceCallTraceSummary;
use DDD\Tests\Agents\Voice\Support\AgentVoiceCallFixtures;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;
use DDD\Tests\Agents\Voice\Support\AgentVoiceValueObjectAssertionsTrait;

/**
 * The sets the call header reads a call's trace into: the spoken sentences ({@see AgentVoiceCallTraceSpokenSentences})
 * and the backlog pushes ({@see AgentVoiceCallTraceBacklogPushes}) hold each row under the key of its own object, so
 * two rows with the same times stay two, and the spoken sentences order by their start on the session clock; the
 * first events ({@see AgentVoiceCallTraceFirstEvents}) keep the first event of a type and answer null / false for a
 * type the call does not have; a header built without turn events is the header built with an empty set of them.
 */
class AgentVoiceCallTraceSetsTest extends AgentVoiceUnitTestCase
{
    use AgentVoiceValueObjectAssertionsTrait;

    protected const int T = 1_800_000_000_000;

    protected int $eventSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->declareAgentPersonaConfiguration(['agentName' => '']);
    }

    public function testTwoSpokenSentencesWithTheSameSpeakerAndTimesStayTwo(): void
    {
        $firstSentence = AgentVoiceCallTraceSpokenSentence::create(AIConversationMessage::ROLE_ASSISTANT, 1_000, 2_000, static::T + 2_000);
        $secondSentence = AgentVoiceCallTraceSpokenSentence::create(AIConversationMessage::ROLE_ASSISTANT, 1_000, 2_000, static::T + 2_000);

        $spokenSentences = AgentVoiceCallTraceSpokenSentences::fromSentences($firstSentence, $secondSentence);
        $spokenSentences->add($firstSentence);

        static::assertSame([$firstSentence, $secondSentence], $spokenSentences->getElements());
    }

    public function testTheSpokenSentencesOrderByTheirStartOnTheSessionClock(): void
    {
        $thirdSpoken = AgentVoiceCallTraceSpokenSentence::create(AIConversationMessage::ROLE_ASSISTANT, 5_000, 6_000, static::T + 1_000);
        $firstSpoken = AgentVoiceCallTraceSpokenSentence::create(AIConversationMessage::ROLE_USER, 0, 1_000, static::T + 3_000);
        $secondSpoken = AgentVoiceCallTraceSpokenSentence::create(AIConversationMessage::ROLE_ASSISTANT, 1_200, 4_000, static::T + 2_000);
        $spokenSentences = AgentVoiceCallTraceSpokenSentences::fromSentences($thirdSpoken, $firstSpoken, $secondSpoken);
        static::assertSame([$thirdSpoken, $firstSpoken, $secondSpoken], $spokenSentences->getElements(), 'in the order of the trace until sorted');

        $spokenSentences->sortBySpokenStart();

        static::assertSame([$firstSpoken, $secondSpoken, $thirdSpoken], $spokenSentences->getElements());
        static::assertSame([0, 1_200, 5_000], array_map([static::class, 'readFromMs'], $spokenSentences->getElements()));
    }

    public function testTwoBacklogPushesWithTheSameTimesStayTwo(): void
    {
        $firstPush = AgentVoiceCallTraceBacklogPush::create(static::T + 3_000, 1_000, 2_500);
        $secondPush = AgentVoiceCallTraceBacklogPush::create(static::T + 3_000, 1_000, 2_500);

        $backlogPushes = AgentVoiceCallTraceBacklogPushes::fromPushes($firstPush, $secondPush);
        $backlogPushes->add($secondPush);

        static::assertSame([$firstPush, $secondPush], $backlogPushes->getElements());
        static::assertCount(0, AgentVoiceCallTraceBacklogPushes::fromPushes());
    }

    public function testTheFirstEventOfATypeWins(): void
    {
        $firstAttach = $this->buildEvent(AgentVoiceCallTraceSummary::EVENT_TYPE_WORKER_ATTACHED, 1_000);
        $laterAttach = $this->buildEvent(AgentVoiceCallTraceSummary::EVENT_TYPE_WORKER_ATTACHED, 2_000);
        $firstWord = $this->buildEvent(AgentVoiceCallTraceSummary::EVENT_TYPE_FIRST_AGENT_TRANSCRIPT, 1_800);

        $firstEvents = AgentVoiceCallTraceFirstEvents::fromTraceEvents(static::buildTraceEventSet($firstAttach, $laterAttach, $firstWord));

        static::assertSame($firstAttach, $firstEvents->findEventOfType(AgentVoiceCallTraceSummary::EVENT_TYPE_WORKER_ATTACHED));
        static::assertSame($firstWord, $firstEvents->findEventOfType(AgentVoiceCallTraceSummary::EVENT_TYPE_FIRST_AGENT_TRANSCRIPT));
        static::assertTrue($firstEvents->hasEventOfType(AgentVoiceCallTraceSummary::EVENT_TYPE_WORKER_ATTACHED));
    }

    public function testAnUnknownTypeIsNullAndFalse(): void
    {
        $firstEvents = AgentVoiceCallTraceFirstEvents::fromTraceEvents(static::buildTraceEventSet(
            $this->buildEvent(AgentVoiceCallTraceSummary::EVENT_TYPE_WORKER_ATTACHED, 1_000)
        ));
        $noEvents = AgentVoiceCallTraceFirstEvents::fromTraceEvents(new AIConversationTraceEvents());

        static::assertNull($firstEvents->findEventOfType(AgentVoiceCallTraceSummary::EVENT_TYPE_RING_STARTED));
        static::assertFalse($firstEvents->hasEventOfType(AgentVoiceCallTraceSummary::EVENT_TYPE_RING_STARTED));
        static::assertNull($firstEvents->findEventOfType('noSuchEventType'));
        static::assertFalse($firstEvents->hasEventOfType(''));
        static::assertNull($noEvents->findEventOfType(AgentVoiceCallTraceSummary::EVENT_TYPE_WORKER_ATTACHED));
        static::assertFalse($noEvents->hasEventOfType(AgentVoiceCallTraceSummary::EVENT_TYPE_WORKER_ATTACHED));
    }

    public function testAHeaderWithoutTurnEventsIsTheHeaderWithAnEmptySetOfThem(): void
    {
        $traceEvents = static::buildTraceEventSet(
            $this->buildEvent(AgentVoiceCallTraceSummary::EVENT_TYPE_RING_STARTED, 0),
            $this->buildEvent(AgentVoiceCallTraceSummary::EVENT_TYPE_ANSWER_CLAIMED, 4_000, durationMs: 120),
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TRANSCRIPT_SENTENCE_PERSISTED, 5_000, payload: ['role' => AIConversationMessage::ROLE_USER, 'spokenFromMs' => 0, 'spokenUntilMs' => 1_000, 'chars' => 40], messageIndex: 1),
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TASK_STARTED, 6_000, payload: ['correlationIds' => ['delegationId' => 'a']], messageIndex: 2),
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TASK_OUTCOME, 9_000, payload: ['correlationIds' => ['delegationId' => 'a']]),
            $this->buildEvent(AIConversationTraceEvent::EVENT_TYPE_TRACE_FLUSHED, 20_000),
        );

        $withoutTurnEvents = AgentVoiceCallTraceSummary::fromTraceEvents($this->buildCall(), $traceEvents);
        $withAnEmptySet = AgentVoiceCallTraceSummary::fromTraceEvents($this->buildCall(), $traceEvents, new AIConversationTurnEvents());

        static::assertSameValueObjects($withAnEmptySet, $withoutTurnEvents);
        static::assertSame([3_000], $withoutTurnEvents->delegationRoundTripsMs);
    }

    protected static function readFromMs(AgentVoiceCallTraceSpokenSentence $spokenSentence): int
    {
        return $spokenSentence->fromMs;
    }

    /** The trace events as a set, in the given order. */
    protected static function buildTraceEventSet(AIConversationTraceEvent ...$traceEvents): AIConversationTraceEvents
    {
        $traceEventSet = new AIConversationTraceEvents();
        $traceEventSet->add(...$traceEvents);

        return $traceEventSet;
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
}
