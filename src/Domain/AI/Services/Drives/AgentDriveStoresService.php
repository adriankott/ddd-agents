<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Drives;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Drives\AgentDriveFiles;
use DDD\Domain\AI\Entities\Drives\AgentDriveMount;
use DDD\Domain\AI\Entities\Drives\AgentDriveMounts;
use DDD\Domain\AI\Entities\Drives\AgentDrivePath;
use DDD\Domain\AI\Entities\Drives\AgentDriveSaveRefusals;
use DDD\Domain\Common\Entities\Files\Sources\FileReference;
use DDD\Domain\Common\Services\Files\FileSourcesService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\Attribute\Required;
use Throwable;

/**
 * The registry of the drive stores ({@see AgentDriveStore}, every service carrying the tag) and the resolution of a
 * file argument against the mounts of a call: `drivespaces/<name>/…` is that mount, `drive/<id>` a reference of the
 * own drive's store, `<source>/<id>` a file of a registered file source of the application (`media/901`; read
 * through {@see FileSourcesAgentDriveStoreService}), any other path a path in the own drive.
 */
class AgentDriveStoresService extends Service
{
    /** @var string An absolute workspace path (`/workspace/reports/Plan.md`) is read without this root */
    public const string WORKSPACE_ROOT_FOLDER = 'workspace';

    /** @var iterable<AgentDriveStore> */
    protected iterable $agentDriveStores = [];

    /** @param iterable<AgentDriveStore> $agentDriveStores */
    #[Required]
    public function setAgentDriveStores(
        #[AutowireIterator(AgentDriveStore::TAG)]
        iterable $agentDriveStores
    ): void {
        $this->agentDriveStores = $agentDriveStores;
    }

    /** @var iterable<AgentDriveSavePrecheck> */
    protected iterable $agentDriveSavePrechecks = [];

    /** @param iterable<AgentDriveSavePrecheck> $agentDriveSavePrechecks */
    #[Required]
    public function setAgentDriveSavePrechecks(
        #[AutowireIterator(AgentDriveSavePrecheck::TAG)]
        iterable $agentDriveSavePrechecks
    ): void {
        $this->agentDriveSavePrechecks = $agentDriveSavePrechecks;
    }

    /**
     * The sources a save into the target would refuse anyway, from the target store's precheck
     * ({@see AgentDriveSavePrecheck}); empty when the store has none.
     *
     * @param AgentDrivePath[] $sourcePaths
     */
    public function findSaveRefusals(
        AgentToolCallProvenance $callProvenance,
        array $sourcePaths,
        AgentDriveMount $targetMount,
        string $targetFolderPath
    ): AgentDriveSaveRefusals {
        foreach ($this->agentDriveSavePrechecks as $agentDriveSavePrecheck) {
            if ($agentDriveSavePrecheck->getStoreName() === $targetMount->storeName) {
                return $agentDriveSavePrecheck->findSaveRefusals($callProvenance, $sourcePaths, $targetMount, $targetFolderPath);
            }
        }
        return new AgentDriveSaveRefusals();
    }

    /** True when at least one drive store with spaces is installed (the adapter of the file sources mounts nothing) */
    public function hasDriveStores(): bool
    {
        foreach ($this->agentDriveStores as $agentDriveStore) {
            if ($agentDriveStore->getStoreName() !== FileSourcesAgentDriveStoreService::STORE_NAME) {
                return true;
            }
        }
        return false;
    }

    /** True when the application registered a file source besides the drive's own (`drive/<id>` is a drive store's) */
    public function hasFileSources(): bool
    {
        foreach ($this->getFileSourcesService()->getSourceNames() as $sourceName) {
            if ($sourceName . '/' !== AgentDrivePath::REFERENCE_PREFIX) {
                return true;
            }
        }
        return false;
    }

