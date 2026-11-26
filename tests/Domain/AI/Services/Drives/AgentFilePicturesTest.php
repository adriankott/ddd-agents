<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Drives;

use DDD\Domain\AI\Entities\MCPPackages\ToolCallImageReference;
use DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallImageStoreService;
use DDD\Domain\AI\Services\Drives\AgentFilePicturesService;
use DDD\Domain\Common\Entities\Files\Sources\FileAudience;
use DDD\Domain\Common\Entities\Files\Sources\FileFlowDecision;
use DDD\Domain\Common\Entities\Files\Sources\FileReference;
use DDD\Domain\Common\Services\Files\FileFlowPolicyService;
use DDD\Domain\Common\Services\Files\FileSourcesService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\FailingWritesFilesystemObjectStorageDouble;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\TieredAgentToolCallImageStoreServiceDouble;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\ToolPictureFixtures;
use DDD\Tests\Agents\Domain\AI\Services\Drives\Fixtures\ArrayFileSource;
use DDD\Tests\Agents\Domain\AI\Services\Drives\Fixtures\ScriptedFileFlowPolicyService;
use DDD\Tests\Agents\Support\IntegrationTestCase;

/**
 * The tool picture store `files`: a picture of any file source is rendered once, kept in the tiers by its bytes and
 * resolved again from its reference; rights and the flow rule are checked on every resolve, a person's own file never
 * reaches the object store, and a file whose bytes changed is no longer the picture that was shown.
 */
class AgentFilePicturesTest extends IntegrationTestCase
{
    protected ArrayFileSource $mediaSource;

    protected ScriptedFileFlowPolicyService $flowPolicy;

    protected TieredAgentToolCallImageStoreServiceDouble $pictureStore;

    protected FailingWritesFilesystemObjectStorageDouble $objectStorage;

    protected AgentFilePicturesService $agentFilePicturesService;

