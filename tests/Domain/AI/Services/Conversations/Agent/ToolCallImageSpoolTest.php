<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallImageReference;
use DDD\Domain\AI\Entities\MCPPackages\ToolResult;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultImage;
use DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallImageSpoolService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use DDD\Tests\Agents\Support\IntegrationTestCase;

/**
 * A picture without a store of its own is spooled when its call completes, so the next worker invocation (no
 * transient carry) re-reads the same base64 text: the tool result's wire entry is byte-identical on both sides of the
 * boundary. Without the spool the second invocation sends the dropped marker instead (the history changes there).
 */
class ToolCallImageSpoolTest extends IntegrationTestCase
{
    protected const string PNG_1X1_BASE64 =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected InMemoryAgentToolCallImageSpoolServiceDouble $spoolDouble;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spoolDouble = new InMemoryAgentToolCallImageSpoolServiceDouble();
        $this->replaceService(AgentToolCallImageSpoolService::class, $this->spoolDouble);
    }

    public function testTheToolResultIsByteIdenticalAcrossAWorkerBoundary(): void
    {
        $toolResult = static::buildToolResultWithPicture();
        $this->spoolDouble->spoolInlineToolResultImages($toolResult, static::buildConversation());
        $toolCall = static::buildCompletedToolCall($toolResult);

        $reference = $toolCall->imageReferences[0];
        self::assertSame(ToolCallImageReference::SOURCE_STORE_SPOOL, $reference->sourceStore);
        self::assertSame('4711/' . $reference->sha256, $reference->sourceImageId);
        self::assertTrue($reference->isReResolvable());
        self::assertSame(1, $toolCall->countPicturesStillRiding([]), 'the next invocation holds no carry and still sends the picture');

        // invocation 1: the transient carry; invocation 2: only the persisted reference, resolved through the executor
        $entryWithCarry = $toolCall->toOpenAiToolResultArray(true, $toolResult->images);
        /** @var MCPToolExecutorService $mCPToolExecutorService */
        $mCPToolExecutorService = DDDService::instance()->getService(MCPToolExecutorService::class);
        $resolvedImage = $mCPToolExecutorService->resolveToolCallImage($reference, []);
        self::assertInstanceOf(ToolResultImage::class, $resolvedImage);
        $entryAfterTheBoundary = $toolCall->toOpenAiToolResultArray(true, [$resolvedImage]);

        self::assertSame(json_encode($entryWithCarry), json_encode($entryAfterTheBoundary));
        $entryWithoutTheSpool = $toolCall->toOpenAiToolResultArray(true, [], false, false, true);
        self::assertNotSame(json_encode($entryWithCarry), json_encode($entryWithoutTheSpool), 'without the spool the history changes at this result');
    }

    public function testBytesThatNoLongerMatchTheReferenceAreADroppedPicture(): void
    {
        $toolResult = static::buildToolResultWithPicture();
        $this->spoolDouble->spoolInlineToolResultImages($toolResult, static::buildConversation());
        $reference = $toolResult->images[0]->toToolCallImageReference();

        $this->spoolDouble->spooledBase64TextsBySpoolImageId[$reference->sourceImageId] = base64_encode('%!PS-Adobe-3.0 not a picture');
        self::assertNull($this->spoolDouble->resolveSpooledToolCallImage($reference));

        unset($this->spoolDouble->spooledBase64TextsBySpoolImageId[$reference->sourceImageId]);
        self::assertNull($this->spoolDouble->resolveSpooledToolCallImage($reference), 'gone bytes');
    }

    public function testAPictureStaysInlineWhenTheSpoolCannotTakeIt(): void
    {
        $this->spoolDouble->writeFails = true;
        $failedWrite = static::buildToolResultWithPicture();
        $this->spoolDouble->spoolInlineToolResultImages($failedWrite, static::buildConversation());
        self::assertSame(ToolCallImageReference::SOURCE_STORE_INLINE, $failedWrite->images[0]->sourceStore);

        $this->spoolDouble->writeFails = false;
        $this->spoolDouble->spoolEnabled = false;
        $disabled = static::buildToolResultWithPicture();
        $this->spoolDouble->spoolInlineToolResultImages($disabled, static::buildConversation());
        self::assertSame(ToolCallImageReference::SOURCE_STORE_INLINE, $disabled->images[0]->sourceStore);

        $this->spoolDouble->spoolEnabled = true;
        $withoutConversationId = static::buildToolResultWithPicture();
        $this->spoolDouble->spoolInlineToolResultImages($withoutConversationId, new AIConversation());
        self::assertSame(ToolCallImageReference::SOURCE_STORE_INLINE, $withoutConversationId->images[0]->sourceStore);

        $errorResult = static::buildToolResultWithPicture();
        $errorResult->isError = true;
        $this->spoolDouble->spoolInlineToolResultImages($errorResult, static::buildConversation());
        self::assertSame(ToolCallImageReference::SOURCE_STORE_INLINE, $errorResult->images[0]->sourceStore);
        self::assertSame([], $this->spoolDouble->spooledBase64TextsBySpoolImageId);
    }

    public function testAPictureThatNamesItsOwnStoreIsNotSpooled(): void
    {
        $toolResult = new ToolResult('Catalog sheet');
        $toolResult->images = [ToolResultImage::fromMcpImageContentPart([
            'type' => 'image', 'data' => self::PNG_1X1_BASE64, 'sourceStore' => 'catalog', 'sourceImageId' => 'sheet-7',
        ])];
        $this->spoolDouble->spoolInlineToolResultImages($toolResult, static::buildConversation());
        self::assertSame('catalog', $toolResult->images[0]->sourceStore);
        self::assertSame([], $this->spoolDouble->spooledBase64TextsBySpoolImageId);
    }

    protected static function buildToolResultWithPicture(): ToolResult
    {
        $toolResult = new ToolResult('Viewport of the start page');
        $toolResult->images = [ToolResultImage::fromMcpImageContentPart(['type' => 'image', 'data' => self::PNG_1X1_BASE64])];
        return $toolResult;
    }

    protected static function buildConversation(): AIConversation
    {
        $conversation = new AIConversation();
        $conversation->id = 4711;
        return $conversation;
    }

    protected static function buildCompletedToolCall(ToolResult $toolResult): ToolCall
    {
        $toolCall = new ToolCall();
        $toolCall->toolCallId = 'call_viewport';
        $toolCall->name = 'browserScreenshot';
        $toolCall->result = $toolResult->content;
        $toolCall->status = AIConversationMessage::STATUS_COMPLETED;
        $toolCall->applyResultImages($toolResult);
        return $toolCall;
    }
}
