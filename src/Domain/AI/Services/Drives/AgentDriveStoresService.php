<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Drives;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Drives\AgentDriveFiles;
use DDD\Domain\AI\Entities\Drives\AgentDriveMount;
use DDD\Domain\AI\Entities\Drives\AgentDriveMounts;
use DDD\Domain\AI\Entities\Drives\AgentDrivePath;
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
 * own drive's store, any other path a path in the own drive.
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

    /** True when at least one drive store is installed */
    public function hasDriveStores(): bool
    {
        foreach ($this->agentDriveStores as $agentDriveStore) {
            return true;
        }
        return false;
    }

    public function findDriveStoreByName(string $storeName): ?AgentDriveStore
    {
        foreach ($this->agentDriveStores as $agentDriveStore) {
            if ($agentDriveStore->getStoreName() === $storeName) {
                return $agentDriveStore;
            }
        }
        return null;
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

    protected function buildMountNameList(AgentDriveMounts $agentDriveMounts): string
    {
        $mountNames = [];
        foreach ($agentDriveMounts->getElements() as $agentDriveMount) {
            $mountNames[] = $agentDriveMount->isOwnDrive ? 'the own drive (paths without prefix)' : rtrim($agentDriveMount->getPathPrefix(), '/');
        }
        return $mountNames === [] ? 'none' : implode(', ', $mountNames);
    }
}
