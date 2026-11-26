<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Drives\Fixtures;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Drives\AgentDriveMount;
use DDD\Domain\AI\Entities\Drives\AgentDriveSaveRefusal;
use DDD\Domain\AI\Entities\Drives\AgentDriveSaveRefusals;
use DDD\Domain\AI\Services\Drives\AgentDriveSavePrecheck;

/** The precheck of the in-memory store: refuses the source arguments it is given, with their reasons */
class RefusingAgentDriveSavePrecheck implements AgentDriveSavePrecheck
{
    /** @var string[] Reason by refused source argument */
    public array $reasonsBySourceArgument = [];

    public function getStoreName(): string
    {
        return InMemoryAgentDriveStore::STORE_NAME;
    }

    public function findSaveRefusals(AgentToolCallProvenance $callProvenance, array $sourcePaths, AgentDriveMount $targetMount, string $targetFolderPath): AgentDriveSaveRefusals
    {
        $agentDriveSaveRefusals = new AgentDriveSaveRefusals();
        foreach ($sourcePaths as $sourcePath) {
            if (isset($this->reasonsBySourceArgument[$sourcePath->argument])) {
                $agentDriveSaveRefusal = AgentDriveSaveRefusal::forSourceArgument($sourcePath->argument, $this->reasonsBySourceArgument[$sourcePath->argument]);
                $agentDriveSaveRefusals->add($agentDriveSaveRefusal);
            }
        }
        return $agentDriveSaveRefusals;
    }
}
