<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Sandboxes;

use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxActionLogEntries;
use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxActionLogEntry;
use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxActionResult;
use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxCommands;
use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxConversations;
use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxDriveStatus;
use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxFileEntries;
use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxProcesses;
use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxSessionEvents;
use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxWorkspace;
use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxWorkspaces;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ConflictException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use DDD\Infrastructure\Untrusted\ContainedPath;
use DDD\Infrastructure\Untrusted\RasterImageDecoder;
use DDD\Infrastructure\Untrusted\UntrustedContentHeaders;
use DDD\Presentation\Base\Dtos\FileResponseDto;
use DDD\Presentation\Base\Dtos\UntrustedContentResponseDto;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\Attribute\Required;
use Throwable;

/**
 * The sandbox administration as the admin screen uses it: the host's {@see AgentSandboxAdministration} (the runner and
 * the workspaces) plus what the package owns — the conversations of a workspace's agent space, the guard against
 * acting while a turn runs there, the audit of every action ({@see AgentSandboxActionLogEntriesService}) and the
 * delivery of workspace files under the untrusted-content rules (raster images and PDF inline, text as text, every
 * other type an opaque attachment).
 */
class AgentSandboxesAdminService extends Service
{
    /** @var int The largest file the screen reads (preview and download) */
    public const int MAX_FILE_CONTENT_BYTES = 26214400;

    /** @var int The largest text a preview shows; a longer text is cut and marked */
    public const int MAX_TEXT_PREVIEW_BYTES = 262144;

    /** @var int Bytes looked at to tell text from binary */
    public const int TEXT_SNIFF_BYTES = 8192;

    /** @var int The most commands, events, conversations or audit entries one read answers */
    public const int MAX_LIST_LIMIT = 200;

    /** @var string Header that marks a cut text preview */
    public const string TRUNCATED_HEADER = 'X-Content-Truncated';

    /** @var iterable<AgentSandboxAdministration> */
    protected iterable $agentSandboxAdministrations = [];

    /** @param iterable<AgentSandboxAdministration> $agentSandboxAdministrations */
    #[Required]
    public function setAgentSandboxAdministrations(
        #[AutowireIterator(AgentSandboxAdministration::TAG)]
        iterable $agentSandboxAdministrations
    ): void {
        $this->agentSandboxAdministrations = $agentSandboxAdministrations;
    }

    /** True when the host installed its sandbox administration */
    public function isConfigured(): bool
    {
        return $this->findAdministration() !== null;
    }

    /**
     * The host's administration; the first one when a host registered several (a warning is logged).
     *
     * @throws NotFoundException when the host has none
     */
    public function getAdministration(): AgentSandboxAdministration
    {
        $agentSandboxAdministration = $this->findAdministration();
        if ($agentSandboxAdministration === null) {
            throw new NotFoundException('No sandbox administration is set up in this application (a service implementing AgentSandboxAdministration)');
        }
        return $agentSandboxAdministration;
    }

    /** Every workspace with its conversation count and live-turn flag; empty when nothing is set up */
    public function findWorkspaces(): AgentSandboxWorkspaces
    {
        $agentSandboxAdministration = $this->findAdministration();
        if ($agentSandboxAdministration === null) {
            return new AgentSandboxWorkspaces();
        }
        $agentSandboxWorkspaces = $agentSandboxAdministration->findWorkspaces();
        $this->addConversationFacts($agentSandboxWorkspaces);
        return $agentSandboxWorkspaces;
    }

    /**
     * One workspace with its conversation count and live-turn flag.
     *
     * @throws NotFoundException
     */
    public function findWorkspace(string $workspaceKey): AgentSandboxWorkspace
    {
        $agentSandboxWorkspace = $this->getAdministration()->findWorkspace($workspaceKey);
        if ($agentSandboxWorkspace === null) {
            throw new NotFoundException('No workspace with this key');
        }
        $agentSandboxWorkspaces = new AgentSandboxWorkspaces();
        $agentSandboxWorkspaces->add($agentSandboxWorkspace);
        $this->addConversationFacts($agentSandboxWorkspaces);
        return $agentSandboxWorkspace;
    }

