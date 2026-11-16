<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Domain\AI\Entities\Evals\MessagesWithLoadedSkillsDouble;

/**
 * A driven conversation of the selection, recall and tiers commands' tests: the owner asks, the agent calls one tool
 * and answers; the skills it loaded are given by the test (a real row reads them from a stored skill catalog).
 */
class AgentEvalCaseCommandsConversationDouble extends AIConversation
{
    /** @var string[] The skills the conversation reports as loaded */
    public array $loadedSkillNamesOfTest = [];

    /**
     * The conversation of one owner prompt: one call of `$toolName` (finished with `$toolStatus`) and the answer
     * `$answerText`.
     *
     * @param string[] $loadedSkillNames
     */
    public static function forTurn(string $prompt, string $toolName, string $toolStatus, string $answerText, array $loadedSkillNames): static
    {
        $conversation = new static();
        $conversation->loadedSkillNamesOfTest = $loadedSkillNames;
        $conversation->messages = new AIConversationMessages();
        $ownerMessage = static::buildMessage(AIConversationMessage::ROLE_USER, AIConversationMessage::MESSAGE_TEXT, $prompt, 0, 0.0);
        $ownerMessage->authorType = AIConversationMessage::AUTHOR_USER;
        $toolCallMessage = static::buildMessage(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::MESSAGE_TOOL_CALL, '', 100, 0.001);
        $toolCall = new ToolCall();
        $toolCall->name = $toolName;
        $toolCall->arguments = [];
        $toolCall->status = $toolStatus;
        $toolCallMessage->toolCall = $toolCall;
        $toolCallMessage->status = $toolStatus;
        $answerMessage = static::buildMessage(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::MESSAGE_TEXT, $answerText, 200, 0.002);
        $answerMessage->authorType = AIConversationMessage::AUTHOR_ASSISTANT;
        foreach ([$ownerMessage, $toolCallMessage, $answerMessage] as $index => $message) {
            $message->indexInConversation = $index;
            $conversation->messages->add($message);
        }
        return $conversation;
    }

    /** The messages of the conversation, reporting the test's loaded skills. */
    public function getAllMessagesIncludingDelegations(array &$visitedConversationObjectIds = []): AIConversationMessages
    {
        $messagesWithLoadedSkills = new MessagesWithLoadedSkillsDouble();
        foreach (parent::getAllMessagesIncludingDelegations($visitedConversationObjectIds)->getElements() as $message) {
            $messagesWithLoadedSkills->add($message);
        }
        $messagesWithLoadedSkills->loadedSkillNamesOfTest = $this->loadedSkillNamesOfTest;
        return $messagesWithLoadedSkills;
    }

    protected static function buildMessage(string $role, string $messageType, string $text, int $inputTokens, float $costInUSD): AIConversationMessage
    {
        $message = new AIConversationMessage();
        $message->role = $role;
        $message->messageType = $messageType;
        $message->message = $text;
        $message->inputTokens = $inputTokens;
        $message->outputTokens = 0;
        $message->cachedInputTokens = 0;
        $message->costInUSD = $costInUSD;
        $message->toolCall = null;
        $message->getChangeHistory()->createdTime = new DateTime('2026-10-01 10:00:0' . $inputTokens % 9);
        return $message;
    }
}
