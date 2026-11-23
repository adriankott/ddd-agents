<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Drives;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Drives\AgentDriveFiles;
use DDD\Domain\AI\Entities\Drives\AgentDriveListing;
use DDD\Domain\AI\Entities\Drives\AgentDriveMount;
use DDD\Domain\AI\Entities\Drives\AgentDriveMounts;
use DDD\Domain\AI\Entities\Drives\AgentDrivePath;
use DDD\Domain\AI\Entities\Drives\AgentDriveSaveResult;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A file store the agent reaches through mounts: the agent space's own drive and further spaces below
 * `drivespaces/<name>/`. A module implements it as a service (registered by this tag, autoconfigured); the agent's
 * drive tools ({@see \DDD\Presentation\Mcp\Client\AI\AgentDriveMCPPackage}) and the file card call every store through
 * {@see AgentDriveStoresService} and import none of them.
 *
 * Every method answers under the rights of the account the call acts for: a file the caller may not read is "not
 * found", never an error that names it. Facts only: no method hands out content or a URL.
 */
#[AutoconfigureTag(AgentDriveStore::TAG)]
interface AgentDriveStore
{
    public const string TAG = 'ai.agent_drive_store';

    /** The store's name in mounts and file references, e.g. `clouddrive` */
    public function getStoreName(): string;

    /** The mounts of the call's agent space: the own drive and the spaces it may read; empty when it has none */
    public function findMountsOfCall(AgentToolCallProvenance $callProvenance): AgentDriveMounts;

    /**
     * One page of a folder (or the one file a path names), without a download.
     *
     * @throws NotFoundException
     * @throws BadRequestException
     */
    public function listDriveFiles(
        AgentToolCallProvenance $callProvenance,
        AgentDrivePath $agentDrivePath,
        ?string $cursor,
        int $limit
    ): AgentDriveListing;

    /**
     * Facts of the files and folders the paths or references name; the ones the caller may not read are left out.
     *
     * @param AgentDrivePath[] $agentDrivePaths
     */
    public function findDriveFiles(AgentToolCallProvenance $callProvenance, array $agentDrivePaths): AgentDriveFiles;

    /**
     * Copies files into a folder of a mount, on the server: a drive source as a new node or a new version with its
     * origin, a workspace file after the store took it in. Consent and the mount's mode are checked by the caller.
     *
     * @param AgentDrivePath[] $sourcePaths
     * @throws NotFoundException
     * @throws BadRequestException
     */
    public function saveDriveFiles(
        AgentToolCallProvenance $callProvenance,
        array $sourcePaths,
        AgentDriveMount $targetMount,
        string $targetFolderPath
    ): AgentDriveSaveResult;

    /**
     * The file card's rows: facts of the referenced files the viewer may read, in a conversation whose widgets carry
     * the hydration key (null = no agent space).
     *
     * @param string[] $references
     */
    public function findFileCards(array $references, ?int $widgetSelfHydrationKey): AgentDriveFiles;
}
