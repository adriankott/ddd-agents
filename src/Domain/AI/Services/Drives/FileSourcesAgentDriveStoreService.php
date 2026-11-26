<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Drives;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Drives\AgentDriveFile;
use DDD\Domain\AI\Entities\Drives\AgentDriveFileContent;
use DDD\Domain\AI\Entities\Drives\AgentDriveFiles;
use DDD\Domain\AI\Entities\Drives\AgentDriveListing;
use DDD\Domain\AI\Entities\Drives\AgentDriveMount;
use DDD\Domain\AI\Entities\Drives\AgentDriveMounts;
use DDD\Domain\AI\Entities\Drives\AgentDrivePath;
use DDD\Domain\AI\Entities\Drives\AgentDriveSaveResult;
use DDD\Domain\AI\Entities\Drives\AgentFileSourceSettings;
use DDD\Domain\Common\Entities\Files\Sources\FileFacts;
use DDD\Domain\Common\Entities\Files\Sources\FileFlowTarget;
use DDD\Domain\Common\Entities\Files\Sources\FileReference;
use DDD\Domain\Common\Entities\Files\Sources\FileReferences;
use DDD\Domain\Common\Services\Files\FileFlowPolicyService;
use DDD\Domain\Common\Services\Files\FileSourcesService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * The drive tools' view of every file that is no drive file: a file of a registered
 * {@see \DDD\Domain\Common\Services\Files\FileSource} named by its reference `<source>/<id>` (`media/901`,
 * `support/3301`). It mounts nothing and holds no folders; {@see AgentDriveStoresService::resolveDrivePath()} hands
 * it a reference on a read-only mount of its own. Facts, cards and bytes come through {@see FileSourcesService}
 * under the rights of the account the call acts for; before bytes go to the model the application's flow rule is
 * asked ({@see FileFlowPolicyService}). It never takes a file: a copy into a drive is the drive store's.
 */
class FileSourcesAgentDriveStoreService extends Service implements AgentDriveStore, AgentDriveFileContentReader
{
    public const string STORE_NAME = 'files';

    /** @var string What the card and the model read for a file whose name and content came from outside */
    public const string STATE_THIRD_PARTY = 'written by a third party';

    public function getStoreName(): string
    {
        return self::STORE_NAME;
    }

    /** No mounts: a file of a source is named by its reference, never by a path */
    public function findMountsOfCall(AgentToolCallProvenance $callProvenance): AgentDriveMounts
    {
        return new AgentDriveMounts();
    }

    /** The read-only mount a resolved reference of a file source lies on */
    public function buildReferenceMount(FileReference $fileReference): AgentDriveMount
    {
        $agentDriveMount = new AgentDriveMount();
        $agentDriveMount->storeName = self::STORE_NAME;
        $agentDriveMount->mountName = $fileReference->sourceName;
        $agentDriveMount->spaceKey = $fileReference->sourceName;
        $agentDriveMount->title = $fileReference->sourceName;
        $agentDriveMount->mode = AgentDriveMount::MODE_READ_ONLY;
        return $agentDriveMount;
    }

    /**
     * A source has no folders: the refusal names the tool that finds its files.
     *
     * @throws BadRequestException
     */
    public function listDriveFiles(AgentToolCallProvenance $callProvenance, AgentDrivePath $agentDrivePath, ?string $cursor, int $limit): AgentDriveListing
    {
        $fileReference = FileReference::tryFromString((string)$agentDrivePath->reference);
        $sourceName = $fileReference?->sourceName ?? '';
        $findToolName = $this->getSettings()->findToolsBySourceName[$sourceName] ?? null;
        throw new BadRequestException(sprintf(
            '%s is one file of the source "%s", which has no folders to list. %s Its facts: showDriveFiles; its content: readDriveDocument or viewImages.',
            $agentDrivePath->argument,
            $sourceName,
            $findToolName !== null
                ? sprintf('Find files of this source with %s.', $findToolName)
                : 'Find files of this source with the tool of the skill that covers it.'
        ));
    }

    public function findDriveFiles(AgentToolCallProvenance $callProvenance, array $agentDrivePaths): AgentDriveFiles
    {
        $references = [];
        foreach ($agentDrivePaths as $agentDrivePath) {
            if ($agentDrivePath->reference !== null) {
                $references[] = $agentDrivePath->reference;
            }
        }
        return $this->findFileCards($references, null);
    }

