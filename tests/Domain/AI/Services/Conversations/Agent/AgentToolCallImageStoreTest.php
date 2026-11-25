<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallImageReference;
use DDD\Domain\AI\Entities\MCPPackages\ToolResult;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultImage;
use DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallImageStoreService;
use DDD\Infrastructure\Cache\Apc;
use DDD\Infrastructure\Services\IssuesLogService;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use Psr\Log\LogLevel;
use ReflectionClass;

/**
 * The three tiers of the picture store: a capture writes L1, L2 (one batch) and L3 and is durable once L3 took it; a
 * read goes L1 → one L2 batch (TTL extended) → L3 and fills the faster tiers again; a failing or missing tier is
 * reported, never thrown; no tier at all leaves the picture inline.
 */
class AgentToolCallImageStoreTest extends IntegrationTestCase
{
    protected RecordingIssuesLogServiceDouble $issuesLogServiceDouble;

    protected string $objectStoreDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->issuesLogServiceDouble = new RecordingIssuesLogServiceDouble();
        $this->replaceService(IssuesLogService::class, $this->issuesLogServiceDouble);
        $this->objectStoreDirectory = sys_get_temp_dir() . '/agent-tool-images-' . bin2hex(random_bytes(6));
        mkdir($this->objectStoreDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->objectStoreDirectory));
        parent::tearDown();
    }

    public function testACaptureWritesEveryTierAndIsDurableOnceTheObjectStoreTookIt(): void
    {
        $objectStorage = new FailingWritesFilesystemObjectStorageDouble($this->objectStoreDirectory);
        $storeService = TieredAgentToolCallImageStoreServiceDouble::withTiers(true, true, $objectStorage);
        $toolResult = ToolPictureFixtures::buildToolResultWithPicture();

        $storeService->storeInlineToolResultImages($toolResult, static::buildConversation());

        $toolResultImage = $toolResult->images[0];
        self::assertSame(ToolCallImageReference::SOURCE_STORE_SPOOL, $toolResultImage->sourceStore);
        self::assertSame('31/' . $toolResultImage->sha256, $toolResultImage->sourceImageId, 'the scope is the agent space');
        self::assertTrue($toolResultImage->isDurable);
        self::assertTrue($toolResultImage->toToolCallImageReference()->isDurable);
        $cacheKey = $storeService->buildCacheKey($toolResultImage->sha256);
        self::assertSame($toolResultImage->base64Data, $storeService->processCache->valuesByKey[$cacheKey]);
        self::assertSame($toolResultImage->base64Data, $storeService->sharedCache->valuesByKey[$cacheKey]);
        self::assertSame(1800, $storeService->sharedCache->ttlSecondsByKey[$cacheKey]);
        self::assertSame(1, $storeService->sharedCache->batchWrites);
        self::assertSame(['agent-tool-images/31/' . $toolResultImage->sha256], $objectStorage->writtenKeys);
    }

    public function testAReadGoesL1ThenOneL2BatchThenL3AndFillsTheFasterTiersAgain(): void
    {
        $objectStorage = new FailingWritesFilesystemObjectStorageDouble($this->objectStoreDirectory);
        $storeService = TieredAgentToolCallImageStoreServiceDouble::withTiers(true, true, $objectStorage);
        $firstResult = ToolPictureFixtures::buildToolResultWithPicture(ToolPictureFixtures::buildPngBase64(64, 48));
        $secondResult = ToolPictureFixtures::buildToolResultWithPicture(ToolPictureFixtures::buildPngBase64(80, 40));
        $storeService->storeInlineToolResultImages($firstResult, static::buildConversation());
        $storeService->storeInlineToolResultImages($secondResult, static::buildConversation());
        $references = [$firstResult->images[0]->toToolCallImageReference(), $secondResult->images[0]->toToolCallImageReference()];

        $fromProcessCache = $storeService->readStoredToolCallImages($references);
        self::assertSame([2, 0, 0, 0], static::countsOf($fromProcessCache));

        // a new process: L1 empty — one L2 batch, the TTL of both entries extended, L1 filled again
        $storeService->processCache->clear();
        $storeService->sharedCache->batchReads = 0;
        $fromSharedCache = $storeService->readStoredToolCallImages($references);
        self::assertSame([0, 2, 0, 0], static::countsOf($fromSharedCache));
        self::assertSame(1, $storeService->sharedCache->batchReads, 'one L2 round trip for every L1 miss');
        self::assertCount(2, $storeService->sharedCache->extendedKeys);
        self::assertCount(2, $storeService->processCache->valuesByKey);

        // after the L2 TTL: both from L3, written back to L2 and L1
        $storeService->processCache->clear();
        $storeService->sharedCache->clear();
        $fromObjectStore = $storeService->readStoredToolCallImages($references);
        self::assertSame([0, 0, 2, 0], static::countsOf($fromObjectStore));
        self::assertCount(2, $storeService->sharedCache->valuesByKey);
        self::assertCount(2, $storeService->processCache->valuesByKey);
        $image = $fromObjectStore->findImageOfSha256($references[1]->sha256);
        self::assertInstanceOf(ToolResultImage::class, $image);
        self::assertSame($secondResult->images[0]->base64Data, $image->base64Data, 'the same bytes: the tool result stays byte-identical');

        // gone everywhere
        $storeService->processCache->clear();
        $storeService->sharedCache->clear();
        exec('rm -rf ' . escapeshellarg($this->objectStoreDirectory . '/agent-tool-images'));
        self::assertSame([0, 0, 0, 2], static::countsOf($storeService->readStoredToolCallImages($references)));
    }

    public function testBytesThatNoLongerMatchTheReferenceAreAMiss(): void
    {
        $storeService = TieredAgentToolCallImageStoreServiceDouble::withTiers(false, true, null);
        $toolResult = ToolPictureFixtures::buildToolResultWithPicture();
        $storeService->storeInlineToolResultImages($toolResult, static::buildConversation());
        $reference = $toolResult->images[0]->toToolCallImageReference();

        $storeService->sharedCache->valuesByKey[$storeService->buildCacheKey($reference->sha256)] = base64_encode('%!PS-Adobe-3.0 not a picture');

        self::assertNull($storeService->readStoredToolCallImage($reference));
    }

    public function testAFailedObjectStoreWriteKeepsThePictureInTheCachesNotDurableAndIsLogged(): void
    {
        $objectStorage = new FailingWritesFilesystemObjectStorageDouble($this->objectStoreDirectory);
        $objectStorage->writesFail = true;
        $storeService = TieredAgentToolCallImageStoreServiceDouble::withTiers(true, true, $objectStorage);
        $toolResult = ToolPictureFixtures::buildToolResultWithPicture();

        $storeService->storeInlineToolResultImages($toolResult, static::buildConversation());

        self::assertSame(ToolCallImageReference::SOURCE_STORE_SPOOL, $toolResult->images[0]->sourceStore);
        self::assertFalse($toolResult->images[0]->isDurable);
        self::assertSame([LogLevel::WARNING], $this->issuesLogServiceDouble->loggedLevels);
        self::assertNotNull($storeService->readStoredToolCallImage($toolResult->images[0]->toToolCallImageReference()));
    }

    public function testWithoutAnyTierOrSwitchedOffThePictureStaysInline(): void
    {
        $withoutTiers = TieredAgentToolCallImageStoreServiceDouble::withTiers(false, false, null);
        $toolResult = ToolPictureFixtures::buildToolResultWithPicture();
        $withoutTiers->storeInlineToolResultImages($toolResult, static::buildConversation());
        self::assertSame(ToolCallImageReference::SOURCE_STORE_INLINE, $toolResult->images[0]->sourceStore);

        $switchedOff = TieredAgentToolCallImageStoreServiceDouble::withTiers(true, true, null, ['enabled' => false]);
        $switchedOffResult = ToolPictureFixtures::buildToolResultWithPicture();
        $switchedOff->storeInlineToolResultImages($switchedOffResult, static::buildConversation());
        self::assertSame(ToolCallImageReference::SOURCE_STORE_INLINE, $switchedOffResult->images[0]->sourceStore);
        self::assertSame([], $switchedOff->processCache->valuesByKey);

        $withoutConversation = ToolPictureFixtures::buildToolResultWithPicture();
        $switchedOn = TieredAgentToolCallImageStoreServiceDouble::withTiers(true, true, null);
        $switchedOn->storeInlineToolResultImages($withoutConversation, new AIConversation());
        self::assertSame(ToolCallImageReference::SOURCE_STORE_INLINE, $withoutConversation->images[0]->sourceStore);

        $errorResult = ToolPictureFixtures::buildToolResultWithPicture();
        $errorResult->isError = true;
        $switchedOn->storeInlineToolResultImages($errorResult, static::buildConversation());
        self::assertSame(ToolCallImageReference::SOURCE_STORE_INLINE, $errorResult->images[0]->sourceStore);
    }

    public function testAPictureThatNamesItsOwnStoreIsNotStored(): void
    {
        $storeService = TieredAgentToolCallImageStoreServiceDouble::withTiers(true, true, null);
        $toolResult = new ToolResult('Catalog sheet');
        $toolResult->images = [ToolResultImage::fromMcpImageContentPart([
            'type' => 'image', 'data' => ToolPictureFixtures::buildPngBase64(), 'sourceStore' => 'catalog', 'sourceImageId' => 'sheet-7',
        ])];

        $storeService->storeInlineToolResultImages($toolResult, static::buildConversation());

        self::assertSame('catalog', $toolResult->images[0]->sourceStore);
        self::assertSame([], $storeService->sharedCache->valuesByKey);
    }

    public function testAProcessLocalSharedCacheAndAMissingObjectStoreAreReportedOncePerProcess(): void
    {
        $storeService = TieredAgentToolCallImageStoreServiceDouble::withTiers(true, false, null, [
            'sharedCache' => ['enabled' => true],
            'objectStore' => ['enabled' => true],
        ]);
        $storeService->cachesByGroup[TieredAgentToolCallImageStoreServiceDouble::SHARED_CACHE_GROUP] = (new ReflectionClass(Apc::class))->newInstanceWithoutConstructor();

        for ($capture = 0; $capture < 2; $capture++) {
            $toolResult = ToolPictureFixtures::buildToolResultWithPicture();
            $storeService->storeInlineToolResultImages($toolResult, static::buildConversation());
            self::assertSame(ToolCallImageReference::SOURCE_STORE_SPOOL, $toolResult->images[0]->sourceStore, 'L1 still takes it');
        }

        self::assertSame([LogLevel::WARNING, LogLevel::WARNING], $this->issuesLogServiceDouble->loggedLevels);
        self::assertStringContainsString('local to the process', $this->issuesLogServiceDouble->loggedMessages[0]);
        self::assertStringContainsString('OBJECT_STORAGE_IMAGES_', $this->issuesLogServiceDouble->loggedMessages[1]);
    }

    public function testTheLegacySpoolKeyIsReadAndReportedOnce(): void
    {
        $storeService = TieredAgentToolCallImageStoreServiceDouble::withTiers(true, true, null);
        $storeService->configNodesByKey[AgentToolCallImageStoreService::LEGACY_CONFIG_KEY] = [
            'cacheGroup' => 'redis', 'cacheTtlSeconds' => 900, 'objectStorage' => 'legacyImages', 'objectKeyPrefix' => 'old/',
        ];

        $settings = $storeService->getSettings();
        $storeService->getSettings();

        self::assertSame('redis', $settings->sharedCacheGroup);
        self::assertSame(900, $settings->sharedCacheTtlSeconds);
        self::assertSame('legacyImages', $settings->objectStorageName);
        self::assertSame('old/', $settings->objectKeyPrefix);
        self::assertSame([LogLevel::WARNING], $this->issuesLogServiceDouble->loggedLevels);
    }

    public function testAFailingSharedCacheNeverFailsTheCall(): void
    {
        $storeService = TieredAgentToolCallImageStoreServiceDouble::withTiers(true, true, null);
        $storeService->sharedCache->failsEveryCall = true;
        $toolResult = ToolPictureFixtures::buildToolResultWithPicture();

        $storeService->storeInlineToolResultImages($toolResult, static::buildConversation());
        $storeService->processCache->clear();

        self::assertNull($storeService->readStoredToolCallImage($toolResult->images[0]->toToolCallImageReference()));
    }

    /** @return int[] L1, L2, L3 hits and misses */
    protected static function countsOf(\DDD\Domain\AI\Entities\Conversations\Agent\Images\AgentToolCallImageReadResult $readResult): array
    {
        return [$readResult->processCacheHits, $readResult->sharedCacheHits, $readResult->objectStoreHits, $readResult->misses];
    }

    public function testTheObjectStoreHoldsTheRawPictureAndStillReadsABase64TextOfAnEarlierVersion(): void
    {
        $objectStorage = new FailingWritesFilesystemObjectStorageDouble($this->objectStoreDirectory);
        $storeService = TieredAgentToolCallImageStoreServiceDouble::withTiers(false, false, $objectStorage);
        $toolResult = ToolPictureFixtures::buildToolResultWithPicture();
        $storeService->storeInlineToolResultImages($toolResult, static::buildConversation());
        $toolResultImage = $toolResult->images[0];
        $objectKey = 'agent-tool-images/' . $toolResultImage->sourceImageId;
        self::assertSame(base64_decode($toolResultImage->base64Data, true), $objectStorage->getObjectContent($objectKey), 'raw bytes, no base64 text');
        $reference = $toolResultImage->toToolCallImageReference();
        self::assertSame($toolResultImage->base64Data, $storeService->readStoredToolCallImage($reference)?->base64Data);

        // an object written before 0.5.0: the base64 text as text/plain
        $objectStorage->putObject($objectKey, $toolResultImage->base64Data, 'text/plain');
        self::assertSame($toolResultImage->base64Data, $storeService->readStoredToolCallImage($reference)?->base64Data);
    }

    public function testErasingTheReferencesOfAPurgedConversationDeletesTheObjectAndTheCacheEntries(): void
    {
        $objectStorage = new FailingWritesFilesystemObjectStorageDouble($this->objectStoreDirectory);
        $storeService = TieredAgentToolCallImageStoreServiceDouble::withTiers(true, true, $objectStorage);
        $toolResult = ToolPictureFixtures::buildToolResultWithPicture();
        $storeService->storeInlineToolResultImages($toolResult, static::buildConversation());
        $reference = $toolResult->images[0]->toToolCallImageReference();
        $inlineReference = new ToolCallImageReference();

        self::assertSame(1, $storeService->eraseStoredPicturesOfReferences([$reference, $inlineReference]));

        self::assertFalse($objectStorage->objectExists('agent-tool-images/' . $reference->sourceImageId));
        self::assertSame([], $storeService->processCache->valuesByKey);
        self::assertSame([], $storeService->sharedCache->valuesByKey);
        self::assertNull($storeService->readStoredToolCallImage($reference));
        self::assertSame(0, $storeService->eraseStoredPicturesOfReferences([$inlineReference]), 'an inline picture has nothing stored');
    }

    protected static function buildConversation(): AIConversation
    {
        $conversation = new AIConversation();
        $conversation->id = 4711;
        $conversation->agentSpaceId = 31;
        return $conversation;
    }
}