    /**
     * One directory of a workspace ('' = the root).
     *
     * @throws NotFoundException
     * @throws BadRequestException when the path leaves the workspace
     */
    public function findFileEntries(string $workspaceKey, string $directoryPath): AgentSandboxFileEntries
    {
        $normalizedDirectoryPath = $this->normalizeRelativePath($directoryPath, true);
        return $this->getAdministration()->findFileEntries($workspaceKey, $normalizedDirectoryPath);
    }

    /**
     * The response that serves one workspace file under the untrusted-content rules: JPEG/PNG/GIF/WebP (by their magic
     * bytes) inline when wanted, PDF inline when wanted, UTF-8 text as `text/plain` (a preview cut at
     * {@see MAX_TEXT_PREVIEW_BYTES}), anything else an opaque attachment. The file name is the path's last segment.
     *
     * @throws NotFoundException
     * @throws BadRequestException when the path leaves the workspace or the file is too large
     */
    public function buildFileContentResponse(string $workspaceKey, string $relativePath, bool $inlineWanted): FileResponseDto
    {
        $normalizedRelativePath = $this->normalizeRelativePath($relativePath, false);
        $fileBytes = $this->getAdministration()->readFileBytes($workspaceKey, $normalizedRelativePath, static::MAX_FILE_CONTENT_BYTES);
        if ($fileBytes === null) {
            throw new NotFoundException('No file at this path');
        }
        $fileName = basename($normalizedRelativePath);
        $isLargerThanMax = strlen($fileBytes) > static::MAX_FILE_CONTENT_BYTES;
        $rasterMimeType = RasterImageDecoder::detectMimeType($fileBytes);
        if ($rasterMimeType !== null) {
            $this->throwIfLargerThanMax($isLargerThanMax);
            return new UntrustedContentResponseDto($fileBytes, $rasterMimeType, $fileName, $inlineWanted);
        }
        if (str_starts_with($fileBytes, '%PDF-')) {
            $this->throwIfLargerThanMax($isLargerThanMax);
            if ($inlineWanted) {
                return new FileResponseDto($fileBytes, 200, ['Content-Disposition' => UntrustedContentHeaders::buildContentDisposition(true, $fileName)], 'application/pdf');
            }
            return new UntrustedContentResponseDto($fileBytes, 'application/pdf', $fileName, false);
        }
        if ($this->isText($fileBytes)) {
            $isTruncated = false;
            if ($inlineWanted && strlen($fileBytes) > static::MAX_TEXT_PREVIEW_BYTES) {
                $fileBytes = mb_strcut($fileBytes, 0, static::MAX_TEXT_PREVIEW_BYTES, 'UTF-8');
                $isTruncated = true;
            } else {
                $this->throwIfLargerThanMax($isLargerThanMax);
            }
            $textResponse = new UntrustedContentResponseDto($fileBytes, 'text/plain', $fileName, false);
            $textResponse->headers->set('Content-Type', 'text/plain; charset=utf-8');
            $textResponse->headers->set(static::TRUNCATED_HEADER, $isTruncated ? 'true' : 'false');
            return $textResponse;
        }
        $this->throwIfLargerThanMax($isLargerThanMax);
        return new UntrustedContentResponseDto($fileBytes, null, $fileName, false);
    }

    /**
     * @throws NotFoundException
     */
    public function findDriveStatus(string $workspaceKey): AgentSandboxDriveStatus
    {
        return $this->getAdministration()->findDriveStatus($workspaceKey);
    }

    /**
     * @throws NotFoundException
     */
    public function findProcesses(string $workspaceKey): AgentSandboxProcesses
    {
        return $this->getAdministration()->findProcesses($workspaceKey);
    }

    /**
     * @throws NotFoundException
     */
    public function findRecentCommands(string $workspaceKey, int $limit): AgentSandboxCommands
    {
        return $this->getAdministration()->findRecentCommands($workspaceKey, $this->clampLimit($limit));
    }

    /**
     * @throws NotFoundException
     */
    public function findSessionEvents(string $workspaceKey, int $limit): AgentSandboxSessionEvents
    {
        return $this->getAdministration()->findSessionEvents($workspaceKey, $this->clampLimit($limit));
    }

