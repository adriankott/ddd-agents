<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\AI;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Drives\AgentDriveMount;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackageDocumentation;
use DDD\Domain\AI\Services\Conversations\Agent\OwnerConsentsService;
use DDD\Domain\AI\Services\Drives\AgentDriveStoresService;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Libs\Config;
use DDD\Presentation\Mcp\Client\Agents\AgentDriveMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentDriveFileSaveRequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentDriveFilesShowRequestDto;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetDelivery;
use DDD\Domain\AI\Entities\Conversations\Widgets\DriveFilesConversationWidget;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentDriveFilesListRequestDto;
use DDD\Tests\Agents\Domain\AI\Services\Drives\Fixtures\InMemoryAgentDriveStore;
use DDD\Tests\Agents\Support\UnitTestCase;

/** The drive tools: schema, listing without download, the mounts, and the write modes of saveDriveFile */
class AgentDriveMCPPackageTest extends UnitTestCase
{
    protected InMemoryAgentDriveStore $inMemoryAgentDriveStore;

    protected AgentDriveStoresService $agentDriveStoresService;

    protected function setUp(): void
    {
        parent::setUp();
        if (Config::getEnv('LAZYLOAD_DEFAULT_REPO_TYPE') === null) {
            LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
        }
        $this->inMemoryAgentDriveStore = new InMemoryAgentDriveStore();
        $this->agentDriveStoresService = new AgentDriveStoresService();
        $this->agentDriveStoresService->setAgentDriveStores([$this->inMemoryAgentDriveStore]);
    }

    public function testTheSchemaBuildsAndExposesTheFiveTools(): void
    {
        $packageDocumentation = MCPPackageDocumentation::createFromClassName(AgentDriveMCPPackage::class);
        $toolNames = [];
        foreach ($packageDocumentation->functions as $toolDocumentation) {
            $toolNames[] = $toolDocumentation->name;
        }
        sort($toolNames);
        self::assertSame(['listDriveFiles', 'readDriveDocument', 'saveDriveFile', 'showDriveFiles', 'viewDriveDocumentPages'], $toolNames);
        self::assertSame('agent.drive', $packageDocumentation->name);
        self::assertStringNotContainsString('array<', (string)json_encode($packageDocumentation->toObject(forPersistence: false)));
    }

    public function testAFolderAndTheMountsAreListedWithoutContent(): void
    {
        $agentDriveMCPPackage = new AgentDriveMCPPackage();
        $listRequestDto = new AgentDriveFilesListRequestDto();
        $listRequestDto->path = 'drivespaces/library/Brand';

        $listing = $agentDriveMCPPackage->listDriveFiles($listRequestDto, $this->agentDriveStoresService, AgentToolCallProvenance::forEvalHarness(null));

        self::assertStringContainsString('drivespaces/library/Brand/Logo.svg · 2 KB · v1 · drive/2', $listing->toLlmMarkdown());

        $listRequestDto->path = 'drivespaces';
        $mountsListing = $agentDriveMCPPackage->listDriveFiles($listRequestDto, $this->agentDriveStoresService, AgentToolCallProvenance::forEvalHarness(null));
        self::assertStringContainsString('mount drivespaces/library · internal · read-only', $mountsListing->toLlmMarkdown());
    }

    public function testAFolderIsShownAsACardRow(): void
    {
        $shownWidgets = [];
        $aIConversationMessagesService = $this->createMock(AIConversationMessagesService::class);
        $aIConversationMessagesService->method('appendWidget')->willReturnCallback(
            function ($agentToolCallProvenance, $conversationWidget) use (&$shownWidgets) {
                $shownWidgets[] = $conversationWidget;
                return ConversationWidgetDelivery::noChat();
            }
        );
        $this->inMemoryAgentDriveStore->referencesByPath['drivespaces/library/Brand'] = 'drive/3';
        $this->inMemoryAgentDriveStore->folderPaths = ['drivespaces/library/Brand'];
        $showRequestDto = new AgentDriveFilesShowRequestDto();
        $showRequestDto->paths = ['drivespaces/library/Brand'];

        $showResponse = (new AgentDriveMCPPackage())->showDriveFiles($showRequestDto, $this->agentDriveStoresService, $aIConversationMessagesService, AgentToolCallProvenance::forEvalHarness(null));

        self::assertSame(1, $showResponse->files->count());
        self::assertTrue($showResponse->files->first()->isFolder);
        self::assertCount(1, $shownWidgets);
        self::assertInstanceOf(DriveFilesConversationWidget::class, $shownWidgets[0]);
        self::assertCount(1, $shownWidgets[0]->references);
    }

    public function testAReadOnlyMountRefusesTheCopy(): void
    {
        $agentDriveMCPPackage = new AgentDriveMCPPackage();
        $saveRequestDto = new AgentDriveFileSaveRequestDto();
        $saveRequestDto->paths = ['reports/Plan.md'];
        $saveRequestDto->to = 'drivespaces/library/Brand';

        $this->expectException(ForbiddenException::class);
        $agentDriveMCPPackage->saveDriveFile($saveRequestDto, $this->agentDriveStoresService, $this->createMock(OwnerConsentsService::class), AgentToolCallProvenance::forEvalHarness(null));
    }

    public function testAnInternalReadWriteMountCopiesWithoutConsent(): void
    {
        $this->inMemoryAgentDriveStore->libraryMode = AgentDriveMount::MODE_READ_WRITE;
        $ownerConsentsService = $this->createMock(OwnerConsentsService::class);
        $ownerConsentsService->expects(self::never())->method('requireOwnerConsentForStep');
        $saveRequestDto = new AgentDriveFileSaveRequestDto();
        $saveRequestDto->paths = ['reports/Plan.md'];
        $saveRequestDto->to = 'drivespaces/library/Plans';

        $saveResponse = (new AgentDriveMCPPackage())->saveDriveFile($saveRequestDto, $this->agentDriveStoresService, $ownerConsentsService, AgentToolCallProvenance::forEvalHarness(null));

        self::assertSame(['reports/Plan.md -> drivespaces/library/Plans/Plan.md'], $this->inMemoryAgentDriveStore->savedLines);
        self::assertStringStartsWith('Copied 1 file(s) into drivespaces/library/Plans.', $saveResponse->toLlmMarkdown());
    }

    public function testAnOutwardVisibleMountAsksForConsentFirst(): void
    {
        $this->inMemoryAgentDriveStore->libraryMode = AgentDriveMount::MODE_READ_WRITE;
        $this->inMemoryAgentDriveStore->libraryIsOutwardVisible = true;
        $ownerConsentsService = $this->createMock(OwnerConsentsService::class);
        $ownerConsentsService->expects(self::once())->method('requireOwnerConsentForStep')
            ->willThrowException(new ForbiddenException('not confirmed'));
        $saveRequestDto = new AgentDriveFileSaveRequestDto();
        $saveRequestDto->paths = ['reports/Plan.md'];
        $saveRequestDto->to = 'drivespaces/library/Plans';

        try {
            (new AgentDriveMCPPackage())->saveDriveFile($saveRequestDto, $this->agentDriveStoresService, $ownerConsentsService, AgentToolCallProvenance::forEvalHarness(null));
            self::fail('the copy must wait for the consent');
        } catch (ForbiddenException) {
        }
        self::assertSame([], $this->inMemoryAgentDriveStore->savedLines);
    }
}
