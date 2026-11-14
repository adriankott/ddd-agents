<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentToolCallAnswer;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentTransientMessageIds;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\ToolResult;
use DDD\Domain\AI\Services\Conversations\Agent\AgentChildTurnRunnerInterface;
use DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallDispatchService;
use DDD\Presentation\Mcp\Dtos\MCPToolResponse;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\Probes\ExecutionRecordingDispatchService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The dispatch of the identical write right after its final refusal in the same turn: the call does not run, it is
 * answered with the refusal and the move left, and the next step answers without tools. The identical repeat of a gate
 * refusal that may open later is dispatched as before.
 */
class RepeatAfterFinalRefusalDispatchTest extends UnitTestCase
{
    protected const string REFUSAL_TEXT = "Tool 'publishOffer' is not available here — it requires the product features OFFERS, which this "
        . 'plan does not include. Do not retry.';

    public function testTheRepeatDoesNotRunAndTheNextStepHasNoTools(): void
    {
        $refusedCallRow = $this->refusedCallRow(true, 1);
        $repeatRow = $this->writeCallRow(2);
        $conversation = $this->conversation($this->ownerRow(0), $refusedCallRow, $repeatRow);

        $agentToolCallAnswer = $this->dispatch($conversation, $repeatRow);

        self::assertTrue($agentToolCallAnswer->forceToolLessAnswerStep);
        self::assertFalse($agentToolCallAnswer->repeatedToolCallAbort);
        self::assertSame(AIConversationMessage::STATUS_FAILED, $repeatRow->toolCall->status);
        self::assertTrue($repeatRow->toolCall->wasRefusedAsDuplicate, 'no work: no counter of work counts it');
        self::assertTrue($repeatRow->toolCall->isRefusedAsFinal());
        self::assertStringStartsWith('NOT executed: `publishOffer` with these arguments was already refused in this turn, and that refusal is final', (string)$repeatRow->toolCall->result);
        self::assertStringContainsString('«' . self::REFUSAL_TEXT . '»', (string)$repeatRow->toolCall->result);
    }

    /**
     * A final refusal without a gate kind (a missing feature or account right, a space the account may not use): the
     * repeat guard counts such a refusal, so the third identical call reaches its cap. Every identical repeat is still
     * answered with the refusal, stored FAILED and refused finally, and the tool runs once.
     */
    public function testEveryRepeatOfAFinalRefusalWithoutAGateKindIsAnsweredWithTheRefusal(): void
    {
        $this->assertFourIdenticalCallsAreAnsweredWithTheFinalRefusal(null);
    }

    public function testEveryRepeatOfAFinalRefusalWithAGateKindIsAnsweredWithTheRefusal(): void
    {
        $this->assertFourIdenticalCallsAreAnsweredWithTheFinalRefusal(MCPToolResponse::REFUSAL_MANDATE_REQUIRED);
    }

    /** Four identical calls in one turn, the first refused finally by the executor with `$refusalKind`. */
    protected function assertFourIdenticalCallsAreAnsweredWithTheFinalRefusal(?string $refusalKind): void
    {
        $dispatchService = new ExecutionRecordingDispatchService();
        $refusedResponse = MCPToolResponse::finalRefusal(self::REFUSAL_TEXT, $refusalKind);
        $refusedResult = new ToolResult(json_encode(['content' => $refusedResponse->content, 'isError' => true], JSON_THROW_ON_ERROR), true);
        $refusedResult->refusalKind = $refusedResponse->refusalKind;
        $refusedResult->refusalIsFinal = $refusedResponse->isFinalRefusal();
        $dispatchService->stubbedToolResult = $refusedResult;
        $conversation = $this->conversation($this->ownerRow(0));

        $firstCallRow = $this->writeCallRow(1);
        $conversation->messages->add($firstCallRow);
        $this->dispatch($conversation, $firstCallRow, $dispatchService);
        self::assertTrue($firstCallRow->toolCall->isRefusedAsFinal(), 'the executor refused it finally');
        self::assertSame($refusalKind !== null, $firstCallRow->toolCall->wasRefusedByGate);

        foreach ([2, 3, 4] as $callNumber) {
            $repeatRow = $this->writeCallRow($callNumber);
            $conversation->messages->add($repeatRow);

            $agentToolCallAnswer = $this->dispatch($conversation, $repeatRow, $dispatchService);

            $case = 'call ' . $callNumber;
            self::assertSame(AIConversationMessage::STATUS_FAILED, $repeatRow->toolCall->status, $case);
            self::assertTrue($repeatRow->toolCall->wasRefusedAsDuplicate, $case);
            self::assertTrue($repeatRow->toolCall->isRefusedAsFinal(), $case);
            self::assertTrue($agentToolCallAnswer->forceToolLessAnswerStep, $case);
            self::assertFalse($agentToolCallAnswer->repeatedToolCallAbort, $case);
            self::assertSame(
                'NOT executed: `publishOffer` with these arguments was already refused in this turn, and that refusal is final — the same '
                    . 'call is refused again, and nothing was done. The refusal: «' . self::REFUSAL_TEXT . '» Write your answer now from what '
                    . 'it says; the next step has no tools.',
                $repeatRow->toolCall->result,
                $case
            );
        }
        self::assertSame(['publishOffer'], $dispatchService->executedToolNames, 'the tool ran once');
    }

