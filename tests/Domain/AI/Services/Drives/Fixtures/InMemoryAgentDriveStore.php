<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Drives\Fixtures;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Drives\AgentDriveFile;
use DDD\Domain\AI\Entities\Drives\AgentDriveFiles;
use DDD\Domain\AI\Entities\Drives\AgentDriveListing;
use DDD\Domain\AI\Entities\Drives\AgentDriveMount;
use DDD\Domain\AI\Entities\Drives\AgentDriveMounts;
use DDD\Domain\AI\Entities\Drives\AgentDrivePath;
use DDD\Domain\AI\Entities\Drives\AgentDriveSaveResult;
use DDD\Domain\AI\Services\Drives\AgentDriveStore;

/** A drive store of the tests: an own drive and a library mount, files by workspace path, saves recorded */
class InMemoryAgentDriveStore implements AgentDriveStore
{
    public const string STORE_NAME = 'testdrive';

    /** @var string[] Workspace paths of the files, path => reference */
    /** @var string[] Paths of referencesByPath that are folders */
    public array $folderPaths = [];

    public array $referencesByPath = [
        'reports/Plan.md' => 'drive/1',
        'drivespaces/library/Brand/Logo.svg' => 'drive/2',
    ];

    /** @var string[] What saveDriveFiles was asked to copy, one line per call */
    public array $savedLines = [];

    public string $libraryMode = AgentDriveMount::MODE_READ_ONLY;

    public bool $libraryIsOutwardVisible = false;

    public function getStoreName(): string
    {
        return self::STORE_NAME;
    }

    public function findMountsOfCall(AgentToolCallProvenance $callProvenance): AgentDriveMounts
    {
        $agentDriveMounts = new AgentDriveMounts();
        $ownDrive = new AgentDriveMount();
        $ownDrive->storeName = self::STORE_NAME;
        $ownDrive->title = 'World "Sommer"';
        $ownDrive->mode = AgentDriveMount::MODE_READ_WRITE;
        $ownDrive->isOwnDrive = true;
        $agentDriveMounts->add($ownDrive);
        $library = new AgentDriveMount();
        $library->storeName = self::STORE_NAME;
        $library->mountName = 'library';
        $library->title = 'Library';
        $library->mode = $this->libraryMode;
        $library->isOutwardVisible = $this->libraryIsOutwardVisible;
        $agentDriveMounts->add($library);
        return $agentDriveMounts;
    }

    public function listDriveFiles(AgentToolCallProvenance $callProvenance, AgentDrivePath $agentDrivePath, ?string $cursor, int $limit): AgentDriveListing
    {
        $agentDriveListing = new AgentDriveListing();
        $agentDriveListing->agentDriveMount = $agentDrivePath->agentDriveMount;
        $agentDriveListing->path = $agentDrivePath->getWorkspacePath();
        $agentDriveListing->agentDriveFiles = new AgentDriveFiles();
        foreach ($this->referencesByPath as $path => $reference) {
            if ($agentDriveListing->path === '' || str_starts_with($path, $agentDriveListing->path . '/')) {
                $agentDriveFile = $this->buildFile($path, $reference);
                $agentDriveListing->agentDriveFiles->add($agentDriveFile);
            }
        }
        return $agentDriveListing;
    }

    public function findDriveFiles(AgentToolCallProvenance $callProvenance, array $agentDrivePaths): AgentDriveFiles
    {
        $agentDriveFiles = new AgentDriveFiles();
        foreach ($agentDrivePaths as $agentDrivePath) {
            foreach ($this->referencesByPath as $path => $reference) {
                if ($path === $agentDrivePath->getWorkspacePath() || $reference === $agentDrivePath->reference) {
                    $agentDriveFile = $this->buildFile($path, $reference);
                    $agentDriveFiles->add($agentDriveFile);
                }
            }
        }
        return $agentDriveFiles;
    }

    public function saveDriveFiles(AgentToolCallProvenance $callProvenance, array $sourcePaths, AgentDriveMount $targetMount, string $targetFolderPath): AgentDriveSaveResult
    {
        $agentDriveSaveResult = new AgentDriveSaveResult();
        $agentDriveSaveResult->savedFiles = new AgentDriveFiles();
        foreach ($sourcePaths as $sourcePath) {
            $targetPath = $targetMount->getPathPrefix() . ($targetFolderPath !== '' ? $targetFolderPath . '/' : '') . basename($sourcePath->getWorkspacePath());
            $this->savedLines[] = $sourcePath->getWorkspacePath() . ' -> ' . $targetPath;
            $agentDriveFile = $this->buildFile($targetPath, 'drive/' . (100 + count($this->savedLines)));
            $agentDriveSaveResult->savedFiles->add($agentDriveFile);
        }
        return $agentDriveSaveResult;
    }

    public function findFileCards(array $references, ?int $widgetSelfHydrationKey): AgentDriveFiles
    {
        $agentDriveFiles = new AgentDriveFiles();
        foreach ($this->referencesByPath as $path => $reference) {
            if (in_array($reference, $references, true)) {
                $agentDriveFile = $this->buildFile($path, $reference);
                $agentDriveFiles->add($agentDriveFile);
            }
        }
        return $agentDriveFiles;
    }

    protected function buildFile(string $path, string $reference): AgentDriveFile
    {
        $agentDriveFile = new AgentDriveFile();
        $agentDriveFile->storeName = self::STORE_NAME;
        $agentDriveFile->reference = $reference;
        $agentDriveFile->path = $path;
        $agentDriveFile->name = basename($path);
        $agentDriveFile->isFolder = in_array($path, $this->folderPaths, true);
        $agentDriveFile->sizeBytes = $agentDriveFile->isFolder ? null : 2048;
        $agentDriveFile->versionNumber = $agentDriveFile->isFolder ? null : 1;
        return $agentDriveFile;
    }
}
