<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A settings update of an existing conversation keeps the stamped tool delivery and the stamped cold-skill drop when
 * it leaves them unset. A different tool delivery is refused once the conversation made a model call (an unstamped
 * conversation runs native: only `native` is accepted then); before it, the change is accepted. The drop switch may
 * change at any time. The conversations are ephemeral (no id), so "made a model call" is read from their rows.
 */
class KeepStampedToolDeliveryAndColdSkillDropTest extends UnitTestCase
{
    public function testAnUpdateLeavingBothUnsetKeepsTheStamps(): void
    {
        $conversation = $this->buildConversation(ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL, true, hasModelCall: true);

        $updatedSettings = $this->buildAIConversationsService()->keepStampedSkillLoadingStrategy($conversation, ContextRetentionSettings::newInstance());

        self::assertSame(ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL, $updatedSettings->toolDelivery);
        self::assertTrue($updatedSettings->dropColdSkillsAtCompactionRebase);
    }

    public function testAClearedRowKeepsTheStamps(): void
    {
        $conversation = $this->buildConversation(ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL_JSON_SCHEMA, false, hasModelCall: true);

        $updatedSettings = $this->buildAIConversationsService()->keepStampedSkillLoadingStrategy($conversation, null);

        self::assertNotNull($updatedSettings);
        self::assertSame(ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL_JSON_SCHEMA, $updatedSettings->toolDelivery);
        self::assertFalse($updatedSettings->dropColdSkillsAtCompactionRebase);
    }

    public function testTheSameToolDeliveryIsAcceptedAfterTheFirstModelCall(): void
    {
        $conversation = $this->buildConversation(ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL, true, hasModelCall: true);
        $requestedSettings = ContextRetentionSettings::newInstance();
        $requestedSettings->toolDelivery = 'CALLTOOL';

        $updatedSettings = $this->buildAIConversationsService()->keepStampedSkillLoadingStrategy($conversation, $requestedSettings);

        self::assertSame(ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL, $updatedSettings->toolDelivery);
    }

    public function testADifferentToolDeliveryIsRefusedAfterTheFirstModelCall(): void
    {
        $conversation = $this->buildConversation(ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL, true, hasModelCall: true);
        $requestedSettings = ContextRetentionSettings::newInstance();
        $requestedSettings->toolDelivery = ContextRetentionSettings::TOOL_DELIVERY_NATIVE;

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage("toolDelivery cannot change after the conversation's first model call (in effect: callTool)");
        $this->buildAIConversationsService()->keepStampedSkillLoadingStrategy($conversation, $requestedSettings);
    }

    public function testAnUnstampedConversationWithAModelCallAcceptsOnlyNative(): void
    {
        $conversation = $this->buildConversation(null, null, hasModelCall: true);
        $nativeSettings = ContextRetentionSettings::newInstance();
        $nativeSettings->toolDelivery = ContextRetentionSettings::TOOL_DELIVERY_NATIVE;

        $updatedSettings = $this->buildAIConversationsService()->keepStampedSkillLoadingStrategy($conversation, $nativeSettings);
        self::assertSame(ContextRetentionSettings::TOOL_DELIVERY_NATIVE, $updatedSettings->toolDelivery);

        $callToolSettings = ContextRetentionSettings::newInstance();
        $callToolSettings->toolDelivery = ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL;
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('(in effect: native)');
        $this->buildAIConversationsService()->keepStampedSkillLoadingStrategy($conversation, $callToolSettings);
    }

    public function testAnUnstampedConversationLeftUnsetStaysUnstamped(): void
    {
        $conversation = $this->buildConversation(null, null, hasModelCall: true);

        $updatedSettings = $this->buildAIConversationsService()->keepStampedSkillLoadingStrategy($conversation, ContextRetentionSettings::newInstance());

        self::assertNull($updatedSettings->toolDelivery, 'no stamp is invented for a conversation that predates it');
        self::assertNull($updatedSettings->dropColdSkillsAtCompactionRebase);
    }

    public function testADifferentToolDeliveryIsAcceptedBeforeTheFirstModelCall(): void
    {
        $conversation = $this->buildConversation(ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL, true, hasModelCall: false);
        $requestedSettings = ContextRetentionSettings::newInstance();
        $requestedSettings->toolDelivery = 'native';

        $updatedSettings = $this->buildAIConversationsService()->keepStampedSkillLoadingStrategy($conversation, $requestedSettings);

        self::assertSame(ContextRetentionSettings::TOOL_DELIVERY_NATIVE, $updatedSettings->toolDelivery);
    }

    public function testTheDropSwitchMayChangeAfterTheFirstModelCall(): void
    {
        $conversation = $this->buildConversation(ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL, true, hasModelCall: true);
        $requestedSettings = ContextRetentionSettings::newInstance();
        $requestedSettings->dropColdSkillsAtCompactionRebase = false;

        $updatedSettings = $this->buildAIConversationsService()->keepStampedSkillLoadingStrategy($conversation, $requestedSettings);

        self::assertFalse($updatedSettings->dropColdSkillsAtCompactionRebase);
        self::assertSame(ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL, $updatedSettings->toolDelivery, 'the delivery stamp stays beside it');
        self::assertNull($requestedSettings->toolDelivery, 'the requested settings are not changed');
    }

    protected function buildAIConversationsService(): AIConversationsServiceWithFixedAgentWireApiDouble
    {
        return new AIConversationsServiceWithFixedAgentWireApiDouble(AIModel::AGENT_WIRE_API_RESPONSES);
    }

    protected function buildConversation(?string $stampedToolDelivery, ?bool $stampedDropColdSkillsAtCompactionRebase, bool $hasModelCall): AIConversation
    {
        $conversation = AIConversation::newInstance();
        $conversation->messages = AIConversationMessages::newInstance();
        if ($hasModelCall) {
            /** @var AIConversationMessage $answeredRow */
            $answeredRow = self::instantiateWithoutConstructor(AIConversationMessage::class);
            $answeredRow->messageType = AIConversationMessage::MESSAGE_TEXT;
            $answeredRow->role = AIConversationMessage::ROLE_ASSISTANT;
            $answeredRow->authorType = AIConversationMessage::AUTHOR_ASSISTANT;
            $answeredRow->indexInConversation = 1;
            $answeredRow->inputTokens = 1200;
            $conversation->messages->add($answeredRow);
        }
        $storedSettings = ContextRetentionSettings::newInstance();
        $storedSettings->skillLoadingStrategy = ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND;
        $storedSettings->toolDelivery = $stampedToolDelivery;
        $storedSettings->dropColdSkillsAtCompactionRebase = $stampedDropColdSkillsAtCompactionRebase;
        $conversation->contextRetentionSettings = $storedSettings;
        return $conversation;
    }
}
