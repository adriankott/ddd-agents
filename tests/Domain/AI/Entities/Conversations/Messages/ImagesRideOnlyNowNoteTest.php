<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultImage;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A result whose pictures ride on this call tells the model to write down now what it saw: the pictures leave the
 * wire after a later step, and a model that kept its verdict only in its reasoning would look at them again. Once they
 * leave, the dropped marker replaces the note.
 */
class ImagesRideOnlyNowNoteTest extends UnitTestCase
{
    protected const string PNG_1X1_BASE64 =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function testAResultWithPicturesOnTheWireCarriesTheNote(): void
    {
        $toolCall = static::buildToolCall();
        $toolResultImage = ToolResultImage::fromMcpImageContentPart(['type' => 'image', 'data' => self::PNG_1X1_BASE64]);

        $toolResultArray = $toolCall->toOpenAiToolResultArray(true, [$toolResultImage]);

        self::assertIsArray($toolResultArray['content']);
        self::assertStringContainsString(ToolCall::buildImagesRideOnlyNowNote(1), $toolResultArray['content'][0]['text']);
        self::assertStringContainsString('write down in your visible text', ToolCall::buildImagesRideOnlyNowNote(2));
    }

    public function testADroppedResultCarriesTheDroppedMarkerInstead(): void
    {
        $toolCall = static::buildToolCall();

        $toolResultArray = $toolCall->toOpenAiToolResultArray(true, [], false, false, true);

        self::assertIsString($toolResultArray['content']);
        self::assertStringContainsString('no longer on the wire', $toolResultArray['content']);
        self::assertStringNotContainsString('attached only while', $toolResultArray['content']);
    }

    protected static function buildToolCall(): ToolCall
    {
        $toolCall = new ToolCall();
        $toolCall->toolCallId = 'call_slides';
        $toolCall->name = 'previewSlides';
        $toolCall->result = 'Slide 3 preview';
        return $toolCall;
    }
}
