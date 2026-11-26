<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Drives;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Drives\AgentDriveMount;
use DDD\Domain\AI\Entities\Drives\AgentDrivePath;
use DDD\Domain\AI\Services\Drives\AgentDriveStoresService;
use DDD\Domain\AI\Services\Drives\FileSourcesAgentDriveStoreService;
use DDD\Domain\Common\Entities\Files\Sources\FileFlowDecision;
use DDD\Domain\Common\Services\Files\FileFlowPolicyService;
use DDD\Domain\Common\Services\Files\FileSourcesService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Tests\Agents\Domain\AI\Services\Drives\Fixtures\ArrayFileSource;
use DDD\Tests\Agents\Domain\AI\Services\Drives\Fixtures\InMemoryAgentDriveStore;
use DDD\Tests\Agents\Domain\AI\Services\Drives\Fixtures\ScriptedFileFlowPolicyService;
use DDD\Tests\Agents\Support\IntegrationTestCase;

/**
 * A file of another file source is named by its reference `<source>/<id>` in every file argument: the registry
 * resolves it onto the read-only mount of the adapter store `files`, which answers facts and bytes through the
 * application's file sources.
 */
class FileSourceReferencesTest extends IntegrationTestCase
{
    protected ArrayFileSource $mediaSource;

    protected ScriptedFileFlowPolicyService $flowPolicy;

    protected AgentDriveStoresService $agentDriveStoresService;

