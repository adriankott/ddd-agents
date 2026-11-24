<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Wire;

use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentMessagesWireReplay;
use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentWireConfiguration;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The two floors of a call on the Anthropic Messages API: from which row on the history goes back exactly as earlier
 * calls sent and received it (the frozen part), and from which step on the thinking blocks ride — per carry-over mode,
 * after a call that reported dropped blocks, and at a cut of `capped`.
 */
class AgentMessagesWireReplayTest extends UnitTestCase
{
    public function testWithoutCarryOverNothingIsFrozenAndNoThinkingRides(): void
    {
        $agentMessagesWireReplay = AgentMessagesWireReplay::decideFor($this->buildTwoTurns(), $this->buildConfiguration(RunSettings::PREVIOUS_REASONING_CARRY_OVER_OFF));

        self::assertNull($agentMessagesWireReplay->frozenFromMessageIndex);
        self::assertFalse($agentMessagesWireReplay->rendersFrozen(5));
        self::assertFalse($agentMessagesWireReplay->sendsTheThinkingOfTheStepAt(5));
    }

    public function testAnotherWireApiHasNoFrozenPart(): void
    {
        $agentWireConfiguration = $this->buildConfiguration(RunSettings::PREVIOUS_REASONING_CARRY_OVER_ALL);
        $agentWireConfiguration->wireApi = RunSettings::AGENT_WIRE_API_RESPONSES;

        self::assertNull(AgentMessagesWireReplay::decideFor($this->buildTwoTurns(), $agentWireConfiguration)->frozenFromMessageIndex);
    }

    public function testCurrentTurnFreezesFromTheNewestTurnInitiator(): void
    {
        $agentMessagesWireReplay = AgentMessagesWireReplay::decideFor($this->buildTwoTurns(), $this->buildConfiguration(RunSettings::PREVIOUS_REASONING_CARRY_OVER_CURRENT_TURN));

        self::assertSame(3, $agentMessagesWireReplay->frozenFromMessageIndex);
        self::assertSame(3, $agentMessagesWireReplay->thinkingFromMessageIndex);
        self::assertFalse($agentMessagesWireReplay->rendersFrozen(2), 'the earlier turn is rendered as on every other wire API');
        self::assertTrue($agentMessagesWireReplay->sendsTheThinkingOfTheStepAt(4));
    }

    public function testAllKeepsTheFloorTheNewestEarlierCallRecordedAcrossTurns(): void
    {
        $messages = $this->buildTwoTurns();
        $messages[4]->requestSnapshot->messagesWireFrozenFromMessageIndex = 0;

        $agentMessagesWireReplay = AgentMessagesWireReplay::decideFor($messages, $this->buildConfiguration(RunSettings::PREVIOUS_REASONING_CARRY_OVER_ALL));

        self::assertSame(0, $agentMessagesWireReplay->frozenFromMessageIndex);
        self::assertSame(0, $agentMessagesWireReplay->thinkingFromMessageIndex);
        self::assertTrue($agentMessagesWireReplay->sendsTheThinkingOfTheStepAt(1), 'the first turn\'s thinking still rides');
    }

    public function testAllWithoutARecordedFloorStartsAtTheNewestTurnInitiator(): void
    {
        $agentMessagesWireReplay = AgentMessagesWireReplay::decideFor($this->buildTwoTurns(), $this->buildConfiguration(RunSettings::PREVIOUS_REASONING_CARRY_OVER_ALL));

        self::assertSame(3, $agentMessagesWireReplay->frozenFromMessageIndex, 'the first call on this wire, or the recording rows are behind a compaction cover');
    }

