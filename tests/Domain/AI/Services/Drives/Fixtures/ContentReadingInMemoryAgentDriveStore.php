<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Drives\Fixtures;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Drives\AgentDriveFileContent;
use DDD\Domain\AI\Entities\Drives\AgentDrivePath;
use DDD\Domain\AI\Services\Drives\AgentDriveFileContentReader;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;

/** The test drive store that also hands out file contents: bytes by workspace path, refused paths, the reads recorded */
class ContentReadingInMemoryAgentDriveStore extends InMemoryAgentDriveStore implements AgentDriveFileContentReader
{
    /** @var array<string, string> The bytes of the files, workspace path => bytes */
    public array $bytesByPath = [];

    /** @var string[] Workspace paths the caller may not read (a scan, a right) */
    public array $forbiddenPaths = [];

    /** @var string[] The workspace paths read, in order */
    public array $readPaths = [];

    /** @var int[] The byte limits the reads were given, in order */
    public array $readMaxBytes = [];

    public function readDriveFileContent(AgentToolCallProvenance $callProvenance, AgentDrivePath $agentDrivePath, int $maxBytes): AgentDriveFileContent
    {
        $workspacePath = $agentDrivePath->getWorkspacePath();
        if ($agentDrivePath->reference !== null) {
            $workspacePath = (string)array_search($agentDrivePath->reference, $this->referencesByPath, true);
        }
        $this->readPaths[] = $workspacePath;
        $this->readMaxBytes[] = $maxBytes;
        if (in_array($workspacePath, $this->forbiddenPaths, true)) {
            throw new ForbiddenException($workspacePath . ' waits for its virus scan: it cannot be read yet.');
        }
        if (!isset($this->bytesByPath[$workspacePath])) {
            throw new NotFoundException($workspacePath . ' does not exist.');
        }
        if (strlen($this->bytesByPath[$workspacePath]) > $maxBytes) {
            throw new BadRequestException($workspacePath . ' is larger than the agent may read.');
        }
        $agentDriveFileContent = AgentDriveFileContent::fromBytes($this->bytesByPath[$workspacePath], basename($workspacePath));
        $agentDriveFileContent->reference = $this->referencesByPath[$workspacePath] ?? null;
        $agentDriveFileContent->workspacePath = $workspacePath;
        return $agentDriveFileContent;
    }
}
