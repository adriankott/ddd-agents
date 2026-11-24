<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\AI\Entities\Conversations\Agent\Images\AgentToolCallImageReadResult;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\PromptCacheDiffReport;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The picture figures of a call's snapshot (pictures sent, picture-store hits per tier, read time) and the report's
 * wire build time p50/p95 with and without pictures.
 */
class PromptCacheDiffReportPictureFiguresTest extends UnitTestCase
{
    public function testASnapshotRecordsThePicturesAndTheStoreReadAndATextCallStaysSmall(): void
    {
        $agentToolCallImageReadResult = new AgentToolCallImageReadResult();
        $agentToolCallImageReadResult->processCacheHits = 1;
        $agentToolCallImageReadResult->sharedCacheHits = 2;
        $agentToolCallImageReadResult->objectStoreHits = 3;
        $agentToolCallImageReadResult->misses = 1;
        $agentToolCallImageReadResult->readMilliseconds = 12.6;
        $requestSnapshot = new RequestSnapshot();

        $requestSnapshot->recordToolCallImages(6, $agentToolCallImageReadResult);

        self::assertSame([6, 1, 2, 3, 1, 13], [
            $requestSnapshot->toolResultImagesOnWire, $requestSnapshot->toolCallImageProcessCacheHits,
            $requestSnapshot->toolCallImageSharedCacheHits, $requestSnapshot->toolCallImageObjectStoreHits,
            $requestSnapshot->toolCallImageMisses, $requestSnapshot->toolCallImageReadMs,
        ]);

        $textCallSnapshot = new RequestSnapshot();
        $textCallSnapshot->recordToolCallImages(0, new AgentToolCallImageReadResult());
        self::assertNull($textCallSnapshot->toolResultImagesOnWire);
        self::assertNull($textCallSnapshot->toolCallImageReadMs);
    }

    public function testTheReportGivesTheWireBuildPercentilesWithAndWithoutPicturesAndTheStoreHits(): void
    {
        $messages = new AIConversationMessages();
        foreach ([[3, 0], [5, 0], [4, 0], [40, 2], [9, 1], [11, 1]] as $messageIndex => [$wireBuildMs, $picturesOnWire]) {
            $requestSnapshot = new RequestSnapshot();
            $requestSnapshot->loopStepOverheadMs = ['wire' => $wireBuildMs];
            $readResult = new AgentToolCallImageReadResult();
            $readResult->processCacheHits = $picturesOnWire;
            $requestSnapshot->recordToolCallImages($picturesOnWire, $readResult);
            $message = new AIConversationMessage();
            $message->indexInConversation = $messageIndex;
            $message->requestSnapshot = $requestSnapshot;
            $messages->add($message);
        }

        $promptCacheDiffReport = PromptCacheDiffReport::fromMessages($messages);

        self::assertSame([11, 40], [$promptCacheDiffReport->wireBuildMsP50WithImages, $promptCacheDiffReport->wireBuildMsP95WithImages]);
        self::assertSame([4, 5], [$promptCacheDiffReport->wireBuildMsP50WithoutImages, $promptCacheDiffReport->wireBuildMsP95WithoutImages]);
        self::assertSame(4, $promptCacheDiffReport->toolCallImageProcessCacheHits);
        self::assertNull(PromptCacheDiffReport::percentileOf([], 50));
    }
}
