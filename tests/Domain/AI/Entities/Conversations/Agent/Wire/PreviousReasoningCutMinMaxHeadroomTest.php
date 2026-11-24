<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Wire;

use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentPreviousReasoningCut;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The cut of the carry-over `capped`: above max(cap, kept at the last cut + headroom) the older steps lose their
 * reasoning; walking back from the newest step, whole steps are kept until the min and the step count are reached.
 */
class PreviousReasoningCutMinMaxHeadroomTest extends UnitTestCase
{
    public function testTheDefaultsKeepTheNewestTwoSteps(): void
    {
        $agentPreviousReasoningCut = AgentPreviousReasoningCut::decideFor($this->buildSteps([1500, 1500, 1500, 1500]), RunSettings::AGENT_WIRE_API_RESPONSES, 4000);

        self::assertTrue($agentPreviousReasoningCut->movedOnThisCall);
        self::assertSame(3, $agentPreviousReasoningCut->cutFromMessageIndex);
        self::assertSame(3000, $agentPreviousReasoningCut->sentReasoningTokens);
    }

    public function testTheMinKeepsWholeStepsUntilItIsReached(): void
    {
        // the worked example of the cut: 600, 900, 700, 1,200, 800 → 4,200 > 4,000
        $messages = $this->buildSteps([600, 900, 700, 1200, 800]);

        $keptAtMin1500 = AgentPreviousReasoningCut::decideFor($messages, RunSettings::AGENT_WIRE_API_RESPONSES, 4000, keptTokensAtCut: 1500);
        self::assertSame(4, $keptAtMin1500->cutFromMessageIndex);
        self::assertSame(2000, $keptAtMin1500->sentReasoningTokens);

        $keptAtMin2500 = AgentPreviousReasoningCut::decideFor($messages, RunSettings::AGENT_WIRE_API_RESPONSES, 4000, keptTokensAtCut: 2500);
        self::assertSame(3, $keptAtMin2500->cutFromMessageIndex, 'a step is never split: 800 + 1,200 + 700');
        self::assertSame(2700, $keptAtMin2500->sentReasoningTokens);

        $keptThreeSteps = AgentPreviousReasoningCut::decideFor($messages, RunSettings::AGENT_WIRE_API_RESPONSES, 4000, keptStepsAtCut: 3);
        self::assertSame(3, $keptThreeSteps->cutFromMessageIndex);
    }

    public function testAMinThatKeepsEveryStepCutsNothing(): void
    {
        $agentPreviousReasoningCut = AgentPreviousReasoningCut::decideFor($this->buildSteps([600, 900, 700, 1200, 800]), RunSettings::AGENT_WIRE_API_RESPONSES, 4000, keptTokensAtCut: 5000);

        self::assertFalse($agentPreviousReasoningCut->movedOnThisCall);
        self::assertSame(0, $agentPreviousReasoningCut->cutFromMessageIndex);
        self::assertSame(4200, $agentPreviousReasoningCut->sentReasoningTokens);
    }

    public function testTheHeadroomDelaysTheNextCutAfterALargeKeptBlock(): void
    {
        // an earlier cut at step 4 kept 1,800 + 1,700 = 3,500 (the call of row 6 recorded it); two more steps add 800
        $messages = $this->buildSteps([500, 500, 500, 1800, 1700, 400, 400]);
        $messages[6]->requestSnapshot->previousReasoningCutFromMessageIndex = 4;
        $messages[7]->requestSnapshot->previousReasoningCutFromMessageIndex = 4;

        $withoutHeadroom = AgentPreviousReasoningCut::decideFor($messages, RunSettings::AGENT_WIRE_API_RESPONSES, 4000);
        self::assertTrue($withoutHeadroom->movedOnThisCall, '4,300 > 4,000: the cut moves again one step later');
        self::assertSame(6, $withoutHeadroom->cutFromMessageIndex);

        $withHeadroom = AgentPreviousReasoningCut::decideFor($messages, RunSettings::AGENT_WIRE_API_RESPONSES, 4000, headroomTokensAfterCut: 1000);
        self::assertFalse($withHeadroom->movedOnThisCall, '4,300 ≤ max(4,000, 3,500 + 1,000)');
        self::assertSame(4, $withHeadroom->cutFromMessageIndex);
        self::assertSame(3500, $withHeadroom->keptTokensAtAnchorCut);
        self::assertSame(4500, $withHeadroom->cutAboveTokens);
    }

    public function testASmallKeptBlockLeavesTheCapInCharge(): void
    {
        $messages = $this->buildSteps([500, 500, 500, 300, 300, 2000, 1500]);
        $messages[6]->requestSnapshot->previousReasoningCutFromMessageIndex = 4;
        $messages[7]->requestSnapshot->previousReasoningCutFromMessageIndex = 4;

        $agentPreviousReasoningCut = AgentPreviousReasoningCut::decideFor($messages, RunSettings::AGENT_WIRE_API_RESPONSES, 4000, headroomTokensAfterCut: 1000);

        self::assertSame(4000, $agentPreviousReasoningCut->cutAboveTokens, 'max(4,000, 600 + 1,000)');
        self::assertTrue($agentPreviousReasoningCut->movedOnThisCall, '4,100 > 4,000');
        self::assertSame(6, $agentPreviousReasoningCut->cutFromMessageIndex);
    }

    /**
     * An owner message at index 0 and one step per entry of `$reasoningTokensPerStep` at the indexes 1, 2, …
     *
     * @param int[] $reasoningTokensPerStep
     * @return AIConversationMessage[]
     */
    protected function buildSteps(array $reasoningTokensPerStep): array
    {
        /** @var AIConversationMessage $ownerMessage */
        $ownerMessage = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $ownerMessage->messageType = AIConversationMessage::MESSAGE_TEXT;
        $ownerMessage->role = AIConversationMessage::ROLE_USER;
        $ownerMessage->authorType = AIConversationMessage::AUTHOR_USER;
        $ownerMessage->message = 'question';
        $ownerMessage->indexInConversation = 0;
        $messages = [$ownerMessage];
        foreach (array_values($reasoningTokensPerStep) as $stepPosition => $reasoningTokens) {
            $messages[] = $this->buildStepRow($stepPosition + 1, $reasoningTokens);
        }
        return $messages;
    }

    protected function buildStepRow(int $messageIndex, int $reasoningTokens): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->authorType = AIConversationMessage::AUTHOR_ASSISTANT;
        $message->indexInConversation = $messageIndex;
        $message->inputTokens = 100;
        $toolCall = new ToolCall();
        $toolCall->toolCallId = 'call_' . $messageIndex;
        $toolCall->name = 'getOrder';
        $toolCall->arguments = ['orderId' => '4711'];
        $toolCall->result = 'order';
        $toolCall->status = AIConversationMessage::STATUS_COMPLETED;
        $message->toolCall = $toolCall;
        $requestSnapshot = new RequestSnapshot();
        $requestSnapshot->modelName = 'OPENAI.TEST';
        $requestSnapshot->wireReasoningApi = RunSettings::AGENT_WIRE_API_RESPONSES;
        $requestSnapshot->wireReasoningItemsJson = '[{"type":"reasoning","id":"rs_' . $messageIndex . '","encrypted_content":"x"}]';
        $requestSnapshot->wireReasoningTokens = $reasoningTokens;
        $message->requestSnapshot = $requestSnapshot;
        return $message;
    }
}