    /**
     * @throws ForbiddenException A file source takes no files
     */
    public function saveDriveFiles(AgentToolCallProvenance $callProvenance, array $sourcePaths, AgentDriveMount $targetMount, string $targetFolderPath): AgentDriveSaveResult
    {
        throw new ForbiddenException('A file source takes no copies: name a folder of a drive as `to` (a folder of the own drive, or drivespaces/<space>/<folder>).');
    }

    /**
     * The bytes of a file of a source for the document tools.
     *
     * @throws NotFoundException
     * @throws ForbiddenException When the flow rule keeps the file from the model
     * @throws BadRequestException
     */
    public function readDriveFileContent(AgentToolCallProvenance $callProvenance, AgentDrivePath $agentDrivePath, int $maxBytes): AgentDriveFileContent
    {
        $fileSourcesService = $this->getFileSourcesService();
        $fileReference = $fileSourcesService->resolveFileReference((string)$agentDrivePath->reference);
        $this->requireFileFactsForModel($fileReference);
        $fileContent = $fileSourcesService->readFileContent($fileReference, $maxBytes);
        $agentDriveFileContent = AgentDriveFileContent::fromBytes($fileContent->getContent(), $fileContent->fileFacts->name, $fileContent->fileFacts->contentType);
        $agentDriveFileContent->reference = $fileReference->toString();
        $agentDriveFileContent->workspacePath = $fileReference->toString();
        return $agentDriveFileContent;
    }

    public function findFileCards(array $references, ?int $widgetSelfHydrationKey): AgentDriveFiles
    {
        $fileReferences = new FileReferences();
        foreach ($references as $reference) {
            $fileReference = $this->getFileSourcesService()->findFileReference((string)$reference);
            if ($fileReference !== null) {
                $fileReferences->add($fileReference);
            }
        }
        $agentDriveFiles = new AgentDriveFiles();
        if ($fileReferences->count() === 0) {
            return $agentDriveFiles;
        }
        foreach ($this->getFileSourcesService()->findFilesFacts($fileReferences)->getElements() as $fileFacts) {
            $agentDriveFile = $this->buildAgentDriveFile($fileFacts);
            $agentDriveFiles->add($agentDriveFile);
        }
        return $agentDriveFiles;
    }

    /**
     * The facts of a file the model is about to receive bytes of: the caller reads it and the flow rule lets it go
     * to the model.
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     * @throws BadRequestException
     */
    public function requireFileFactsForModel(FileReference $fileReference): FileFacts
    {
        $fileFacts = $this->getFileSourcesService()->requireFileFacts($fileReference);
        $fileFlowDecision = $this->getFileFlowPolicyService()->decideFileFlow($fileFacts, FileFlowTarget::forModel());
        if (!$fileFlowDecision->isAllowed) {
            throw new ForbiddenException($fileFlowDecision->refusalReason ?? sprintf('%s must not be read here.', $fileReference->toString()));
        }
        return $fileFacts;
    }

    /** One file of a source for the model and the cards: the reference is its path */
    public function buildAgentDriveFile(FileFacts $fileFacts): AgentDriveFile
    {
        $agentDriveFile = new AgentDriveFile();
        $agentDriveFile->storeName = self::STORE_NAME;
        $agentDriveFile->reference = $fileFacts->fileReference->toString();
        $agentDriveFile->path = $fileFacts->fileReference->toString();
        $agentDriveFile->name = $fileFacts->name;
        $agentDriveFile->sizeBytes = $fileFacts->sizeBytes;
        $agentDriveFile->contentType = $fileFacts->contentType;
        $agentDriveFile->description = $fileFacts->containerLabel;
        $agentDriveFile->state = $fileFacts->isContentUntrusted ? self::STATE_THIRD_PARTY : null;
        $agentDriveFile->setOpenLinkIfSafe($fileFacts->openLink);
        return $agentDriveFile;
    }

    public function getSettings(): AgentFileSourceSettings
    {
        return AgentFileSourceSettings::fromConfigNode(Config::get(AgentFileSourceSettings::CONFIG_KEY));
    }

    protected function getFileSourcesService(): FileSourcesService
    {
        /** @var FileSourcesService $fileSourcesService */
        $fileSourcesService = DDDService::instance()->getService(FileSourcesService::class);
        return $fileSourcesService;
    }

    protected function getFileFlowPolicyService(): FileFlowPolicyService
    {
        /** @var FileFlowPolicyService $fileFlowPolicyService */
        $fileFlowPolicyService = DDDService::instance()->getService(FileFlowPolicyService::class);
        return $fileFlowPolicyService;
    }
}
