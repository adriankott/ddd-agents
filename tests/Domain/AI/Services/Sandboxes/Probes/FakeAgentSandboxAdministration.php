<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Sandboxes\Probes;

use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxCommands;
use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxDriveStatus;
use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxFileEntries;
use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxOffloadResult;
use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxProcesses;
use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxSessionEvents;
use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxWorkspace;
use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxWorkspaces;
use DDD\Domain\AI\Services\Sandboxes\AgentSandboxAdministration;
use DDD\Infrastructure\Exceptions\NotFoundException;

/** A host administration in memory: one workspace, files by path, the calls it received. */
class FakeAgentSandboxAdministration implements AgentSandboxAdministration
{
    public AgentSandboxWorkspace $agentSandboxWorkspace;

    /** @var string[] relative path => bytes */
    public array $fileBytesByPath = [];

    /** @var string[] the actions it ran, e.g. `END:ws-1`, `KILL_JOB:ws-1:job-7` */
    public array $receivedCalls = [];

    /** @var string|null The last path the package asked for */
    public ?string $lastRequestedPath = null;

    public function __construct(string $workspaceKey = 'ws-1', ?int $agentSpaceId = 31, ?string $displayName = 'Space 31')
    {
        $this->agentSandboxWorkspace = new AgentSandboxWorkspace();
        $this->agentSandboxWorkspace->workspaceKey = $workspaceKey;
        $this->agentSandboxWorkspace->agentSpaceId = $agentSpaceId;
        $this->agentSandboxWorkspace->displayName = $displayName;
        $this->agentSandboxWorkspace->state = AgentSandboxWorkspace::STATE_RUNNING;
    }

    public function findWorkspaces(): AgentSandboxWorkspaces
    {
        $agentSandboxWorkspaces = new AgentSandboxWorkspaces();
        $agentSandboxWorkspace = $this->findWorkspace($this->agentSandboxWorkspace->workspaceKey);
        $agentSandboxWorkspaces->add($agentSandboxWorkspace);
        return $agentSandboxWorkspaces;
    }

    public function findWorkspace(string $workspaceKey): ?AgentSandboxWorkspace
    {
        return $workspaceKey === $this->agentSandboxWorkspace->workspaceKey ? clone $this->agentSandboxWorkspace : null;
    }

    public function findFileEntries(string $workspaceKey, string $directoryPath): AgentSandboxFileEntries
    {
        $this->lastRequestedPath = $directoryPath;
        return new AgentSandboxFileEntries();
    }

    public function readFileBytes(string $workspaceKey, string $relativePath, int $maxBytes): ?string
    {
        $this->lastRequestedPath = $relativePath;
        $fileBytes = $this->fileBytesByPath[$relativePath] ?? null;
        return $fileBytes !== null ? substr($fileBytes, 0, $maxBytes + 1) : null;
    }

    public function findDriveStatus(string $workspaceKey): AgentSandboxDriveStatus
    {
        return new AgentSandboxDriveStatus();
    }

    public function findProcesses(string $workspaceKey): AgentSandboxProcesses
    {
        return new AgentSandboxProcesses();
    }

    public function findRecentCommands(string $workspaceKey, int $limit): AgentSandboxCommands
    {
        return new AgentSandboxCommands();
    }

    public function findSessionEvents(string $workspaceKey, int $limit): AgentSandboxSessionEvents
    {
        return new AgentSandboxSessionEvents();
    }

    public function startSession(string $workspaceKey): void
    {
        $this->receivedCalls[] = 'START:' . $workspaceKey;
    }

    public function offloadSession(string $workspaceKey): AgentSandboxOffloadResult
    {
        $this->receivedCalls[] = 'OFFLOAD:' . $workspaceKey;
        $agentSandboxOffloadResult = new AgentSandboxOffloadResult();
        $agentSandboxOffloadResult->committedChangeCount = 3;
        $agentSandboxOffloadResult->wasSessionReleased = true;
        return $agentSandboxOffloadResult;
    }

    public function killJob(string $workspaceKey, string $jobId): void
    {
        if ($jobId === 'missing') {
            throw new NotFoundException('No such job');
        }
        $this->receivedCalls[] = 'KILL_JOB:' . $workspaceKey . ':' . $jobId;
    }

    public function endSession(string $workspaceKey): void
    {
        $this->receivedCalls[] = 'END:' . $workspaceKey;
    }

    public function resetWorkspace(string $workspaceKey): void
    {
        $this->receivedCalls[] = 'RESET:' . $workspaceKey;
    }
}