    /**
     * The file reference an argument names when it is `<source>/<id>` of a registered file source other than the
     * drive: exactly that form, so `media/901` is the media item and `/workspace/media/901` or `./media/901` the
     * file of the own drive at that path.
     */
    public function findFileSourceReference(string $argument): ?FileReference
    {
        $fileReference = $this->getFileSourcesService()->findFileReference(trim(str_replace('\\', '/', $argument)));
        if ($fileReference === null || $fileReference->sourceName . '/' === AgentDrivePath::REFERENCE_PREFIX) {
            return null;
        }
        return $fileReference;
    }

    public function findDriveStoreByName(string $storeName): ?AgentDriveStore
    {
        foreach ($this->agentDriveStores as $agentDriveStore) {
            if ($agentDriveStore->getStoreName() === $storeName) {
                return $agentDriveStore;
            }
        }
        // the adapter of the file sources is the package's own store: it answers even where no tag registered it
        return $storeName === FileSourcesAgentDriveStoreService::STORE_NAME ? $this->getFileSourcesAgentDriveStoreService() : null;
    }

    /** The mounts of every store for the call, the own drive first; a store that fails is left out (and logged) */
    public function findMountsOfCall(AgentToolCallProvenance $callProvenance): AgentDriveMounts
    {
        $ownDriveMounts = [];
        $furtherMounts = [];
        foreach ($this->agentDriveStores as $agentDriveStore) {
            try {
                foreach ($agentDriveStore->findMountsOfCall($callProvenance)->getElements() as $agentDriveMount) {
                    if ($agentDriveMount->isOwnDrive) {
                        $ownDriveMounts[] = $agentDriveMount;
                    } else {
                        $furtherMounts[] = $agentDriveMount;
                    }
                }
            } catch (Throwable $throwable) {
                DDDService::instance()->getLogger()->warning('[AgentDrive] mounts of a store failed', ['store' => $agentDriveStore->getStoreName(), 'reason' => $throwable->getMessage()]);
            }
        }
        $agentDriveMounts = new AgentDriveMounts();
        foreach ([...$ownDriveMounts, ...$furtherMounts] as $agentDriveMount) {
            $agentDriveMounts->add($agentDriveMount);
        }
        return $agentDriveMounts;
    }

    /**
     * The mount and the path inside it for a file argument.
     *
     * @throws BadRequestException When the argument is empty, leaves its mount (`..`) or names a mount the call lacks
     */
    public function resolveDrivePath(AgentDriveMounts $agentDriveMounts, string $argument): AgentDrivePath
    {
        $agentDrivePath = new AgentDrivePath();
        $agentDrivePath->argument = $argument;
        $fileSourceReference = $this->findFileSourceReference($argument);
        if ($fileSourceReference !== null) {
            $agentDrivePath->reference = $fileSourceReference->toString();
            $agentDrivePath->agentDriveMount = $this->getFileSourcesAgentDriveStoreService()->buildReferenceMount($fileSourceReference);
            return $agentDrivePath;
        }
        $normalizedArgument = trim(str_replace('\\', '/', trim($argument)), '/');
        if ($normalizedArgument === self::WORKSPACE_ROOT_FOLDER || str_starts_with($normalizedArgument, self::WORKSPACE_ROOT_FOLDER . '/')) {
            $normalizedArgument = trim(substr($normalizedArgument, strlen(self::WORKSPACE_ROOT_FOLDER)), '/');
        }
        $normalizedArgument = preg_replace('#^(\./)+#', '', ltrim($normalizedArgument, '/')) ?? '';
        if (in_array('..', explode('/', $normalizedArgument), true)) {
            throw new BadRequestException(sprintf('"%s" leaves its folder (..): name the file by its path in the workspace or by its reference drive/<id>.', $argument));
        }
        if (preg_match('#^' . preg_quote(AgentDrivePath::REFERENCE_PREFIX, '#') . '[A-Za-z0-9_-]+$#', $normalizedArgument) === 1) {
            $agentDrivePath->reference = $normalizedArgument;
            $agentDrivePath->agentDriveMount = $agentDriveMounts->findOwnDrive() ?? $agentDriveMounts->first();
            if ($agentDrivePath->agentDriveMount === null) {
                throw new BadRequestException('No drive is mounted in this conversation.');
            }
            return $agentDrivePath;
        }
        $mountedSpacesPrefix = AgentDriveMount::MOUNTED_SPACES_FOLDER . '/';
        if ($normalizedArgument === AgentDriveMount::MOUNTED_SPACES_FOLDER || str_starts_with($normalizedArgument, $mountedSpacesPrefix)) {
            $pathParts = explode('/', substr($normalizedArgument, strlen($mountedSpacesPrefix)), 2);
            $mountName = $pathParts[0] ?? '';
            $agentDriveMount = $mountName !== '' ? $agentDriveMounts->findByMountName($mountName) : null;
            if ($agentDriveMount === null) {
                throw new BadRequestException(sprintf(
                    '"%s" names no mounted space. Mounted: %s.',
                    $argument,
                    $this->buildMountNameList($agentDriveMounts)
                ));
            }
            $agentDrivePath->agentDriveMount = $agentDriveMount;
            $agentDrivePath->pathInMount = trim($pathParts[1] ?? '', '/');
            return $agentDrivePath;
        }
        $agentDrivePath->agentDriveMount = $agentDriveMounts->findOwnDrive();
        if ($agentDrivePath->agentDriveMount === null) {
            throw new BadRequestException(sprintf(
                '"%s": this conversation has no own drive. Mounted: %s.',
                $argument,
                $this->buildMountNameList($agentDriveMounts)
            ));
        }
        $agentDrivePath->pathInMount = $normalizedArgument;
        return $agentDrivePath;
    }