    protected string $objectStoreDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mediaSource = new ArrayFileSource('media');
        $this->mediaSource->addFile('901', 'logo-acme-901.png', 'image/png', (string)base64_decode(ToolPictureFixtures::buildPngBase64(800, 400)), FileAudience::PUBLIC);
        $this->mediaSource->addFile('902', 'route-problem-902.jpg', 'image/jpeg', (string)base64_decode(ToolPictureFixtures::buildJpegBase64(640, 480)), FileAudience::PERSONAL, true);
        $this->mediaSource->addFile('903', 'Angebot.pdf', 'application/pdf', '%PDF-1.4');
        $fileSourcesService = new FileSourcesService();
        $fileSourcesService->setFileSources([$this->mediaSource]);
        $this->replaceService(FileSourcesService::class, $fileSourcesService);
        $this->flowPolicy = new ScriptedFileFlowPolicyService();
        $this->replaceService(FileFlowPolicyService::class, $this->flowPolicy);
        $this->objectStoreDirectory = sys_get_temp_dir() . '/agent-file-pictures-' . bin2hex(random_bytes(6));
        mkdir($this->objectStoreDirectory, 0777, true);
        $this->objectStorage = new FailingWritesFilesystemObjectStorageDouble($this->objectStoreDirectory);
        $this->pictureStore = TieredAgentToolCallImageStoreServiceDouble::withTiers(true, true, $this->objectStorage);
        $this->replaceService(AgentToolCallImageStoreService::class, $this->pictureStore);
        $this->agentFilePicturesService = new AgentFilePicturesService();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->objectStoreDirectory));
        parent::tearDown();
    }

    public function testAPictureIsRenderedScaledAndKeptInEveryTier(): void
    {
        $toolResultImage = $this->agentFilePicturesService->renderPictureForModel(new FileReference('media', '901'), 512);

        self::assertSame('files', $toolResultImage->sourceStore);
        self::assertMatchesRegularExpression('#^media/901:512:[0-9a-f]{12}$#', $toolResultImage->sourceImageId);
        self::assertSame([512, 256], [$toolResultImage->width, $toolResultImage->height]);
        self::assertTrue($toolResultImage->isDurable);
        $cacheKey = $this->pictureStore->buildCacheKey($toolResultImage->sha256);
        self::assertSame($toolResultImage->base64Data, $this->pictureStore->processCache->valuesByKey[$cacheKey]);
        self::assertSame($toolResultImage->base64Data, $this->pictureStore->sharedCache->valuesByKey[$cacheKey]);
        self::assertSame(['agent-tool-images/sources/files/' . $toolResultImage->sha256], $this->objectStorage->writtenKeys);
    }

    public function testALaterModelCallReadsTheSameBytesFromTheTiersWithoutTheSource(): void
    {
        $toolResultImage = $this->agentFilePicturesService->renderPictureForModel(new FileReference('media', '901'), 512);
        $toolCallImageReference = $toolResultImage->toToolCallImageReference();
        $readCountAfterRender = $this->mediaSource->readCount;

        $fromProcessCache = $this->agentFilePicturesService->resolvePicture($toolCallImageReference);
        self::assertSame($toolResultImage->sha256, $fromProcessCache->sha256);
        self::assertSame('files', $fromProcessCache->sourceStore);
        self::assertSame($toolResultImage->sourceImageId, $fromProcessCache->sourceImageId);

        // another worker: only the shared cache, then only the object store, then nothing but the source
        $this->pictureStore->processCache->valuesByKey = [];
        self::assertSame($toolResultImage->sha256, $this->agentFilePicturesService->resolvePicture($toolCallImageReference)->sha256);
        $this->pictureStore->processCache->valuesByKey = [];
        $this->pictureStore->sharedCache->valuesByKey = [];
        self::assertSame($toolResultImage->sha256, $this->agentFilePicturesService->resolvePicture($toolCallImageReference)->sha256);
        self::assertSame($readCountAfterRender, $this->mediaSource->readCount, 'the tiers answered, the source was not read');
        self::assertNotEmpty($this->pictureStore->sharedCache->valuesByKey, 'the object store hit fills the caches again');

        $this->pictureStore->processCache->valuesByKey = [];
        $this->pictureStore->sharedCache->valuesByKey = [];
        exec('rm -rf ' . escapeshellarg($this->objectStoreDirectory . '/agent-tool-images'));
        $renderedAgain = $this->agentFilePicturesService->resolvePicture($toolCallImageReference);
        self::assertSame($toolResultImage->sha256, $renderedAgain->sha256, 'the same source gives the same picture');
        self::assertSame($readCountAfterRender + 1, $this->mediaSource->readCount);
    }

    public function testRightsAndTheFlowRuleAreCheckedBeforeTheTiersAnswer(): void
    {
        $toolResultImage = $this->agentFilePicturesService->renderPictureForModel(new FileReference('media', '901'), 512);
        $toolCallImageReference = $toolResultImage->toToolCallImageReference();

        // the flow rule no longer lets the file go to the model: the cached bytes stay unread
        $this->flowPolicy->fileFlowDecision = FileFlowDecision::refuse('media/901 must not be shown.');
        self::assertNull($this->agentFilePicturesService->resolvePicture($toolCallImageReference));
        $this->flowPolicy->fileFlowDecision = null;
        self::assertNotNull($this->agentFilePicturesService->resolvePicture($toolCallImageReference));

        // the caller may no longer read the file (or it is gone)
        unset($this->mediaSource->fileFactsById['901']);
        self::assertNull($this->agentFilePicturesService->resolvePicture($toolCallImageReference));
    }

    public function testAPersonsOwnPictureNeverReachesTheObjectStore(): void
    {
        $toolResultImage = $this->agentFilePicturesService->renderPictureForModel(new FileReference('media', '902'), 1024);
        self::assertFalse($toolResultImage->isDurable);
        self::assertSame([], $this->objectStorage->writtenKeys);
        self::assertArrayHasKey($this->pictureStore->buildCacheKey($toolResultImage->sha256), $this->pictureStore->sharedCache->valuesByKey);

        // even a reference that claims durability is not read from the object store
        $toolCallImageReference = $toolResultImage->toToolCallImageReference();
        $toolCallImageReference->isDurable = true;
        $this->objectStorage->putObject('agent-tool-images/sources/files/' . $toolResultImage->sha256, (string)base64_decode($toolResultImage->base64Data), $toolResultImage->mimeType);
        $this->pictureStore->processCache->valuesByKey = [];
        $this->pictureStore->sharedCache->valuesByKey = [];
        $readCountBefore = $this->mediaSource->readCount;
        self::assertNotNull($this->agentFilePicturesService->resolvePicture($toolCallImageReference));
        self::assertSame($readCountBefore + 1, $this->mediaSource->readCount, 'rendered from the source, not read from the object store');
    }

    public function testAFileWhoseBytesChangedIsNoLongerThePictureThatWasShown(): void
    {
        $toolResultImage = $this->agentFilePicturesService->renderPictureForModel(new FileReference('media', '901'), 512);
        $toolCallImageReference = $toolResultImage->toToolCallImageReference();
        $this->pictureStore->processCache->valuesByKey = [];
        $this->pictureStore->sharedCache->valuesByKey = [];
        exec('rm -rf ' . escapeshellarg($this->objectStoreDirectory . '/agent-tool-images'));
        $this->mediaSource->contentById['901'] = (string)base64_decode(ToolPictureFixtures::buildPngBase64(300, 300));
        self::assertNull($this->agentFilePicturesService->resolvePicture($toolCallImageReference));

        // a source that knows the sha256 of its bytes answers without a read
        $this->mediaSource->fileFactsById['901']->sha256 = hash('sha256', $this->mediaSource->contentById['901']);
        $readCountBefore = $this->mediaSource->readCount;
        self::assertNull($this->agentFilePicturesService->resolvePicture($toolCallImageReference));
        self::assertSame($readCountBefore, $this->mediaSource->readCount);
    }

    public function testWhatIsNoPictureOrNoReferenceOfTheStoreIsRefused(): void
    {
        try {
            $this->agentFilePicturesService->renderPictureForModel(new FileReference('media', '903'), 512);
            self::fail('a PDF was shown as a picture');
        } catch (BadRequestException $badRequestException) {
            self::assertStringContainsString('media/903 is no picture (application/pdf)', $badRequestException->getMessage());
        }
        try {
            $this->agentFilePicturesService->renderPictureForModel(new FileReference('media', '404'), 512);
            self::fail('a missing file was shown');
        } catch (NotFoundException) {
        }
        $this->flowPolicy->fileFlowDecision = FileFlowDecision::refuse('not for the model');
        try {
            $this->agentFilePicturesService->renderPictureForModel(new FileReference('media', '901'), 512);
            self::fail('the flow rule was not asked');
        } catch (ForbiddenException) {
        }
        $this->flowPolicy->fileFlowDecision = null;
        foreach ([['spool', 'media/901:512'], ['files', 'media/901'], ['files', '../etc/passwd:512'], ['files', 'unknown/1:512'], ['files', 'media/901:0']] as [$sourceStore, $sourceImageId]) {
            $toolCallImageReference = new ToolCallImageReference();
            $toolCallImageReference->sourceStore = $sourceStore;
            $toolCallImageReference->sourceImageId = $sourceImageId;
            $toolCallImageReference->sha256 = str_repeat('a', 64);
            self::assertNull($this->agentFilePicturesService->resolvePicture($toolCallImageReference), $sourceStore . ' ' . $sourceImageId);
        }
    }
}