    /**
     * The newest root conversations of the workspace's agent space; empty at account level.
     *
     * @throws NotFoundException
     */
    public function findConversations(string $workspaceKey, int $limit): AgentSandboxConversations
    {
        $agentSandboxWorkspace = $this->findWorkspace($workspaceKey);
        if ($agentSandboxWorkspace->agentSpaceId === null) {
            return new AgentSandboxConversations();
        }
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        return $aIConversationsService->findNewestRootConversationsOfAgentSpaceIdForSandbox($agentSandboxWorkspace->agentSpaceId, $this->clampLimit($limit));
    }

    /** The newest audit entries of a workspace */
    public function findActionLogEntries(string $workspaceKey, int $limit): AgentSandboxActionLogEntries
    {
        /** @var AgentSandboxActionLogEntriesService $agentSandboxActionLogEntriesService */
        $agentSandboxActionLogEntriesService = DDDService::instance()->getService(AgentSandboxActionLogEntriesService::class);
        return $agentSandboxActionLogEntriesService->findNewestActionLogEntriesOfWorkspace($workspaceKey, $this->clampLimit($limit));
    }

    /**
     * Runs one action on a workspace and audits it. Refused (and audited as REFUSED): a forced action without a reason,
     * a reset whose confirmation is not the workspace's name, any action while a conversation of the workspace's agent
     * space holds a live turn unless forced. A host call that throws is audited as FAILED and its error rethrown.
     *
     * @param string $workspaceKey
     * @param string $action one of {@see AgentSandboxActionLogEntry::ACTIONS}
     * @param string|null $jobId the job of a KILL_JOB
     * @param bool $force act although a turn runs (needs a reason)
     * @param string|null $reason why the action is forced
     * @param string|null $confirmation for a reset: the workspace's display name (its key when it has none)
     * @return AgentSandboxActionResult
     * @throws NotFoundException
     * @throws BadRequestException
     * @throws ConflictException when a turn runs on the space and the action is not forced
     */
    public function runAction(
        string $workspaceKey,
        string $action,
        ?string $jobId,
        bool $force,
        ?string $reason,
        ?string $confirmation
    ): AgentSandboxActionResult {
        if (!in_array($action, AgentSandboxActionLogEntry::ACTIONS, true)) {
            throw new BadRequestException('Unknown sandbox action: ' . $action);
        }
        $agentSandboxWorkspace = $this->findWorkspace($workspaceKey);
        $agentSandboxActionLogEntry = AgentSandboxActionLogEntry::newInstance();
        $agentSandboxActionLogEntry->workspaceKey = $agentSandboxWorkspace->workspaceKey;
        $agentSandboxActionLogEntry->agentSpaceId = $agentSandboxWorkspace->agentSpaceId;
        $agentSandboxActionLogEntry->action = $action;
        $agentSandboxActionLogEntry->jobId = $jobId;
        $agentSandboxActionLogEntry->actorAccountId = $this->findActorAccountId();
        $agentSandboxActionLogEntry->forced = $force;
        $agentSandboxActionLogEntry->reason = $reason;

        if ($force && trim((string)$reason) === '') {
            $this->refuseAction($agentSandboxActionLogEntry, new BadRequestException('A forced action needs a reason'));
        }
        if ($action === AgentSandboxActionLogEntry::ACTION_KILL_JOB && trim((string)$jobId) === '') {
            $this->refuseAction($agentSandboxActionLogEntry, new BadRequestException('Killing a job needs the job id'));
        }
        if ($action === AgentSandboxActionLogEntry::ACTION_RESET) {
            $expectedConfirmation = $this->buildResetConfirmation($agentSandboxWorkspace);
            if (trim((string)$confirmation) !== $expectedConfirmation) {
                $this->refuseAction($agentSandboxActionLogEntry, new BadRequestException('A reset needs the workspace\'s name as confirmation'));
            }
        }
        if ($agentSandboxWorkspace->hasLiveTurn && !$force) {
            $this->refuseAction(
                $agentSandboxActionLogEntry,
                new ConflictException('A conversation turn is running on this space: wait for it to end, or force the action with a reason')
            );
        }

        $agentSandboxActionResult = new AgentSandboxActionResult();
        $agentSandboxActionResult->action = $action;
        $agentSandboxActionResult->workspaceKey = $agentSandboxWorkspace->workspaceKey;
        try {
            $agentSandboxAdministration = $this->getAdministration();
            switch ($action) {
                case AgentSandboxActionLogEntry::ACTION_START:
                    $agentSandboxAdministration->startSession($agentSandboxWorkspace->workspaceKey);
                    break;
                case AgentSandboxActionLogEntry::ACTION_OFFLOAD:
                    $agentSandboxActionResult->offloadResult = $agentSandboxAdministration->offloadSession($agentSandboxWorkspace->workspaceKey);
                    $agentSandboxActionLogEntry->outcomeDetail = 'committed ' . $agentSandboxActionResult->offloadResult->committedChangeCount
                        . ' change(s), session ' . ($agentSandboxActionResult->offloadResult->wasSessionReleased ? 'released' : 'kept')
                        . ($agentSandboxActionResult->offloadResult->detail !== null ? ': ' . $agentSandboxActionResult->offloadResult->detail : '');
                    break;
                case AgentSandboxActionLogEntry::ACTION_KILL_JOB:
                    $agentSandboxAdministration->killJob($agentSandboxWorkspace->workspaceKey, trim((string)$jobId));
                    break;
                case AgentSandboxActionLogEntry::ACTION_END:
                    $agentSandboxAdministration->endSession($agentSandboxWorkspace->workspaceKey);
                    break;
                case AgentSandboxActionLogEntry::ACTION_RESET:
                    $agentSandboxAdministration->resetWorkspace($agentSandboxWorkspace->workspaceKey);
                    break;
            }
        } catch (Throwable $throwable) {
            $agentSandboxActionLogEntry->outcome = AgentSandboxActionLogEntry::OUTCOME_FAILED;
            $agentSandboxActionLogEntry->outcomeDetail = $throwable->getMessage();
            $this->recordActionLogEntry($agentSandboxActionLogEntry);
            throw $throwable;
        }
        $agentSandboxActionLogEntry->outcome = AgentSandboxActionLogEntry::OUTCOME_SUCCEEDED;
        $storedActionLogEntry = $this->recordActionLogEntry($agentSandboxActionLogEntry);
        $agentSandboxActionResult->outcome = AgentSandboxActionLogEntry::OUTCOME_SUCCEEDED;
        $agentSandboxActionResult->actionLogEntryId = $storedActionLogEntry !== null && isset($storedActionLogEntry->id) ? (int)$storedActionLogEntry->id : null;
        return $agentSandboxActionResult;
    }

