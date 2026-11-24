<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\Client\Agents;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallImageReference;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultImage;
use DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallImageStoreService;
use DDD\Presentation\Mcp\Client\Agents\AgentImagesMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentEarlierToolImageViewRequestDto;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\TieredAgentToolCallImageStoreServiceDouble;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\ToolPictureFixtures;
use DDD\Tests\Agents\Support\IntegrationTestCase;

/**
 * `viewEarlierToolImage` attaches a picture of an earlier result again from the picture store: the same reference
 * (store, id, durability), the trust of the original call, never stored a second time; an unknown or gone picture is a
 * tool error that says what to do.
 */
class AgentImagesMCPPackageTest extends IntegrationTestCase
{
    protected TieredAgentToolCallImageStoreServiceDouble $storeDouble;

    /** The picture the last {@see buildConversationWithAStoredPicture()} stored */
    protected ToolResultImage $storedToolResultImage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storeDouble = TieredAgentToolCallImageStoreServiceDouble::withTiers(false, true, null);
        $this->replaceService(AgentToolCallImageStoreService::class, $this->storeDouble);
    }

    public function testAPictureOfAnEarlierResultIsAttachedAgainWithItsReferenceAndTrust(): void
    {
        $provenance = $this->buildConversationWithAStoredPicture(false);
        $toolResultImage = $this->storedToolResultImage;

        $mcpToolResponse = (new AgentImagesMCPPackage())->viewEarlierToolImage(static::buildRequest(substr($toolResultImage->sha256, 0, 12)), $provenance);

        self::assertFalse($mcpToolResponse->isError);
        $imagePart = $mcpToolResponse->content[1];
        self::assertSame('image', $imagePart['type']);
        self::assertSame($toolResultImage->base64Data, $imagePart['data']);
        self::assertSame(ToolCallImageReference::SOURCE_STORE_SPOOL, $imagePart['sourceStore']);
        self::assertSame($toolResultImage->sourceImageId, $imagePart['sourceImageId']);
        self::assertFalse($mcpToolResponse->getDeclaredTrustedContent(), 'a screenshot of a foreign page stays untrusted');
        $reDecoded = ToolResultImage::fromMcpImageContentPart($imagePart);
        self::assertSame(ToolCallImageReference::SOURCE_STORE_SPOOL, $reDecoded?->sourceStore, 'the store does not take it a second time');

        $trustedProvenance = $this->buildConversationWithAStoredPicture(true);
        $trustedImage = $this->storedToolResultImage;
        $trustedResponse = (new AgentImagesMCPPackage())->viewEarlierToolImage(static::buildRequest(substr($trustedImage->sha256, 0, 12)), $trustedProvenance);
        self::assertTrue($trustedResponse->getDeclaredTrustedContent());
    }

    public function testAnUnknownOrGoneOrMalformedImageIdIsAToolError(): void
    {
        $provenance = $this->buildConversationWithAStoredPicture(false);
        $toolResultImage = $this->storedToolResultImage;
        $agentImagesMCPPackage = new AgentImagesMCPPackage();

        self::assertTrue($agentImagesMCPPackage->viewEarlierToolImage(static::buildRequest('abc'), $provenance)->isError);
        self::assertTrue($agentImagesMCPPackage->viewEarlierToolImage(static::buildRequest('0123456789ab'), $provenance)->isError);

        $this->storeDouble->sharedCache->clear();
        $goneResponse = $agentImagesMCPPackage->viewEarlierToolImage(static::buildRequest(substr($toolResultImage->sha256, 0, 12)), $provenance);
        self::assertTrue($goneResponse->isError);
        self::assertStringContainsString('browserScreenshot', json_encode($goneResponse->content));
    }

    /** The provenance of a conversation whose one earlier result holds a stored picture ({@see $storedToolResultImage}). */
    protected function buildConversationWithAStoredPicture(bool $returnedTrustedContent): AgentToolCallProvenance
    {
        $conversation = new AIConversation();
        $conversation->id = 4711;
        $conversation->agentSpaceId = 31;
        $toolResult = ToolPictureFixtures::buildToolResultWithPicture(ToolPictureFixtures::buildPngBase64(40 + (int)$returnedTrustedContent, 30));
        $this->storeDouble->storeInlineToolResultImages($toolResult, $conversation);
        $toolCall = new ToolCall();
        $toolCall->toolCallId = 'call_viewport';
        $toolCall->name = 'browserScreenshot';
        $toolCall->result = $toolResult->content;
        $toolCall->status = AIConversationMessage::STATUS_COMPLETED;
        $toolCall->applyResultImages($toolResult);
        $toolCall->returnedUntrustedContent = !$returnedTrustedContent;
        $message = new AIConversationMessage();
        $message->indexInConversation = 1;
        $message->toolCall = $toolCall;
        $conversationMessages = new AIConversationMessages();
        $conversationMessages->add($message);
        $this->storedToolResultImage = $toolResult->images[0];
        return new AgentToolCallProvenance(conversationId: 4711, conversationMessages: $conversationMessages, conversation: $conversation);
    }

    protected static function buildRequest(string $imageId): AgentEarlierToolImageViewRequestDto
    {
        $requestDto = new AgentEarlierToolImageViewRequestDto();
        $requestDto->imageId = $imageId;
        return $requestDto;
    }
}
