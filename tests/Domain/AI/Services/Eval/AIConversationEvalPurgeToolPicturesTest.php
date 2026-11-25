<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\MCPPackages\ToolCallImageReference;
use DDD\Domain\AI\Services\Eval\AIConversationEvalPurgeService;
use PHPUnit\Framework\TestCase;

/** The purge erases the stored pictures of the tool calls it deletes: only references that name the picture store. */
class AIConversationEvalPurgeToolPicturesTest extends TestCase
{
    public function testOnlyReferencesToThePictureStoreAreCollected(): void
    {
        $toolCallJson = (string)json_encode([
            'name' => 'viewPage',
            'imageReferences' => [
                ['sourceStore' => ToolCallImageReference::SOURCE_STORE_SPOOL, 'sourceImageId' => '31/abc', 'sha256' => 'abc'],
                ['sourceStore' => ToolCallImageReference::SOURCE_STORE_INLINE, 'sourceImageId' => '', 'sha256' => 'def'],
                ['sourceStore' => 'drive', 'sourceImageId' => 'file-1', 'sha256' => 'ghi'],
                'not a reference',
            ],
        ]);

        $toolCallImageReferences = AIConversationEvalPurgeService::extractStoredToolCallImageReferences($toolCallJson);

        self::assertCount(1, $toolCallImageReferences);
        self::assertSame('31/abc', $toolCallImageReferences[0]->sourceImageId);
        self::assertSame('abc', $toolCallImageReferences[0]->sha256);
        self::assertSame([], AIConversationEvalPurgeService::extractStoredToolCallImageReferences('{"name":"x"}'));
        self::assertSame([], AIConversationEvalPurgeService::extractStoredToolCallImageReferences(null));
        self::assertSame([], AIConversationEvalPurgeService::extractStoredToolCallImageReferences('no json'));
    }
}
