<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentToolCallFork;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Probes\OutcomeStubbingForkJoinService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The join of a forked call and a final refusal: the flag the child stored is taken onto the loop's call, so the
 * identical repeat of the turn is answered without running; a DELETE whose failure is rewritten to "already completed"
 * keeps no final refusal.
 */
class AgentToolCallForkJoinFinalRefusalTest extends UnitTestCase
{
    protected const string REFUSAL_TEXT = "Tool 'publishOffer' is not permitted for this user account. Do not retry.";

    public function testTheJoinTakesTheFinalRefusalOfTheChild(): void
    {
        $callRow = $this->callRow('publishOffer', MCPToolEffect::WRITE, 1);
        $agentToolCallForkJoinService = $this->forkJoinWithOutcome($this->refusedOutcome('publishOffer', MCPToolEffect::WRITE));

        $agentToolCallForkJoinService->adoptForkedToolCallOutcome(new AgentToolCallFork(), $this->conversation($callRow), $callRow->toolCall, $callRow);

        self::assertSame(AIConversationMessage::STATUS_FAILED, $callRow->toolCall->status);
        self::assertSame(self::REFUSAL_TEXT, $callRow->toolCall->result);
        self::assertTrue($callRow->toolCall->isRefusedAsFinal());
    }

    public function testADeleteRewrittenToAlreadyCompletedKeepsNoFinalRefusal(): void
    {
        // an earlier turn deleted offer 4; the forked call deletes it again and the executor refuses it finally
        $completedDeleteRow = $this->callRow('deleteOffer', MCPToolEffect::DELETE, 1);
        $completedDeleteRow->toolCall->markCompleted();
        $callRow = $this->callRow('deleteOffer', MCPToolEffect::DELETE, 3);
        $agentToolCallForkJoinService = $this->forkJoinWithOutcome($this->refusedOutcome('deleteOffer', MCPToolEffect::DELETE));

        $agentToolCallForkJoinService->adoptForkedToolCallOutcome(new AgentToolCallFork(), $this->conversation($completedDeleteRow, $this->ownerRow(2), $callRow), $callRow->toolCall, $callRow);

        self::assertSame(AIConversationMessage::STATUS_COMPLETED, $callRow->toolCall->status);
        self::assertStringStartsWith('This `deleteOffer` already COMPLETED earlier', (string)$callRow->toolCall->result);
        self::assertFalse($callRow->toolCall->isRefusedAsFinal());
        self::assertNull($callRow->toolCall->wasRefusedAsFinal, 'no key on the stored row');
    }

    protected function forkJoinWithOutcome(ToolCall $outcome): OutcomeStubbingForkJoinService
    {
        /** @var OutcomeStubbingForkJoinService $agentToolCallForkJoinService */
        $agentToolCallForkJoinService = self::instantiateWithoutConstructor(OutcomeStubbingForkJoinService::class);
        $agentToolCallForkJoinService->stubbedOutcome = $outcome;
        return $agentToolCallForkJoinService;
    }

    /** The row the child wrote: FAILED, refused finally by the executor without a gate kind. */
    protected function refusedOutcome(string $toolName, string $effect): ToolCall
    {
        $outcome = $this->toolCall($toolName, $effect, 'call_outcome');
        $outcome->markFailed();
        $outcome->result = self::REFUSAL_TEXT;
        $outcome->wasRefusedAsFinal = true;
        return $outcome;
    }

    protected function toolCall(string $toolName, string $effect, string $toolCallId): ToolCall
    {
        $toolCall = new ToolCall();
        $toolCall->toolCallId = $toolCallId;
        $toolCall->name = $toolName;
        $toolCall->arguments = ['offerId' => 4];
        $toolCall->stampEffect(new MCPToolEffect($effect));
        return $toolCall;
    }

    protected function callRow(string $toolName, string $effect, int $index): AIConversationMessage
    {
        $message = $this->row(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, '', $index);
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $message->toolCall = $this->toolCall($toolName, $effect, 'call_' . $index);
        return $message;
    }

    protected function ownerRow(int $index): AIConversationMessage
    {
        return $this->row(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'delete the offer', $index);
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
