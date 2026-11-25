<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\AI;

use DDD\Domain\AI\Entities\ClientTools\AgentClientToolAnswer;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Services\ClientTools\AgentClientToolRequestsService;
use DDD\Presentation\Mcp\Client\Agents\AgentUIMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentUIToolResponseDto;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\ToolPictureFixtures;
use DDD\Presentation\Mcp\Client\Agents\AgentUIWindowMCPPackage;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * `Agent.UI`: at most `AI.agentUI.maxOpenScreenPerUserTurn` screens per message of the user (the calls after the
 * user's message, a failed one not counted, the call itself left out); a switched-off request type answers "not
 * enabled"; the window-mode tools are offered only while the host switches them on; the picture limits are stated.
 */
class AgentUIMCPPackageNavigationCapTest extends UnitTestCase
{
    public function testOnlyTheOpenScreenCallsAfterTheUsersMessageCount(): void
    {
        $messages = new AIConversationMessages();
        $sourceUserMessage = static::buildMessage(4, AIConversationMessage::MESSAGE_TEXT);
        $sourceUserMessage->role = AIConversationMessage::ROLE_USER;
        foreach ([
            static::buildMessage(2, AIConversationMessage::MESSAGE_TOOL_CALL, 'openScreen', 'call-old'),
            $sourceUserMessage,
            static::buildMessage(5, AIConversationMessage::MESSAGE_TOOL_CALL, 'openScreen', 'call-a'),
            static::buildMessage(6, AIConversationMessage::MESSAGE_TOOL_CALL, 'agent.ui.openScreen', 'call-failed', AIConversationMessage::STATUS_FAILED),
            static::buildMessage(7, AIConversationMessage::MESSAGE_TOOL_CALL, 'listScreens', 'call-list'),
            static::buildMessage(8, AIConversationMessage::MESSAGE_TOOL_CALL, 'openScreen', 'call-now'),
        ] as $conversationMessage) {
            $messages->add($conversationMessage);
        }
        $provenance = new AgentToolCallProvenance($sourceUserMessage, null, 70, null, 70, $messages);
        $provenance->toolCallId = 'call-now';

        self::assertSame(1, AgentUIMCPPackage::countOpenScreenCallsOfUserTurn($provenance));
        self::assertSame(0, AgentUIMCPPackage::countOpenScreenCallsOfUserTurn(new AgentToolCallProvenance()), 'no owner thread: nothing to count');
        self::assertSame(1, AgentUIMCPPackage::getMaxOpenScreenPerUserTurn());
    }

    public function testASwitchedOffTypeAnswersNotEnabledAndTheWindowToolsAreOffByDefault(): void
    {
        $notEnabledResponse = AgentUIMCPPackage::buildUnansweredResponse(AgentClientToolAnswer::ofKind(AgentClientToolAnswer::KIND_NOT_ENABLED), 8, false);

        self::assertSame(AgentClientToolAnswer::KIND_NOT_ENABLED, $notEnabledResponse->outcome);
        self::assertSame([AgentUIMCPPackage::NOT_ENABLED], $notEnabledResponse->lines);
        self::assertFalse(AgentUIWindowMCPPackage::isAvailable());
        self::assertNotEmpty(AgentUIMCPPackage::PICTURE_LIMITATIONS);
        self::assertSame(['small', 'medium', 'large'], AgentUIMCPPackage::PICTURE_SIZES);
        self::assertSame(['colour', 'greyscale', 'machine'], AgentUIMCPPackage::RENDER_MODES);
    }

    public function testAScreenPictureRidesAsAnImagePartOfTheResponse(): void
    {
        $responseDto = new AgentUIToolResponseDto();
        self::assertSame([], $responseDto->getResponseImages());
        $agentScreenPicture = AgentClientToolRequestsService::checkScreenPicture(ToolPictureFixtures::buildPngBase64(40, 30));
        $responseDto->setScreenPicture($agentScreenPicture);

        $responseImages = $responseDto->getResponseImages();

        self::assertCount(1, $responseImages);
        self::assertSame('image/png', $responseImages[0]->mimeType);
        self::assertSame($agentScreenPicture->base64Data, $responseImages[0]->base64Data);
        self::assertStringNotContainsString($agentScreenPicture->base64Data, (string)json_encode($responseDto->toObject()), 'never in the text or the structured result');
    }

    protected static function buildMessage(int $index, string $messageType, ?string $toolName = null, ?string $toolCallId = null, string $status = AIConversationMessage::STATUS_COMPLETED): AIConversationMessage
    {
        $conversationMessage = new AIConversationMessage();
        $conversationMessage->id = 1000 + $index;
        $conversationMessage->indexInConversation = $index;
        $conversationMessage->messageType = $messageType;
        $conversationMessage->role = AIConversationMessage::ROLE_ASSISTANT;
        if ($toolName !== null) {
            $toolCall = new ToolCall();
            $toolCall->name = $toolName;
            $toolCall->toolCallId = $toolCallId;
            $toolCall->status = $status;
            $conversationMessage->toolCall = $toolCall;
        }
        return $conversationMessage;
    }
}