    /**
     * The store of a resolved path.
     *
     * @throws NotFoundException
     */
    public function requireDriveStoreOfPath(AgentDrivePath $agentDrivePath): AgentDriveStore
    {
        $storeName = $agentDrivePath->agentDriveMount?->storeName ?? '';
        $agentDriveStore = $this->findDriveStoreByName($storeName);
        if ($agentDriveStore === null) {
            throw new NotFoundException(sprintf('The drive store "%s" is not installed.', $storeName));
        }
        return $agentDriveStore;
    }

    /**
     * The file card rows of one store; empty when the store is not installed or fails.
     *
     * @param string[] $references
     */
    public function findFileCards(string $storeName, array $references, ?int $widgetSelfHydrationKey): AgentDriveFiles
    {
        $agentDriveStore = $this->findDriveStoreByName($storeName);
        if ($agentDriveStore === null || $references === []) {
            return new AgentDriveFiles();
        }
        try {
            return $agentDriveStore->findFileCards($references, $widgetSelfHydrationKey);
        } catch (Throwable $throwable) {
            DDDService::instance()->getLogger()->warning('[AgentDrive] file cards failed', ['store' => $storeName, 'reason' => $throwable->getMessage()]);
            return new AgentDriveFiles();
        }
    }

    protected function getFileSourcesService(): FileSourcesService
    {
        /** @var FileSourcesService $fileSourcesService */
        $fileSourcesService = DDDService::instance()->getService(FileSourcesService::class);
        return $fileSourcesService;
    }

    protected function getFileSourcesAgentDriveStoreService(): FileSourcesAgentDriveStoreService
    {
        /** @var FileSourcesAgentDriveStoreService $fileSourcesAgentDriveStoreService */
        $fileSourcesAgentDriveStoreService = DDDService::instance()->getService(FileSourcesAgentDriveStoreService::class);
        return $fileSourcesAgentDriveStoreService;
    }

    protected function buildMountNameList(AgentDriveMounts $agentDriveMounts): string
    {
        $mountNames = [];
        foreach ($agentDriveMounts->getElements() as $agentDriveMount) {
            $mountNames[] = $agentDriveMount->isOwnDrive ? 'the own drive (paths without prefix)' : rtrim($agentDriveMount->getPathPrefix(), '/');
        }
        return $mountNames === [] ? 'none' : implode(', ', $mountNames);
    }
}