    /** The text a reset must be confirmed with: the workspace's display name, its key when it has none */
    public function buildResetConfirmation(AgentSandboxWorkspace $agentSandboxWorkspace): string
    {
        $displayName = trim((string)$agentSandboxWorkspace->displayName);
        return $displayName !== '' ? $displayName : $agentSandboxWorkspace->workspaceKey;
    }

    protected function findAdministration(): ?AgentSandboxAdministration
    {
        $foundAdministration = null;
        foreach ($this->agentSandboxAdministrations as $agentSandboxAdministration) {
            if ($foundAdministration === null) {
                $foundAdministration = $agentSandboxAdministration;
                continue;
            }
            DDDService::instance()->getLogger()->warning('[AgentSandbox] more than one sandbox administration is registered; the first one answers', [
                'used' => $foundAdministration::class,
                'ignored' => $agentSandboxAdministration::class,
            ]);
        }
        return $foundAdministration;
    }

    /** Adds the package's facts (conversation count, live turn) to the workspaces of agent spaces */
    protected function addConversationFacts(AgentSandboxWorkspaces $agentSandboxWorkspaces): void
    {
        $agentSpaceIds = $agentSandboxWorkspaces->getAgentSpaceIds();
        if ($agentSpaceIds === []) {
            return;
        }
        $conversationCountsByAgentSpaceId = $this->countRootConversationsPerAgentSpaceId($agentSpaceIds);
        $agentSpaceIdsWithALiveTurn = $this->findAgentSpaceIdsWithALiveTurn($agentSpaceIds);
        foreach ($agentSandboxWorkspaces->getElements() as $agentSandboxWorkspace) {
            if ($agentSandboxWorkspace->agentSpaceId === null) {
                continue;
            }
            $agentSandboxWorkspace->conversationCount = $conversationCountsByAgentSpaceId[$agentSandboxWorkspace->agentSpaceId] ?? 0;
            $agentSandboxWorkspace->hasLiveTurn = in_array($agentSandboxWorkspace->agentSpaceId, $agentSpaceIdsWithALiveTurn, true);
        }
    }

