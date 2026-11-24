<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentRequest;
use DDD\Domain\AI\Entities\Conversations\Widgets\DriveFilesConversationWidget;
use DDD\Domain\AI\Entities\Drives\AgentDriveFiles;
use DDD\Domain\AI\Entities\Drives\AgentDriveMount;
use DDD\Domain\AI\Entities\Drives\AgentDrivePath;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolParallelSafe;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolRequiresOwnerConfirmation;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolSummary;
use DDD\Domain\AI\Services\Conversations\Agent\OwnerConsentsService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Drives\AgentDriveStoresService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentDriveFileSaveRequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentDriveFilesListRequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentDriveFilesMcpResponseDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentDriveFilesShowRequestDto;

/**
 * The drive tools every drive store shares: browse a mount without a download, copy files into another space (on
 * the server, with the owner's consent where the mount asks for it) and show files as cards in the chat. The store
 * behind a mount is a module's {@see \DDD\Domain\AI\Services\Drives\AgentDriveStore}; without one installed the
 * package is unavailable.
 */
class AgentDriveMCPPackage extends MCPPackage
{
    public static ?string $name = 'agent.drive';

    public static ?string $description = 'Browse the drive mounts without a download (listDriveFiles), copy files into another drive space on the server (saveDriveFile) and show files to the owner as cards (showDriveFiles).';

    public const int DEFAULT_LIST_LIMIT = 100;
    public const int MAX_LIST_LIMIT = 500;
    public const int MAX_SAVE_PATHS = 50;

    public const string CONSENT_ACTION_KIND = 'copy files into a shared drive space';

    public static function isAvailable(): bool
    {
        /** @var AgentDriveStoresService $agentDriveStoresService */
        $agentDriveStoresService = DDDService::instance()->getService(AgentDriveStoresService::class);
        return $agentDriveStoresService->hasDriveStores();
    }

