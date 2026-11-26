<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Drives;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Drives\AgentDriveMount;
use DDD\Domain\AI\Entities\Drives\AgentDrivePath;
use DDD\Domain\AI\Entities\Drives\AgentDriveSaveRefusals;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Optional companion of an {@see AgentDriveStore}: the sources of a save that store would refuse anyway, found
 * without writing or reading any bytes (the source is not readable for the caller, the application's flow rule keeps
 * it out of the target). `saveDriveFile` asks it BEFORE it asks the owner for consent: a step that is refused
 * regardless is never put to the owner, and the consent request names only the files that can be copied. A store
 * without a precheck is asked the owner for every source, as before.
 */
#[AutoconfigureTag(AgentDriveSavePrecheck::TAG)]
interface AgentDriveSavePrecheck
{
    public const string TAG = 'ai.agent_drive_save_precheck';

    /** The name of the drive store this precheck belongs to ({@see AgentDriveStore::getStoreName()}) */
    public function getStoreName(): string;

    /**
     * The sources the store's save would refuse, each with its reason; sources not named here may still be refused
     * by the save (a conflict, the size, the scan).
     *
     * @param AgentDrivePath[] $sourcePaths
     */
    public function findSaveRefusals(
        AgentToolCallProvenance $callProvenance,
        array $sourcePaths,
        AgentDriveMount $targetMount,
        string $targetFolderPath
    ): AgentDriveSaveRefusals;
}