    public function testACallThatReportedDroppedThinkingLiftsTheThinkingFloorToItsStepAndKeepsTheFrozenPart(): void
    {
        $messages = $this->buildTwoTurns();
        $messages[4]->requestSnapshot->messagesWireFrozenFromMessageIndex = 0;
        $messages[4]->requestSnapshot->inputTransformations = [['type' => 'thinking_dropped', 'path' => 'messages.2.content.0', 'reason' => 'prefix_binding_mismatch']];

        $agentMessagesWireReplay = AgentMessagesWireReplay::decideFor($messages, $this->buildConfiguration(RunSettings::PREVIOUS_REASONING_CARRY_OVER_ALL));

        self::assertSame(0, $agentMessagesWireReplay->frozenFromMessageIndex);
        self::assertSame(4, $agentMessagesWireReplay->droppedThinkingReportedAtMessageIndex);
        self::assertSame(4, $agentMessagesWireReplay->thinkingFromMessageIndex);
        self::assertTrue($agentMessagesWireReplay->rendersFrozen(1));
        self::assertFalse($agentMessagesWireReplay->sendsTheThinkingOfTheStepAt(1), 'a block before the reporting call is invalid for good');
        self::assertTrue($agentMessagesWireReplay->sendsTheThinkingOfTheStepAt(4), 'the reporting call\'s own block was produced over the request as the model read it');
    }

    public function testCappedMovesTheThinkingFloorToTheNewestTwoStepsOnceTheCapIsCrossed(): void
    {
        $messages = [$this->ownerTextRow(0)];
        foreach ([1, 2, 3, 4] as $messageIndex) {
            $messages[] = $this->stepRow($messageIndex, 1500);
        }
        $messages[1]->requestSnapshot->messagesWireFrozenFromMessageIndex = 0;
        $agentWireConfiguration = $this->buildConfiguration(RunSettings::PREVIOUS_REASONING_CARRY_OVER_CAPPED);
        $agentWireConfiguration->previousReasoningCapTokens = 4000;

        $agentMessagesWireReplay = AgentMessagesWireReplay::decideFor($messages, $agentWireConfiguration, 'ANTHROPIC.TEST');

        self::assertSame(0, $agentMessagesWireReplay->frozenFromMessageIndex, 'the cut strips thinking, the steps stay frozen');
        self::assertNotNull($agentMessagesWireReplay->previousReasoningCut);
        self::assertTrue($agentMessagesWireReplay->previousReasoningCut->movedOnThisCall);
        self::assertSame(3, $agentMessagesWireReplay->thinkingFromMessageIndex);
        self::assertFalse($agentMessagesWireReplay->sendsTheThinkingOfTheStepAt(2));
        self::assertTrue($agentMessagesWireReplay->sendsTheThinkingOfTheStepAt(3));
        self::assertSame(3000, $agentMessagesWireReplay->previousReasoningCut->sentReasoningTokens);
    }

    public function testCappedCountsOnlyTheStepsAtOrAboveTheFrozenFloor(): void
    {
        $messages = [$this->ownerTextRow(0), $this->stepRow(1, 3900), $this->ownerTextRow(2), $this->stepRow(3, 300)];
        $agentWireConfiguration = $this->buildConfiguration(RunSettings::PREVIOUS_REASONING_CARRY_OVER_CAPPED);
        $agentWireConfiguration->previousReasoningCapTokens = 4000;

        $agentMessagesWireReplay = AgentMessagesWireReplay::decideFor($messages, $agentWireConfiguration, 'ANTHROPIC.TEST');

        self::assertSame(2, $agentMessagesWireReplay->frozenFromMessageIndex);
        self::assertSame(2, $agentMessagesWireReplay->thinkingFromMessageIndex, 'the thinking below the frozen floor cannot ride, so it does not count against the cap');
        self::assertFalse($agentMessagesWireReplay->previousReasoningCut->movedOnThisCall);
        self::assertSame(300, $agentMessagesWireReplay->previousReasoningCut->sentReasoningTokens);
    }

