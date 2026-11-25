<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Installation;

use DDD\Domain\AI\Services\Installation\AgentDocumentToolsInstallationCheckService;

/** The document tools check with the tools bound and only the programs of `$programsOnPath` on the PATH */
class AgentDocumentToolsInstallationCheckWithoutProgramsDouble extends AgentDocumentToolsInstallationCheckService
{
    /** @var string[] */
    public array $programsOnPath = [];

    protected function areDocumentToolsBound(): bool
    {
        return true;
    }

    protected function isProgramOnPath(string $programName): bool
    {
        return in_array($programName, $this->programsOnPath, true);
    }
}
