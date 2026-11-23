<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Sandboxes;

use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxCommands;
use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxDriveStatus;
use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxFileEntries;
use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxOffloadResult;
use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxProcesses;
use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxSessionEvents;
use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxWorkspace;
use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxWorkspaces;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The host's side of the sandbox administration: the host owns the runner and the workspaces (sessions, files,
 * commands, the drive working copy) and answers these calls; the package adds the screen's endpoints
 * ({@see \DDD\Presentation\Api\Agents\Sandboxes\Controller\AgentSandboxesControllerTrait}), the conversations of a
 * space, the guard against a running turn and the audit ({@see AgentSandboxesAdminService}).
 *
 * Exactly one service of the host implements it (autoconfigured by this tag); without one the screen reports "not set
 * up". Every call is made for an admin the host's controller let through; the host checks nothing more. A workspace
 * the host does not know answers {@see NotFoundException}. Paths are relative to the workspace root, `/`-separated,
 * and were checked by the package ({@see \DDD\Infrastructure\Untrusted\ContainedPath::isSafeRelativePath()}); the host
 * still resolves them inside the workspace only. No answer carries the session's environment or a secret: command
 * lines and output tails come with the session's secret values masked.
 */
#[AutoconfigureTag(AgentSandboxAdministration::TAG)]
interface AgentSandboxAdministration
{
    public const string TAG = 'ai.agent_sandbox_administration';

    /** Every workspace the host knows: with a session, and offloaded ones from the store */
    public function findWorkspaces(): AgentSandboxWorkspaces;

    /** One workspace; null when the host does not know it */
    public function findWorkspace(string $workspaceKey): ?AgentSandboxWorkspace;

    /**
     * The entries of one directory of the workspace ('' = the root), read-only.
     *
     * @throws NotFoundException
     */
    public function findFileEntries(string $workspaceKey, string $directoryPath): AgentSandboxFileEntries;

    /**
     * The bytes of one file, at most `$maxBytes + 1` of them (one more tells the package the file is larger); null when
     * the path names no file.
     *
     * @throws NotFoundException
     */
    public function readFileBytes(string $workspaceKey, string $relativePath, int $maxBytes): ?string;

    /** What the workspace holds that the drive does not have yet */
    public function findDriveStatus(string $workspaceKey): AgentSandboxDriveStatus;

    /** The processes and background jobs of the session; empty without a session */
    public function findProcesses(string $workspaceKey): AgentSandboxProcesses;

    /** The last commands of the session, newest first */
    public function findRecentCommands(string $workspaceKey, int $limit): AgentSandboxCommands;

    /** The newest entries of the session's log, newest first */
    public function findSessionEvents(string $workspaceKey, int $limit): AgentSandboxSessionEvents;

    /**
     * Starts (warms) a session for the workspace; nothing when one runs.
     *
     * @throws NotFoundException
     */
    public function startSession(string $workspaceKey): void;

    /**
     * Commits the pending drive changes, then releases the session; the workspace persists.
     *
     * @throws NotFoundException
     */
    public function offloadSession(string $workspaceKey): AgentSandboxOffloadResult;

    /**
     * Kills one background job of the session.
     *
     * @throws NotFoundException when the session or the job does not exist
     */
    public function killJob(string $workspaceKey, string $jobId): void;

    /**
     * Ends the session without committing (pending changes stay in the workspace store where the host keeps them).
     *
     * @throws NotFoundException
     */
    public function endSession(string $workspaceKey): void;

    /**
     * Wipes the workspace's local state; the drive is kept.
     *
     * @throws NotFoundException
     * @throws BadRequestException when the host refuses (e.g. it cannot reset a workspace with a session)
     */
    public function resetWorkspace(string $workspaceKey): void;
}