    public function testTheFloorsOfARecordedCallRenderItsReconstruction(): void
    {
        $recordedReplay = AgentMessagesWireReplay::fromRecordedFloors(2, 6);
        self::assertTrue($recordedReplay->rendersFrozen(4));
        self::assertFalse($recordedReplay->sendsTheThinkingOfTheStepAt(4));
        self::assertTrue($recordedReplay->sendsTheThinkingOfTheStepAt(6));

        $withoutFrozenPart = AgentMessagesWireReplay::fromRecordedFloors(null, null);
        self::assertFalse($withoutFrozenPart->rendersFrozen(0));
    }

    public function testAStoredAnswerOfTheMessagesApiIsRecognisedWhateverModelProducedIt(): void
    {
        $requestSnapshot = new RequestSnapshot();
        $requestSnapshot->modelName = 'ANTHROPIC.OTHER';
        $requestSnapshot->wireReasoningApi = RunSettings::AGENT_WIRE_API_MESSAGES;
        $requestSnapshot->wireReasoningItemsJson = '[{"type":"text","text":"Done."}]';
        self::assertTrue(AgentMessagesWireReplay::holdsStoredAnswerOfTheMessagesApi($requestSnapshot));

        $requestSnapshot->wireReasoningApi = RunSettings::AGENT_WIRE_API_CHAT_COMPLETIONS;
        self::assertFalse(AgentMessagesWireReplay::holdsStoredAnswerOfTheMessagesApi($requestSnapshot));
        self::assertFalse(AgentMessagesWireReplay::holdsStoredAnswerOfTheMessagesApi(null));
    }

    /**
     * Turn 1: owner (0), two steps (1, 2). Turn 2: owner (3), one step (4).
     *
     * @return AIConversationMessage[]
     */
    protected function buildTwoTurns(): array
    {
        return [$this->ownerTextRow(0), $this->stepRow(1, 400), $this->stepRow(2, 400), $this->ownerTextRow(3), $this->stepRow(4, 400)];
    }

    protected function buildConfiguration(string $previousReasoningCarryOver): AgentWireConfiguration
    {
        $agentWireConfiguration = new AgentWireConfiguration();
        $agentWireConfiguration->wireApi = RunSettings::AGENT_WIRE_API_MESSAGES;
        $agentWireConfiguration->skillLoadingStrategy = ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND;
        $agentWireConfiguration->previousReasoningCarryOver = $previousReasoningCarryOver;
        $agentWireConfiguration->previousReasoningCapTokens = 4000;
        return $agentWireConfiguration;
    }

    protected function ownerTextRow(int $messageIndex): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->role = AIConversationMessage::ROLE_USER;
        $message->authorType = AIConversationMessage::AUTHOR_USER;
        $message->message = 'question ' . $messageIndex;
        $message->indexInConversation = $messageIndex;
        return $message;
    }

    protected function stepRow(int $messageIndex, int $thinkingTokens): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->authorType = AIConversationMessage::AUTHOR_ASSISTANT;
        $message->indexInConversation = $messageIndex;
        $message->inputTokens = 100;
        $toolCall = new ToolCall();
        $toolCall->toolCallId = 'toolu_' . $messageIndex;
        $toolCall->name = 'getOrder';
        $toolCall->arguments = ['orderId' => '4711'];
        $toolCall->result = 'order';
        $toolCall->status = AIConversationMessage::STATUS_COMPLETED;
        $message->toolCall = $toolCall;
        $requestSnapshot = new RequestSnapshot();
        $requestSnapshot->modelName = 'ANTHROPIC.TEST';
        $requestSnapshot->wireReasoningApi = RunSettings::AGENT_WIRE_API_MESSAGES;
        $requestSnapshot->wireReasoningItemsJson = '[{"type":"thinking","thinking":"step ' . $messageIndex . '","signature":"sig-' . $messageIndex . '"},'
            . '{"type":"tool_use","id":"toolu_' . $messageIndex . '","name":"getOrder","input":{"orderId":"4711"}}]';
        $requestSnapshot->wireReasoningTokens = $thinkingTokens;
        $message->requestSnapshot = $requestSnapshot;
        return $message;
    }
}
