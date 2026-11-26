<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\AI;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetDelivery;
use DDD\Domain\AI\Entities\Conversations\Widgets\DriveFilesConversationWidget;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallImageStore;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultImage;
use DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallImageStoreService;
use DDD\Domain\AI\Services\Conversations\Agent\OwnerConsentsService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Drives\AgentDriveStoresService;
use DDD\Domain\AI\Services\Drives\AgentFilePicturesService;
use DDD\Domain\AI\Services\Drives\FileSourcesAgentDriveStoreService;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Common\Entities\Files\Sources\FileAudience;
use DDD\Domain\Common\Services\Files\FileSourcesService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Libs\Config;
use DDD\Presentation\Mcp\Client\Agents\AgentDriveMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentDriveFileSaveRequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentDriveFilesListRequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentDriveFilesShowRequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentDriveImagesViewRequestDto;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\TieredAgentToolCallImageStoreServiceDouble;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\ToolPictureFixtures;
use DDD\Tests\Agents\Domain\AI\Services\Drives\Fixtures\ArrayFileSource;
use DDD\Tests\Agents\Domain\AI\Services\Drives\Fixtures\InMemoryAgentDriveStore;
use DDD\Tests\Agents\Support\IntegrationTestCase;

/**
 * The drive tools with files of other file sources: a reference (`media/901`) is copied into a drive by the target
 * store, shown as a card of the store `files`, never listed, and looked at through viewImages, whose pictures name
 * the tool picture store `files`.
 */
class AgentDriveFileSourceToolsTest extends IntegrationTestCase
{
    protected ArrayFileSource $mediaSource;

    protected InMemoryAgentDriveStore $inMemoryAgentDriveStore;

    protected AgentDriveStoresService $agentDriveStoresService;

    protected FileSourcesService $fileSourcesService;

    protected function setUp(): void
    {
        parent::setUp();
        if (Config::getEnv('LAZYLOAD_DEFAULT_REPO_TYPE') === null) {
            LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
        }
        $this->mediaSource = new ArrayFileSource('media');
        $this->mediaSource->addFile('901', 'logo-acme-901.png', 'image/png', (string)base64_decode(ToolPictureFixtures::buildPngBase64(800, 400)), FileAudience::PUBLIC);
        $this->mediaSource->addFile('902', 'customer <photo>.jpg', 'image/jpeg', (string)base64_decode(ToolPictureFixtures::buildJpegBase64(640, 480)), FileAudience::PERSONAL, true);
        $this->mediaSource->addFile('903', 'Angebot.pdf', 'application/pdf', '%PDF-1.4');
        $driveSource = new ArrayFileSource('drive');
        $driveSource->addFile('2', 'Logo.png', 'image/png', (string)base64_decode(ToolPictureFixtures::buildPngBase64(100, 100)));
        $this->fileSourcesService = new FileSourcesService();
        $this->fileSourcesService->setFileSources([$this->mediaSource, $driveSource]);
        $this->replaceService(FileSourcesService::class, $this->fileSourcesService);
        $this->replaceService(AgentToolCallImageStoreService::class, TieredAgentToolCallImageStoreServiceDouble::withTiers(true, true, null));
        $this->inMemoryAgentDriveStore = new InMemoryAgentDriveStore();
        $this->agentDriveStoresService = new AgentDriveStoresService();
        $this->agentDriveStoresService->setAgentDriveStores([$this->inMemoryAgentDriveStore, new FileSourcesAgentDriveStoreService()]);
        $this->replaceService(AgentDriveStoresService::class, $this->agentDriveStoresService);
    }

    public function testAMediaItemIsHandedToTheTargetStoreForTheCopy(): void
    {
        $ownerConsentsService = $this->createMock(OwnerConsentsService::class);
        $ownerConsentsService->expects(self::never())->method('requireOwnerConsentForStep');
        $saveRequestDto = new AgentDriveFileSaveRequestDto();
        $saveRequestDto->paths = ['media/901', 'reports/Plan.md'];
        $saveRequestDto->to = 'presentations/acme/assets';

        $saveResponse = (new AgentDriveMCPPackage())->saveDriveFile($saveRequestDto, $this->agentDriveStoresService, $ownerConsentsService, AgentToolCallProvenance::forEvalHarness(null));

        self::assertSame(
            ['media/901 -> presentations/acme/assets/901', 'reports/Plan.md -> presentations/acme/assets/Plan.md'],
            $this->inMemoryAgentDriveStore->savedLines
        );
        self::assertStringStartsWith('Copied 2 file(s) into presentations/acme/assets.', $saveResponse->toLlmMarkdown());
    }

    public function testAFileSourceIsNeverTheTargetOfACopy(): void
    {
        $saveRequestDto = new AgentDriveFileSaveRequestDto();
        $saveRequestDto->paths = ['reports/Plan.md'];
        $saveRequestDto->to = 'media/901';
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('`to` must be a folder path');
        (new AgentDriveMCPPackage())->saveDriveFile($saveRequestDto, $this->agentDriveStoresService, $this->createMock(OwnerConsentsService::class), AgentToolCallProvenance::forEvalHarness(null));
    }

