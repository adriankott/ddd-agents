<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A conversation created without knowing its model (the console chat, an evaluation run) carries no strategy under the
 * append-only default; the run stamps it with the wire API of its model before the first call — also when the
 * conversation is ephemeral (no id), which is stamped in memory only.
 */
class UnstampedEphemeralConversationIsStampedByItsRunTest extends UnitTestCase
{
    public function testAnEphemeralConversationOnChatCompletionsIsStampedWithTheSwap(): void
    {
        $conversation = static::buildEphemeralConversationWithoutModelKnownAtCreation();

        (new AIConversationsServiceWithFixedAgentWireApiDouble(AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS))->stampCreationSettingsOfUnstampedConversation($conversation);

        self::assertSame(ContextRetentionSettings::SKILL_LOADING_STRATEGY_SYSTEM_PROMPT_SWAP, $conversation->contextRetentionSettings->skillLoadingStrategy);
        self::assertFalse($conversation->usesConversationAppend());
    }

    public function testAnEphemeralConversationOnTheResponsesApiKeepsTheAppendOnlyDefault(): void
    {
        $conversation = static::buildEphemeralConversationWithoutModelKnownAtCreation();

        (new AIConversationsServiceWithFixedAgentWireApiDouble(AIModel::AGENT_WIRE_API_RESPONSES))->stampCreationSettingsOfUnstampedConversation($conversation);

        self::assertSame(ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND, $conversation->contextRetentionSettings->skillLoadingStrategy);
    }

    protected static function buildEphemeralConversationWithoutModelKnownAtCreation(): AIConversation
    {
        $conversation = AIConversation::newInstance();
        $conversation->messages = AIConversationMessages::newInstance();
        $conversation->contextRetentionSettings = ContextRetentionSettings::buildForNewConversation(null, null);
        self::assertNull($conversation->contextRetentionSettings->skillLoadingStrategy);
        return $conversation;
    }
}