    /**
     * Lists one folder of the own drive or of a mounted space: names, sizes, dates, versions and references, no
     * content and no download. `drivespaces` lists the mounted spaces with their write mode. For a search by name or
     * content use the drive's search tool.
     *
     * @example {}
     * @example {"path": "drivespaces/library/Brand"}
     * @example {"path": "reports", "cursor": "c2"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolParallelSafe('reads of the drive store')]
    #[MCPToolSummary('Lists one folder of the own drive or a mounted space (drivespaces/<name>/…) without a download; `drivespaces` lists the mounts.')]
    public function listDriveFiles(
        AgentDriveFilesListRequestDto $requestDto,
        AgentDriveStoresService $agentDriveStoresService,
        AgentToolCallProvenance $callProvenance
    ): AgentDriveFilesMcpResponseDto {
        $agentDriveMounts = $agentDriveStoresService->findMountsOfCall($callProvenance);
        $requestedPath = trim((string)$requestDto->path);
        $agentDriveFilesMcpResponseDto = new AgentDriveFilesMcpResponseDto();
        if (trim($requestedPath, '/') === AgentDriveMount::MOUNTED_SPACES_FOLDER || $agentDriveMounts->count() === 0) {
            $agentDriveFilesMcpResponseDto->mounts = $agentDriveMounts;
            return $agentDriveFilesMcpResponseDto;
        }
        $agentDrivePath = $agentDriveStoresService->resolveDrivePath($agentDriveMounts, $requestedPath);
        $limit = max(1, min(self::MAX_LIST_LIMIT, $requestDto->limit ?? self::DEFAULT_LIST_LIMIT));
        $agentDriveListing = $agentDriveStoresService->requireDriveStoreOfPath($agentDrivePath)
            ->listDriveFiles($callProvenance, $agentDrivePath, $requestDto->cursor, $limit);
        $agentDriveFilesMcpResponseDto->path = $agentDriveListing->path;
        $agentDriveFilesMcpResponseDto->files = $agentDriveListing->agentDriveFiles ?? new AgentDriveFiles();
        $agentDriveFilesMcpResponseDto->nextCursor = $agentDriveListing->nextCursor;
        $agentDriveFilesMcpResponseDto->totalCount = $agentDriveListing->totalCount;
        return $agentDriveFilesMcpResponseDto;
    }

    /**
     * Copies files into a folder of another drive space, or from a mounted space into the own drive, on the server:
     * no byte passes the workspace. A file of the own drive is committed first. A space people outside the staff see
     * needs the owner's consent first (ask, naming the space and who sees it); a read-only mount refuses.
     *
     * @example {"paths": ["reports/Zuordnung.xlsx"], "to": "drivespaces/world-31/Reports"}
     * @example {"paths": ["drivespaces/library/Brand/Logo.svg"], "to": "lib/brand"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::WRITE)]
    #[MCPToolRequiresOwnerConfirmation]
    #[MCPToolSummary('Copies files into a folder of another drive space (or from a mount into the own drive) on the server; an outward-visible space needs the owner\'s consent first.')]
    public function saveDriveFile(
        AgentDriveFileSaveRequestDto $requestDto,
        AgentDriveStoresService $agentDriveStoresService,
        OwnerConsentsService $ownerConsentsService,
        AgentToolCallProvenance $callProvenance
    ): AgentDriveFilesMcpResponseDto {
        $sourceArguments = $this->findNonEmptyArguments($requestDto->paths);
        if ($sourceArguments === []) {
            throw new BadRequestException('saveDriveFile needs `paths`: the files to copy.');
        }
        if (count($sourceArguments) > self::MAX_SAVE_PATHS) {
            throw new BadRequestException(sprintf('saveDriveFile copies at most %d files per call.', self::MAX_SAVE_PATHS));
        }
        if (trim($requestDto->to) === '') {
            throw new BadRequestException('saveDriveFile needs `to`: the target folder (drivespaces/<space>/<folder> or a folder of the own drive).');
        }
        $agentDriveMounts = $agentDriveStoresService->findMountsOfCall($callProvenance);
        $targetPath = $agentDriveStoresService->resolveDrivePath($agentDriveMounts, $requestDto->to);
        $targetMount = $targetPath->agentDriveMount;
        if ($targetMount === null || $targetPath->reference !== null) {
            throw new BadRequestException('`to` must be a folder path, not a file reference.');
        }
        $targetWriteMode = $targetMount->getEffectiveWriteMode();
        if ($targetWriteMode === AgentDriveMount::MODE_READ_ONLY) {
            throw new ForbiddenException(sprintf('%s is read-only for you: nothing was copied.', $targetMount->isOwnDrive ? 'The own drive' : rtrim($targetMount->getPathPrefix(), '/')));
        }
        $sourcePaths = [];
        foreach ($sourceArguments as $sourceArgument) {
            $sourcePaths[] = $agentDriveStoresService->resolveDrivePath($agentDriveMounts, $sourceArgument);
        }
        $agentDriveStore = $agentDriveStoresService->requireDriveStoreOfPath($targetPath);

        $ownerConsentReservation = null;
        if ($targetWriteMode === AgentDriveMount::MODE_WRITE_WITH_CONSENT) {
            $ownerConsentReservation = $ownerConsentsService->requireOwnerConsentForStep($callProvenance, $this->buildConsentRequest($sourcePaths, $targetPath));
        }
        $agentDriveSaveResult = $agentDriveStore->saveDriveFiles($callProvenance, $sourcePaths, $targetMount, $targetPath->pathInMount);
        if ($ownerConsentReservation !== null) {
            if ($agentDriveSaveResult->hasSavedFiles()) {
                $ownerConsentsService->markConsentUsed($callProvenance, $ownerConsentReservation);
            } else {
                $ownerConsentsService->releaseConsent($callProvenance, $ownerConsentReservation);
            }
        }

        $agentDriveFilesMcpResponseDto = new AgentDriveFilesMcpResponseDto();
        $agentDriveFilesMcpResponseDto->files = $agentDriveSaveResult->savedFiles ?? new AgentDriveFiles();
        $agentDriveFilesMcpResponseDto->refusedLines = $agentDriveSaveResult->refusedLines;
        $agentDriveFilesMcpResponseDto->note = $agentDriveSaveResult->hasSavedFiles()
            ? sprintf('Copied %d file(s) into %s.', $agentDriveSaveResult->savedFiles->count(), $targetPath->getWorkspacePath())
            : 'Nothing was copied.';
        return $agentDriveFilesMcpResponseDto;
    }

    /**
     * Shows files and folders to the owner as cards in the chat (name, type, size, date; open and download for people
     * with the right; a folder row shows its entries). Use it instead of pasting a link or the content. Uncommitted files are committed first by the drive;
     * name files by workspace path or reference.
     *
     * @example {"paths": ["reports/Zuordnung.xlsx"]}
     * @example {"paths": ["drive/812", "drivespaces/library/Brand/Logo.svg"], "title": "Logos"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolSummary('Shows drive files to the owner as cards in the chat (open, download); never paste links or content instead.')]
    public function showDriveFiles(
        AgentDriveFilesShowRequestDto $requestDto,
        AgentDriveStoresService $agentDriveStoresService,
        AIConversationMessagesService $aIConversationMessagesService,
        AgentToolCallProvenance $callProvenance
    ): AgentDriveFilesMcpResponseDto {
        $fileArguments = $this->findNonEmptyArguments($requestDto->paths);
        if ($fileArguments === []) {
            throw new BadRequestException('showDriveFiles needs `paths`: the files to show.');
        }
        if (count($fileArguments) > DriveFilesConversationWidget::MAX_FILES) {
            throw new BadRequestException(sprintf('showDriveFiles shows at most %d files per card.', DriveFilesConversationWidget::MAX_FILES));
        }
        $agentDriveMounts = $agentDriveStoresService->findMountsOfCall($callProvenance);
        $agentDrivePathsByStore = [];
        foreach ($fileArguments as $fileArgument) {
            $agentDrivePath = $agentDriveStoresService->resolveDrivePath($agentDriveMounts, $fileArgument);
            $agentDrivePathsByStore[$agentDrivePath->agentDriveMount?->storeName ?? ''][] = $agentDrivePath;
        }
        $shownFiles = new AgentDriveFiles();
        $missingArguments = [];
        $widgetNotes = [];
        foreach ($agentDrivePathsByStore as $storeName => $agentDrivePaths) {
            $agentDriveStore = $agentDriveStoresService->requireDriveStoreOfPath($agentDrivePaths[0]);
            $foundFiles = $agentDriveStore->findDriveFiles($callProvenance, $agentDrivePaths);
            $references = [];
            // folders are shown too: the card renders a folder row (its entries, a link to the drive screen)
            foreach ($foundFiles->getElements() as $foundFile) {
                $references[] = $foundFile->reference;
                $shownFiles->add($foundFile);
            }
            $missingArguments = [...$missingArguments, ...$this->findArgumentsWithoutFile($agentDrivePaths, $foundFiles)];
            if ($references === []) {
                continue;
            }
            $driveFilesWidget = new DriveFilesConversationWidget();
            $driveFilesWidget->store = (string)$storeName;
            $driveFilesWidget->references = $references;
            $driveFilesWidget->title = $requestDto->title !== null && trim($requestDto->title) !== '' ? mb_substr(trim($requestDto->title), 0, 160) : null;
            $driveFilesWidget->widgetSelfHydrationKey = $callProvenance->agentSpace?->getWidgetSelfHydrationKey();
            $conversationWidgetDelivery = $aIConversationMessagesService->appendWidget($callProvenance, $driveFilesWidget);
            $dictationNote = $driveFilesWidget->buildDictationNote($conversationWidgetDelivery, 'Name the files and where they are in one short sentence.');
            if ($dictationNote !== null) {
                $widgetNotes[] = $dictationNote;
            }
        }
        if ($shownFiles->count() === 0) {
            throw new NotFoundException('None of these files or folders exists in a mounted drive: ' . implode(', ', $fileArguments) . '. Check the paths with listDriveFiles.');
        }
        $agentDriveFilesMcpResponseDto = new AgentDriveFilesMcpResponseDto();
        $agentDriveFilesMcpResponseDto->files = $shownFiles;
        $agentDriveFilesMcpResponseDto->refusedLines = array_map([$this, 'buildNotFoundLine'], $missingArguments);
        $agentDriveFilesMcpResponseDto->note = $widgetNotes !== []
            ? implode(' ', $widgetNotes)
            : 'The files are shown as cards. Write one short line about them; do not list or link them again.';
        return $agentDriveFilesMcpResponseDto;
    }

    /** @param AgentDrivePath[] $sourcePaths */
    protected function buildConsentRequest(array $sourcePaths, AgentDrivePath $targetPath): OwnerConsentRequest
    {
        $sourceWorkspacePaths = [];
        foreach ($sourcePaths as $sourcePath) {
            $sourceWorkspacePaths[] = $sourcePath->getWorkspacePath();
        }
        sort($sourceWorkspacePaths);
        $targetMount = $targetPath->agentDriveMount;
        $targetLine = sprintf(
            'copy %s into %s (%s)',
            implode(', ', $sourceWorkspacePaths),
            $targetPath->getWorkspacePath(),
            $targetMount?->title ?? ''
        );
        $consequenceLine = $targetMount?->audienceDescription !== null
            ? 'afterwards ' . $targetMount->audienceDescription . ' can open and download the files'
            : 'afterwards the people of that space can open and download the files';
        return OwnerConsentRequest::forAction(
            'saveDriveFile',
            self::CONSENT_ACTION_KIND,
            $targetLine,
            $consequenceLine,
            $targetPath->getWorkspacePath() . '|' . implode('|', $sourceWorkspacePaths)
        );
    }

    /**
     * @param string[] $arguments
     * @return string[]
     */
    protected function findNonEmptyArguments(array $arguments): array
    {
        $nonEmptyArguments = [];
        foreach ($arguments as $argument) {
            if (is_string($argument) && trim($argument) !== '') {
                $nonEmptyArguments[] = trim($argument);
            }
        }
        return array_values(array_unique($nonEmptyArguments));
    }

    /**
     * @param AgentDrivePath[] $agentDrivePaths
     * @return string[]
     */
    protected function findArgumentsWithoutFile(array $agentDrivePaths, AgentDriveFiles $foundFiles): array
    {
        $foundKeys = [];
        foreach ($foundFiles->getElements() as $foundFile) {
            $foundKeys[$foundFile->reference] = true;
            $foundKeys[$foundFile->path] = true;
        }
        $argumentsWithoutFile = [];
        foreach ($agentDrivePaths as $agentDrivePath) {
            if (!isset($foundKeys[$agentDrivePath->getWorkspacePath()])) {
                $argumentsWithoutFile[] = $agentDrivePath->argument;
            }
        }
        return $argumentsWithoutFile;
    }

    public function buildNotFoundLine(string $argument): string
    {
        return $argument . ': not found';
    }
}