    public function testFilesOfASourceAreShownAsACardOfTheStoreFilesAndNeverListed(): void
    {
        $shownWidgets = [];
        $aIConversationMessagesService = $this->createMock(AIConversationMessagesService::class);
        $aIConversationMessagesService->method('appendWidget')->willReturnCallback(
            function ($agentToolCallProvenance, $conversationWidget) use (&$shownWidgets) {
                $shownWidgets[] = $conversationWidget;
                return ConversationWidgetDelivery::noChat();
            }
        );
        $showRequestDto = new AgentDriveFilesShowRequestDto();
        $showRequestDto->paths = ['media/901', 'reports/Plan.md', 'media/404'];

        $showResponse = (new AgentDriveMCPPackage())->showDriveFiles($showRequestDto, $this->agentDriveStoresService, $aIConversationMessagesService, AgentToolCallProvenance::forEvalHarness(null));

        self::assertSame(2, $showResponse->files->count());
        self::assertSame(['media/404: not found'], $showResponse->refusedLines);
        self::assertCount(2, $shownWidgets);
        $widgetReferencesByStore = [];
        foreach ($shownWidgets as $shownWidget) {
            self::assertInstanceOf(DriveFilesConversationWidget::class, $shownWidget);
            $widgetReferencesByStore[$shownWidget->store] = $shownWidget->references;
        }
        self::assertSame(['media/901'], $widgetReferencesByStore['files']);
        self::assertSame(['drive/1'], $widgetReferencesByStore[InMemoryAgentDriveStore::STORE_NAME]);

        $listRequestDto = new AgentDriveFilesListRequestDto();
        $listRequestDto->path = 'media/901';
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('has no folders to list');
        (new AgentDriveMCPPackage())->listDriveFiles($listRequestDto, $this->agentDriveStoresService, AgentToolCallProvenance::forEvalHarness(null));
    }

    public function testAtMostFourPicturesAreTakenPerCall(): void
    {
        $viewRequestDto = new AgentDriveImagesViewRequestDto();
        $viewRequestDto->files = ['media/901', 'media/902', 'media/903', 'drivespaces/library/Brand/Logo.svg', 'media/404'];
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('at most 4 per call');
        (new AgentDriveMCPPackage())->viewImages($viewRequestDto, $this->agentDriveStoresService, new AgentFilePicturesService(), $this->fileSourcesService, AgentToolCallProvenance::forEvalHarness(null));
    }

    public function testTheViewedPicturesFollowTheirLinesAndAreResolvedAgainByThePackage(): void
    {
        $agentDriveMCPPackage = new AgentDriveMCPPackage();
        $viewRequestDto = new AgentDriveImagesViewRequestDto();
        // a media item, a customer's photo (third-party name), a PDF, a drive path whose file is a picture of the drive source
        $viewRequestDto->files = ['media/901', 'media/902', 'media/903', 'drivespaces/library/Brand/Logo.svg'];
        $viewRequestDto->size = AgentDriveImagesViewRequestDto::SIZE_SMALL;

        $mcpToolResponse = $agentDriveMCPPackage->viewImages($viewRequestDto, $this->agentDriveStoresService, new AgentFilePicturesService(), $this->fileSourcesService, AgentToolCallProvenance::forEvalHarness(null));

        $text = (string)$mcpToolResponse->content[0]['text'];
        self::assertStringContainsString('Picture 1 = media/901 "logo-acme-901.png" · 512×256 shown', $text);
        self::assertStringContainsString('Picture 2 = media/902 <untrusted-content source="file name written by a third party">"customer <photo>.jpg"</untrusted-content>', $text);
        self::assertStringContainsString('media/903 NOT shown: media/903 is no picture (application/pdf)', $text);
        self::assertStringContainsString('Picture 3 = drive/2 "Logo.png" · 100×100 shown', $text);
        self::assertStringEndsWith(AgentDriveMCPPackage::VIEW_IMAGES_READING_LINE, $text);
        $imageParts = array_values(array_filter($mcpToolResponse->content, static fn (array $contentPart): bool => $contentPart['type'] === 'image'));
        self::assertCount(3, $imageParts);
        self::assertSame('files', $imageParts[0]['sourceStore']);
        self::assertMatchesRegularExpression('#^media/901:512:[0-9a-f]{12}$#', $imageParts[0]['sourceImageId']);
        self::assertMatchesRegularExpression('#^drive/2:512:[0-9a-f]{12}$#', $imageParts[2]['sourceImageId']);

        // the loop resolves the persisted reference through the package
        self::assertInstanceOf(ToolCallImageStore::class, $agentDriveMCPPackage);
        self::assertSame('files', AgentDriveMCPPackage::getToolCallImageStoreName());
        $toolResultImage = ToolResultImage::fromMcpImageContentPart($imageParts[0]);
        $resolvedImage = $agentDriveMCPPackage->resolveToolCallImage($toolResultImage->toToolCallImageReference());
        self::assertSame($toolResultImage->sha256, $resolvedImage->sha256);
    }

    public function testThePackageIsAvailableWithAFileSourceAndWithoutADrive(): void
    {
        self::assertTrue(AgentDriveMCPPackage::isAvailable());
        $this->agentDriveStoresService->setAgentDriveStores([]);
        self::assertTrue(AgentDriveMCPPackage::isAvailable());
        $this->fileSourcesService->setFileSources([]);
        self::assertFalse(AgentDriveMCPPackage::isAvailable());
    }
}