    protected FileSourcesAgentDriveStoreService $fileSourcesStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mediaSource = new ArrayFileSource('media');
        $this->mediaSource->addFile('901', 'logo-acme-901.png', 'image/png', 'PNG');
        $this->mediaSource->addFile('902', 'Angebot <b>.pdf', 'application/pdf', '%PDF-1.4', isContentUntrusted: true);
        $driveSource = new ArrayFileSource('drive');
        $fileSourcesService = new FileSourcesService();
        $fileSourcesService->setFileSources([$this->mediaSource, $driveSource]);
        $this->replaceService(FileSourcesService::class, $fileSourcesService);
        $this->flowPolicy = new ScriptedFileFlowPolicyService();
        $this->replaceService(FileFlowPolicyService::class, $this->flowPolicy);
        $this->fileSourcesStore = new FileSourcesAgentDriveStoreService();
        $this->replaceService(FileSourcesAgentDriveStoreService::class, $this->fileSourcesStore);
        $this->agentDriveStoresService = new AgentDriveStoresService();
        $this->agentDriveStoresService->setAgentDriveStores([new InMemoryAgentDriveStore(), $this->fileSourcesStore]);
    }

    protected function resolve(string $argument): AgentDrivePath
    {
        return $this->agentDriveStoresService->resolveDrivePath(
            $this->agentDriveStoresService->findMountsOfCall(AgentToolCallProvenance::forEvalHarness(null)),
            $argument
        );
    }

    public function testAReferenceOfAFileSourceLiesOnTheReadOnlyMountOfTheAdapter(): void
    {
        $agentDrivePath = $this->resolve(' media/901 ');
        self::assertSame('media/901', $agentDrivePath->reference);
        self::assertSame('media/901', $agentDrivePath->getWorkspacePath());
        self::assertSame(FileSourcesAgentDriveStoreService::STORE_NAME, $agentDrivePath->agentDriveMount->storeName);
        self::assertSame(AgentDriveMount::MODE_READ_ONLY, $agentDrivePath->agentDriveMount->getEffectiveWriteMode());
        self::assertFalse($agentDrivePath->agentDriveMount->isOwnDrive);
        self::assertSame($this->fileSourcesStore, $this->agentDriveStoresService->requireDriveStoreOfPath($agentDrivePath));
    }

    public function testOnlyTheExactFormIsAReferenceAndTheDriveKeepsItsOwn(): void
    {
        // a path of the own drive that happens to start with a source's name
        foreach (['/workspace/media/901', './media/901', 'media/logo.png', 'media/901/thumb', 'media'] as $pathArgument) {
            $agentDrivePath = $this->resolve($pathArgument);
            self::assertNull($agentDrivePath->reference, $pathArgument);
            self::assertSame(InMemoryAgentDriveStore::STORE_NAME, $agentDrivePath->agentDriveMount->storeName, $pathArgument);
        }
        // a name that is no registered source is a folder of the own drive
        self::assertNull($this->resolve('ticket/7')->reference);
        // drive/<id> stays a reference of the own drive's store, although a file source of that name is registered
        $driveReferencePath = $this->resolve('drive/1');
        self::assertSame('drive/1', $driveReferencePath->reference);
        self::assertSame(InMemoryAgentDriveStore::STORE_NAME, $driveReferencePath->agentDriveMount->storeName);
        self::assertNull($this->agentDriveStoresService->findFileSourceReference('drive/1'));
    }

    public function testTheAdapterMountsNothingAndIsNoDriveOfItsOwn(): void
    {
        $onlyTheAdapter = new AgentDriveStoresService();
        $onlyTheAdapter->setAgentDriveStores([$this->fileSourcesStore]);
        self::assertFalse($onlyTheAdapter->hasDriveStores());
        self::assertTrue($onlyTheAdapter->hasFileSources());
        self::assertSame(0, $onlyTheAdapter->findMountsOfCall(AgentToolCallProvenance::forEvalHarness(null))->count());
        // a reference resolves without any mount
        self::assertSame('media/901', $onlyTheAdapter->resolveDrivePath($onlyTheAdapter->findMountsOfCall(AgentToolCallProvenance::forEvalHarness(null)), 'media/901')->reference);
        self::assertTrue($this->agentDriveStoresService->hasDriveStores());
    }

    public function testFactsAndCardsComeThroughTheFileSources(): void
    {
        $call = AgentToolCallProvenance::forEvalHarness(null);
        $agentDriveFiles = $this->fileSourcesStore->findDriveFiles($call, [$this->resolve('media/901'), $this->resolve('media/902'), $this->resolve('media/404')]);
        self::assertSame(2, $agentDriveFiles->count());
        $logoFile = $agentDriveFiles->first();
        self::assertSame('files', $logoFile->storeName);
        self::assertSame('media/901', $logoFile->reference);
        self::assertSame('media/901', $logoFile->path);
        self::assertSame('logo-acme-901.png', $logoFile->name);
        self::assertSame('/admin/media/901', $logoFile->openLink);
        self::assertNull($logoFile->state);
        self::assertStringContainsString('media/901 · 3 B · media/901', $logoFile->buildLlmLine());
        $cards = $this->agentDriveStoresService->findFileCards('files', ['media/902', 'media/404', 'assets/logo.png', 'unknown/1'], null);
        self::assertSame(1, $cards->count());
        self::assertSame(FileSourcesAgentDriveStoreService::STATE_THIRD_PARTY, $cards->first()->state);
    }

    public function testASourceHasNoFoldersAndTakesNoCopies(): void
    {
        $call = AgentToolCallProvenance::forEvalHarness(null);
        try {
            $this->fileSourcesStore->listDriveFiles($call, $this->resolve('media/901'), null, 100);
            self::fail('a file source was listed');
        } catch (BadRequestException $badRequestException) {
            self::assertStringContainsString('the source "media", which has no folders', $badRequestException->getMessage());
            self::assertStringContainsString('the tool of the skill that covers it', $badRequestException->getMessage());
        }
        $this->expectException(ForbiddenException::class);
        $this->fileSourcesStore->saveDriveFiles($call, [$this->resolve('reports/Plan.md')], $this->resolve('media/901')->agentDriveMount, '');
    }

    public function testADocumentIsReadThroughTheSourceAndTheFlowRuleIsAsked(): void
    {
        $call = AgentToolCallProvenance::forEvalHarness(null);
        $agentDriveFileContent = $this->fileSourcesStore->readDriveFileContent($call, $this->resolve('media/902'), 1000);
        self::assertSame('%PDF-1.4', $agentDriveFileContent->getBytes());
        self::assertSame('media/902', $agentDriveFileContent->getDisplayName());
        self::assertSame('application/pdf', $agentDriveFileContent->contentType);
        self::assertSame(['MODEL'], $this->flowPolicy->askedTargetKinds);

        try {
            $this->fileSourcesStore->readDriveFileContent($call, $this->resolve('media/902'), 3);
            self::fail('a document over the limit was read');
        } catch (BadRequestException) {
        }
        $this->flowPolicy->fileFlowDecision = FileFlowDecision::refuse('media/902 stays where it is.');
        $readCountBefore = $this->mediaSource->readCount;
        try {
            $this->fileSourcesStore->readDriveFileContent($call, $this->resolve('media/902'), 1000);
            self::fail('a file the flow rule keeps from the model was read');
        } catch (ForbiddenException $forbiddenException) {
            self::assertSame('media/902 stays where it is.', $forbiddenException->getMessage());
        }
        self::assertSame($readCountBefore, $this->mediaSource->readCount, 'no byte is read before the flow rule said yes');

        $this->flowPolicy->fileFlowDecision = null;
        $this->expectException(NotFoundException::class);
        $missingPath = new AgentDrivePath();
        $missingPath->reference = 'media/404';
        $this->fileSourcesStore->readDriveFileContent($call, $missingPath, 1000);
    }
}
