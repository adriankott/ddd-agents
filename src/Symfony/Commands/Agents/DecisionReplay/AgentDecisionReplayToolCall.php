<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\DecisionReplay;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One stored tool call whose decision is replayed: the conversation it was made in, the message that carries it, the
 * messages that came before it (the conversation as it stood when the call was made) and whom the replayed decision
 * is made for.
 *
 * A plain object of one command run; no container service.
 */
#[Exclude]
class AgentDecisionReplayToolCall
{
    /**
     * @param AIConversation $conversation the stored conversation
     * @param AIConversationMessage $callMessage the message that carries the tool call
     * @param AIConversationMessages $messagesBeforeCall every message of the conversation with a lower index, in order
     * @param AgentDecisionReplayScope $agentDecisionReplayScope
     */
    public function __construct(
        public readonly AIConversation $conversation,
        public readonly AIConversationMessage $callMessage,
        public readonly AIConversationMessages $messagesBeforeCall,
        public readonly AgentDecisionReplayScope $agentDecisionReplayScope,
    ) {
    }

    /** The tool call of the message. */
    public function getToolCall(): ToolCall
    {
        return $this->callMessage->toolCall;
    }

    /** The name of the called tool; empty when the call names none. */
    public function getToolName(): string
    {
        return (string)($this->callMessage->toolCall->name ?? '');
    }

    /** The index of the call's message in its conversation (-1 when it has none). */
    public function getCallIndex(): int
    {
        return (int)($this->callMessage->indexInConversation ?? -1);
    }

    /** The key of the replayed decision's row: `<conversation id>:<index of the call>`. */
    public function buildRowKey(): string
    {
        return $this->conversation->id . ':' . $this->getCallIndex();
    }
}
