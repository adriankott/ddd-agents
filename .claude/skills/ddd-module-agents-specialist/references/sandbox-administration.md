# Sandbox administration (agent workspaces)

**Reader:** host developer

Contents: what the host implements · what the package adds · mounting the endpoints.

The admin screen for the agent workspaces of a host: one workspace per agent space (or per account at account level),
its runner session, resource use, files, unsaved drive changes, processes, commands, session log, the space's
conversations, and the actions start, offload, kill a job, end, reset. The runner and the workspace logic are the
host's; the package ships the contract, the endpoints, the guard and the audit.

## What the host implements

One service implementing `DDD\Domain\AI\Services\Sandboxes\AgentSandboxAdministration` (tag
`ai.agent_sandbox_administration`, autoconfigured; exactly one — a second one is ignored with a warning). Without one,
`GET /list` answers `isConfigured: false` and every other route 404.

| Method | Answers |
|---|---|
| `findWorkspaces(): AgentSandboxWorkspaces` | every workspace the host knows: with a session, and offloaded ones from its store |
| `findWorkspace(string $workspaceKey): ?AgentSandboxWorkspace` | one workspace; null = unknown (404) |
| `findFileEntries(string $workspaceKey, string $directoryPath): AgentSandboxFileEntries` | one directory, `''` = root |
| `readFileBytes(string $workspaceKey, string $relativePath, int $maxBytes): ?string` | at most `$maxBytes + 1` bytes; null = no file |
| `findDriveStatus(string $workspaceKey): AgentSandboxDriveStatus` | unsaved changes against the drive |
| `findProcesses(string $workspaceKey): AgentSandboxProcesses` | processes and background jobs of the session |
| `findRecentCommands(string $workspaceKey, int $limit): AgentSandboxCommands` | the last commands, newest first; fill `status` (`RUNNING`/`EXITED`/`KILLED`/`TIMED_OUT`: exit code → EXITED, killed job → KILLED, runner timeout → TIMED_OUT, else RUNNING; null = unknown) |
| `findSessionEvents(string $workspaceKey, int $limit): AgentSandboxSessionEvents` | the session log, newest first |
| `startSession(string $workspaceKey): void` | warm a session |
| `offloadSession(string $workspaceKey): AgentSandboxOffloadResult` | commit the drive changes, then release the session |
| `killJob(string $workspaceKey, string $jobId): void` | kill one background job |
| `endSession(string $workspaceKey): void` | end the session without committing |
| `resetWorkspace(string $workspaceKey): void` | wipe the local state, keep the drive |

- Paths reach the host relative, `/`-separated and checked (`ContainedPath::isSafeRelativePath()`); the host still
  resolves them inside the workspace only.
- No answer carries the session's environment. Command lines, argv and output tails come with the session's secret
  values masked by the host (or its runner).
- `AgentSandboxWorkspace`: `workspaceKey`, `agentSpaceId`, `displayName` (the name the drive shows), `state`
  (`RUNNING` / `IDLE` / `OFFLOADED`), `lastActivityAt`, `diskUsedBytes` / `diskQuotaBytes`, `memoryBytes` /
  `memoryPeakBytes` / `memoryLimitBytes`, `mountMode` (`FUSE` / `PLAIN`) + `mountModeReason`, `runnerPod`,
  `ownerDisplayName`, `runningJobCount`. The package fills `conversationCount` and `hasLiveTurn`.

## What the package adds (`AgentSandboxesAdminService`)

- **Conversations:** root conversations per agent space (`AIConversationsService::countRootConversationsPerAgentSpaceId()`,
  `findNewestRootConversationsOfAgentSpaceIdForSandbox()`), in the process's environment.
- **Guard:** an action on a workspace whose agent space has a conversation in a live turn (`RUNNING`,
  `AWAITING_DELEGATES`) answers 409 unless the request sends `force: true` with a `reason`.
- **Reset:** needs `confirmation` = the workspace's `displayName` (its key when it has none).
- **Audit:** every action, refusals and failures included, is a row of `AgentSandboxActionLogEntry` (table
  `AgentSandboxActionLogEntries`: workspaceKey, agentSpaceId, action, jobId, actorAccountId, forced, reason, outcome
  `SUCCEEDED` / `REFUSED` / `FAILED`, outcomeDetail, created). Plain ids, no foreign keys. Readable only by a reader of
  every agent row. Without the table the action still runs and the entry goes to the error log.
- **Files:** served under the untrusted-content rules: JPEG / PNG / GIF / WebP (magic bytes) inline when asked, PDF
  inline when asked, UTF-8 text as `text/plain; charset=utf-8` attachment (an inline preview is cut at 256 KB,
  `X-Content-Truncated: true`), anything else `application/octet-stream` attachment; 25 MB at most.

## Mounting the endpoints

```php
#[Route('/maintenance/sandboxes')]
#[Tag(group: 'Maintenance', name: 'AgentSandboxes', description: 'Agent workspaces and their sessions')]
class AdminAgentSandboxesController extends AdminController
{
    use AgentSandboxesControllerTrait;

    protected function throwForbiddenUnlessSandboxesAccessAllowed(): void
    {
        // operators only, e.g. the superadmin role; throw ForbiddenException otherwise
    }
}
```

| Route | Request | Answer |
|---|---|---|
| `GET /list` | | `isConfigured`, `workspaces` |
| `GET /{workspaceKey}` | | `workspace` |
| `GET /{workspaceKey}/files` | `path` (query, `''` = root) | `directoryPath`, `entries` |
| `GET /{workspaceKey}/files/content` | `path`, `disposition` = `inline` / `attachment` | the bytes (see Files) |
| `GET /{workspaceKey}/driveStatus` | | `driveStatus` |
| `GET /{workspaceKey}/processes` | | `processes` |
| `GET /{workspaceKey}/commands` | `limit` (default 50, ≤ 200) | `commands` |
| `GET /{workspaceKey}/events` | `limit` | `sessionEvents` |
| `GET /{workspaceKey}/conversations` | `limit` | `conversations` |
| `GET /{workspaceKey}/actions` | `limit` | `actionLogEntries` |
| `POST /{workspaceKey}/start`, `/offload`, `/end`, `/reset`, `/jobs/{jobId}/kill` | body `force`, `reason`, `confirmation` | `actionResult` (`action`, `workspaceKey`, `outcome`, `offloadResult`, `actionLogEntryId`) |

Refusals: 400 (force without reason, a reset with the wrong confirmation, a kill without a job, a path leaving the
workspace, a file over 25 MB), 409 (a live turn, not forced), 404 (no administration, unknown workspace or file).
A screen shows file names, command lines and outputs as text only.