    /**
     * A signal row between the repeats (the loop's own bounce of a text, a message to the run, a widget click) may
     * bring what the refusal missed: the next identical call runs again, and the repeats the loop answered before
     * the signal are not counted against it. Refused finally once more, its repeats are answered with the refusal.
     */
    public function testASignalRowBetweenTheRepeatsLetsTheIdenticalCallRunAgainWithoutAGateKind(): void
    {
        $this->assertTheIdenticalCallRunsAgainAfterASignalRow(null);
    }

    public function testASignalRowBetweenTheRepeatsLetsTheIdenticalCallRunAgainWithAGateKind(): void
    {
        $this->assertTheIdenticalCallRunsAgainAfterASignalRow(MCPToolResponse::REFUSAL_MANDATE_REQUIRED);
    }

    /** Calls 1 and 2, a signal row, calls 3, 4 and 5: every executed call is refused finally with `$refusalKind`. */
    protected function assertTheIdenticalCallRunsAgainAfterASignalRow(?string $refusalKind): void
    {
        $dispatchService = new ExecutionRecordingDispatchService();
        $refusedResponse = MCPToolResponse::finalRefusal(self::REFUSAL_TEXT, $refusalKind);
        $refusedResult = new ToolResult(json_encode(['content' => $refusedResponse->content, 'isError' => true], JSON_THROW_ON_ERROR), true);
        $refusedResult->refusalKind = $refusedResponse->refusalKind;
        $refusedResult->refusalIsFinal = $refusedResponse->isFinalRefusal();
        $dispatchService->stubbedToolResult = $refusedResult;
        $conversation = $this->conversation($this->ownerRow(0));

        $firstCallRow = $this->writeCallRow(1);
        $conversation->messages->add($firstCallRow);
        $this->dispatch($conversation, $firstCallRow, $dispatchService);
        $secondCallRow = $this->writeCallRow(2);
        $conversation->messages->add($secondCallRow);
        $this->dispatch($conversation, $secondCallRow, $dispatchService);
        self::assertTrue($secondCallRow->toolCall->wasRefusedAsDuplicate, 'call 2 is answered with the refusal of call 1');

        $signalRow = $this->row(AIConversationMessage::ROLE_SYSTEM, AIConversationMessage::AUTHOR_AGENT, 'Your text was not accepted as the final answer.', 3);
        $signalRow->messageType = AIConversationMessage::MESSAGE_SIGNAL;
        $conversation->messages->add($signalRow);

        $thirdCallRow = $this->writeCallRow(4);
        $conversation->messages->add($thirdCallRow);
        $thirdCallAnswer = $this->dispatch($conversation, $thirdCallRow, $dispatchService);

        self::assertSame(['publishOffer', 'publishOffer'], $dispatchService->executedToolNames, 'call 3 ran');
        self::assertFalse($thirdCallRow->toolCall->wasRefusedAsDuplicate, 'the executor answered call 3, not the repeat guard');
        self::assertTrue($thirdCallRow->toolCall->isRefusedAsFinal());
        self::assertSame(AIConversationMessage::STATUS_FAILED, $thirdCallRow->toolCall->status);
        self::assertFalse($thirdCallAnswer->repeatedToolCallAbort);
        self::assertStringNotContainsString('You have already called', (string)$thirdCallRow->toolCall->result);

        foreach ([5, 6] as $index) {
            $repeatRow = $this->writeCallRow($index);
            $conversation->messages->add($repeatRow);

            $agentToolCallAnswer = $this->dispatch($conversation, $repeatRow, $dispatchService);

            $case = 'row ' . $index;
            self::assertTrue($repeatRow->toolCall->wasRefusedAsDuplicate, $case);
            self::assertTrue($repeatRow->toolCall->isRefusedAsFinal(), $case);
            self::assertTrue($agentToolCallAnswer->forceToolLessAnswerStep, $case);
            self::assertFalse($agentToolCallAnswer->repeatedToolCallAbort, $case);
            self::assertStringStartsWith('NOT executed: `publishOffer` with these arguments was already refused in this turn, and that refusal is final', (string)$repeatRow->toolCall->result, $case);
        }
        self::assertSame(['publishOffer', 'publishOffer'], $dispatchService->executedToolNames, 'the tool ran twice: once before and once after the signal row');
    }

    public function testTheRepeatOfAGateRefusalThatMayOpenIsNotAnsweredByTheFinalRefusalBranch(): void
    {
        $refusedCallRow = $this->refusedCallRow(false, 1);
        $repeatRow = $this->writeCallRow(2);
        $conversation = $this->conversation($this->ownerRow(0), $refusedCallRow, $repeatRow);

        $agentToolCallAnswer = $this->dispatch($conversation, $repeatRow);

        self::assertFalse($agentToolCallAnswer->forceToolLessAnswerStep, 'the sanctioned retry keeps the tools');
        self::assertFalse($repeatRow->toolCall->wasRefusedAsDuplicate);
        self::assertFalse($repeatRow->toolCall->isRefusedAsFinal());
        self::assertStringNotContainsString('that refusal is final', (string)$repeatRow->toolCall->result);
    }