    /**
     * @param int[] $agentSpaceIds
     * @return int[] agent space id => root conversation count
     */
    protected function countRootConversationsPerAgentSpaceId(array $agentSpaceIds): array
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        return $aIConversationsService->countRootConversationsPerAgentSpaceId($agentSpaceIds);
    }

    /**
     * @param int[] $agentSpaceIds
     * @return int[]
     */
    protected function findAgentSpaceIdsWithALiveTurn(array $agentSpaceIds): array
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        return $aIConversationsService->findAgentSpaceIdsWithALiveTurn($agentSpaceIds);
    }

    protected function recordActionLogEntry(AgentSandboxActionLogEntry $agentSandboxActionLogEntry): ?AgentSandboxActionLogEntry
    {
        /** @var AgentSandboxActionLogEntriesService $agentSandboxActionLogEntriesService */
        $agentSandboxActionLogEntriesService = DDDService::instance()->getService(AgentSandboxActionLogEntriesService::class);
        return $agentSandboxActionLogEntriesService->recordActionLogEntry($agentSandboxActionLogEntry);
    }

    /**
     * Audits a refused action and throws the refusal.
     *
     * @throws BadRequestException|ConflictException
     */
    protected function refuseAction(AgentSandboxActionLogEntry $agentSandboxActionLogEntry, BadRequestException|ConflictException $refusal): never
    {
        $agentSandboxActionLogEntry->outcome = AgentSandboxActionLogEntry::OUTCOME_REFUSED;
        $agentSandboxActionLogEntry->outcomeDetail = $refusal->getMessage();
        $this->recordActionLogEntry($agentSandboxActionLogEntry);
        throw $refusal;
    }

    protected function findActorAccountId(): ?int
    {
        $authAccount = AuthService::instance()->getAccount();
        return $authAccount !== null && isset($authAccount->id) ? (int)$authAccount->id : null;
    }

    /**
     * A relative workspace path as the host gets it: `/`-separated, no leading or trailing slash, no `.` or `..`, no
     * encoded separator, no control character.
     *
     * @throws BadRequestException
     */
    protected function normalizeRelativePath(string $relativePath, bool $allowRoot): string
    {
        $trimmedPath = trim(trim($relativePath), '/');
        if ($trimmedPath === '') {
            if ($allowRoot) {
                return '';
            }
            throw new BadRequestException('A file path is needed');
        }
        if (!ContainedPath::isSafeRelativePath($trimmedPath)) {
            throw new BadRequestException('The path leaves the workspace or is not valid');
        }
        return $trimmedPath;
    }

    protected function isText(string $fileBytes): bool
    {
        $sniffedBytes = mb_strcut($fileBytes, 0, static::TEXT_SNIFF_BYTES, 'UTF-8');
        return !str_contains($sniffedBytes, "\0") && mb_check_encoding($sniffedBytes, 'UTF-8');
    }

    /**
     * @throws BadRequestException
     */
    protected function throwIfLargerThanMax(bool $isLargerThanMax): void
    {
        if ($isLargerThanMax) {
            throw new BadRequestException('The file is larger than ' . intdiv(static::MAX_FILE_CONTENT_BYTES, 1048576) . ' MB and is not served here');
        }
    }

    protected function clampLimit(int $limit): int
    {
        return max(1, min($limit, static::MAX_LIST_LIMIT));
    }
}
