<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * An append-only conversation whose owner picks a Chat Completions model mid-conversation moves to the swap before the
 * run's wire configuration is resolved (append-only on Chat Completions is refused); nothing else changes.
 */
class AppendOnlyConversationSwitchedToChatCompletionsModelTest extends UnitTestCase
{
    public function testAnAppendOnlyConversationOnAChatCompletionsModelSwitchesToTheSwap(): void
    {
        $conversation = static::buildConversationStamped(ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND);

        $switched = (new AIConversationsServiceWithFixedAgentWireApiDouble(AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS))
            ->switchToSystemPromptSwapForChatCompletionsModel($conversation, AIModel::MODEL_ANTHROPIC_CLAUDE_OPUS_5_5);

        self::assertTrue($switched);
        self::assertFalse($conversation->usesConversationAppend());
        self::assertSame(ContextRetentionSettings::SKILL_LOADING_STRATEGY_SYSTEM_PROMPT_SWAP, $conversation->contextRetentionSettings->skillLoadingStrategy);
    }

    public function testAnAppendOnlyConversationOnTheResponsesApiKeepsItsStrategy(): void
    {
        $conversation = static::buildConversationStamped(ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND);

        $switched = (new AIConversationsServiceWithFixedAgentWireApiDouble(AIModel::AGENT_WIRE_API_RESPONSES))
            ->switchToSystemPromptSwapForChatCompletionsModel($conversation, AIModel::MODEL_OPENAI_GPT6_LUNA);

        self::assertFalse($switched);
        self::assertTrue($conversation->usesConversationAppend());
    }

    public function testAStrategyNamedAtCreationIsNotChangedBeforeTheFirstCall(): void
    {
        $conversation = static::buildConversationStamped(ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND, false);

        self::assertFalse((new AIConversationsServiceWithFixedAgentWireApiDouble(AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS))
            ->switchToSystemPromptSwapForChatCompletionsModel($conversation, AIModel::MODEL_ANTHROPIC_CLAUDE_OPUS_5_5));
        self::assertTrue($conversation->usesConversationAppend());
    }

    public function testASwapConversationStaysOnTheSwapWhateverTheModel(): void
    {
        foreach ([AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS, AIModel::AGENT_WIRE_API_RESPONSES] as $agentWireApi) {
            $conversation = static::buildConversationStamped(ContextRetentionSettings::SKILL_LOADING_STRATEGY_SYSTEM_PROMPT_SWAP);

            self::assertFalse((new AIConversationsServiceWithFixedAgentWireApiDouble($agentWireApi))->switchToSystemPromptSwapForChatCompletionsModel($conversation, null));
            self::assertSame(ContextRetentionSettings::SKILL_LOADING_STRATEGY_SYSTEM_PROMPT_SWAP, $conversation->contextRetentionSettings->skillLoadingStrategy);
        }
    }

    protected static function buildConversationStamped(string $skillLoadingStrategy, bool $madeAModelCall = true): AIConversation
    {
        $conversation = AIConversation::newInstance();
        $conversation->messages = AIConversationMessages::newInstance();
        $conversation->contextRetentionSettings = ContextRetentionSettings::buildForNewConversation(null, null);
        $conversation->contextRetentionSettings->skillLoadingStrategy = $skillLoadingStrategy;
        if ($madeAModelCall) {
            $earlierCallRow = AIConversationMessage::newInstance();
            $earlierCallRow->inputTokens = 1200;
            $conversation->messages->add($earlierCallRow);
        }
        return $conversation;
    }
}