    /**
     * A DELETE whose identical call already completed in the conversation: its failure is rewritten to "already
     * completed", and a final refusal it carried does not stay on the call.
     */
    public function testADeleteRewrittenToAlreadyCompletedKeepsNoFinalRefusal(): void
    {
        // an earlier turn deleted offer 4, then offer 5; the owner's next turn deletes offer 4 again
        $completedDeleteRow = $this->deleteCallRow(1);
        $completedDeleteRow->toolCall->markCompleted();
        $otherDeleteRow = $this->deleteCallRow(2);
        $otherDeleteRow->toolCall->arguments = ['offerId' => 5];
        $otherDeleteRow->toolCall->markCompleted();
        $repeatedDeleteRow = $this->deleteCallRow(4);
        $dispatchService = new ExecutionRecordingDispatchService();
        $refusedResult = new ToolResult(self::REFUSAL_TEXT, true);
        $refusedResult->refusalIsFinal = true;
        $dispatchService->stubbedToolResult = $refusedResult;

        $this->dispatch($this->conversation($this->ownerRow(0), $completedDeleteRow, $otherDeleteRow, $this->ownerRow(3), $repeatedDeleteRow), $repeatedDeleteRow, $dispatchService);

        self::assertSame(['deleteOffer'], $dispatchService->executedToolNames);
        self::assertSame(AIConversationMessage::STATUS_COMPLETED, $repeatedDeleteRow->toolCall->status);
        self::assertStringStartsWith('This `deleteOffer` already COMPLETED earlier', (string)$repeatedDeleteRow->toolCall->result);
        self::assertFalse($repeatedDeleteRow->toolCall->isRefusedAsFinal());
    }

    protected function deleteCallRow(int $index): AIConversationMessage
    {
        $message = $this->writeCallRow($index);
        $message->toolCall->name = 'deleteOffer';
        $message->toolCall->stampEffect(new MCPToolEffect(MCPToolEffect::DELETE));
        return $message;
    }

    protected function dispatch(AIConversation $conversation, AIConversationMessage $callRow, ?AgentToolCallDispatchService $dispatchService = null): AgentToolCallAnswer
    {
        return ($dispatchService ?? new AgentToolCallDispatchService())->dispatchToolCall(
            AgentRun::forTurnEntry(),
            $this->createStub(AgentChildTurnRunnerInterface::class),
            $conversation,
            $callRow->toolCall,
            $callRow,
            false,
            null,
            AgentTransientMessageIds::startingAt(0),
            null,
            false
        );
    }

    protected function writeCallRow(int $index): AIConversationMessage
    {
        $message = $this->row(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, '', $index);
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $toolCall = new ToolCall();
        $toolCall->toolCallId = 'call_' . $index;
        $toolCall->name = 'publishOffer';
        $toolCall->arguments = ['offerId' => 4];
        $toolCall->stampEffect(new MCPToolEffect(MCPToolEffect::WRITE));
        $message->toolCall = $toolCall;
        return $message;
    }

    protected function refusedCallRow(bool $refusalIsFinal, int $index): AIConversationMessage
    {
        $message = $this->writeCallRow($index);
        $refusedResponse = $refusalIsFinal
            ? MCPToolResponse::finalRefusal(self::REFUSAL_TEXT, MCPToolResponse::REFUSAL_MANDATE_REQUIRED)
            : MCPToolResponse::gateRefusal(self::REFUSAL_TEXT, MCPToolResponse::REFUSAL_MANDATE_REQUIRED);
        $refusedResult = new ToolResult(json_encode(['content' => $refusedResponse->content, 'isError' => true], JSON_THROW_ON_ERROR), true);
        $refusedResult->refusalKind = $refusedResponse->refusalKind;
        $refusedResult->refusalIsFinal = $refusedResponse->isFinalRefusal();
        $message->toolCall->applyExecutionOutcome($refusedResult);
        $message->toolCall->result = $refusedResult->content;
        return $message;
    }

    protected function ownerRow(int $index): AIConversationMessage
    {
        return $this->row(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'publish the offer', $index);
    }

    protected function row(string $role, string $authorType, string $text, int $index): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->role = $role;
        $message->authorType = $authorType;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->message = $text;
        $message->indexInConversation = $index;
        return $message;
    }

    protected function conversation(AIConversationMessage ...$rows): AIConversation
    {
        $messages = new AIConversationMessages();
        foreach ($rows as $row) {
            $messages->add($row);
        }
        /** @var AIConversation $conversation */
        $conversation = self::instantiateWithoutConstructor(AIConversation::class);
        $conversation->messages = $messages;
        return $conversation;
    }
}
