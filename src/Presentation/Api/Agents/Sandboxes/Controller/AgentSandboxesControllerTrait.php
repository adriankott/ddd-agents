<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Sandboxes\Controller;

use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxActionLogEntry;
use DDD\Domain\AI\Services\Sandboxes\AgentSandboxesAdminService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ConflictException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Presentation\Api\Agents\Sandboxes\Dtos\AgentSandboxActionLogEntriesGetResponseDto;
use DDD\Presentation\Api\Agents\Sandboxes\Dtos\AgentSandboxActionPostRequestDto;
use DDD\Presentation\Api\Agents\Sandboxes\Dtos\AgentSandboxActionPostResponseDto;
use DDD\Presentation\Api\Agents\Sandboxes\Dtos\AgentSandboxCommandsGetResponseDto;
use DDD\Presentation\Api\Agents\Sandboxes\Dtos\AgentSandboxConversationsGetResponseDto;
use DDD\Presentation\Api\Agents\Sandboxes\Dtos\AgentSandboxDriveStatusGetResponseDto;
use DDD\Presentation\Api\Agents\Sandboxes\Dtos\AgentSandboxFileContentGetRequestDto;
use DDD\Presentation\Api\Agents\Sandboxes\Dtos\AgentSandboxFilesGetRequestDto;
use DDD\Presentation\Api\Agents\Sandboxes\Dtos\AgentSandboxFilesGetResponseDto;
use DDD\Presentation\Api\Agents\Sandboxes\Dtos\AgentSandboxJobKillPostRequestDto;
use DDD\Presentation\Api\Agents\Sandboxes\Dtos\AgentSandboxListGetRequestDto;
use DDD\Presentation\Api\Agents\Sandboxes\Dtos\AgentSandboxProcessesGetResponseDto;
use DDD\Presentation\Api\Agents\Sandboxes\Dtos\AgentSandboxSessionEventsGetResponseDto;
use DDD\Presentation\Api\Agents\Sandboxes\Dtos\AgentSandboxWorkspaceGetRequestDto;
use DDD\Presentation\Api\Agents\Sandboxes\Dtos\AgentSandboxWorkspaceGetResponseDto;
use DDD\Presentation\Api\Agents\Sandboxes\Dtos\AgentSandboxWorkspacesGetResponseDto;
use DDD\Presentation\Base\Dtos\FileResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Summary;
use DDD\Presentation\Base\Router\Routes\Get;
use DDD\Presentation\Base\Router\Routes\Post;

/**
 * The sandbox endpoints of an admin API: the agent workspaces (one per agent space) with session state and resource
 * use, a read-only file tree with safe previews, unsaved drive changes, processes, commands, session log, the space's
 * conversations, the audit, and the actions start, offload, kill a job, end, reset. The host implements
 * {@see \DDD\Domain\AI\Services\Sandboxes\AgentSandboxAdministration}; an application mounts the endpoints with one
 * controller of its own, which sets the path prefix, the tag and the access (operators only):
 *
 *     #[Route('/maintenance/sandboxes')]
 *     #[Tag(group: 'Maintenance', name: 'AgentSandboxes', description: 'Agent workspaces and their sessions')]
 *     class AdminAgentSandboxesController extends AdminController
 *     {
 *         use AgentSandboxesControllerTrait;
 *
 *         protected function throwForbiddenUnlessSandboxesAccessAllowed(): void { … superadmin only … }
 *     }
 *
 * Every action is refused (409) while a conversation of the workspace's agent space holds a live turn, unless it is
 * forced with a reason; every action is audited, refusals included. File names, command lines and outputs are foreign
 * text: a screen shows them as text only.
 */
trait AgentSandboxesControllerTrait
{
    /**
     * Every workspace the host knows, with its state, resource use, mount mode, owner and conversation count.
     */
    #[Get('/list')]
    #[Summary('AgentSandboxes List')]
    public function listAgentSandboxes(AgentSandboxesAdminService $agentSandboxesAdminService): AgentSandboxWorkspacesGetResponseDto
    {
        $this->throwForbiddenUnlessSandboxesAccessAllowed();
        $responseDto = new AgentSandboxWorkspacesGetResponseDto();
        $responseDto->isConfigured = $agentSandboxesAdminService->isConfigured();
        $responseDto->workspaces = $agentSandboxesAdminService->findWorkspaces();
        return $responseDto;
    }

    /**
     * One workspace.
     *
     * @throws NotFoundException
     */
    #[Get('/{workspaceKey}', requirements: ['workspaceKey' => '[^/]+'])]
    #[Summary('AgentSandbox Details')]
    public function getAgentSandbox(
        AgentSandboxWorkspaceGetRequestDto $requestDto,
        AgentSandboxesAdminService $agentSandboxesAdminService
    ): AgentSandboxWorkspaceGetResponseDto {
        $this->throwForbiddenUnlessSandboxesAccessAllowed();
        $responseDto = new AgentSandboxWorkspaceGetResponseDto();
        $responseDto->workspace = $agentSandboxesAdminService->findWorkspace($requestDto->workspaceKey);
        return $responseDto;
    }

    /**
     * One directory of the workspace, read-only.
     *
     * @throws NotFoundException
     * @throws BadRequestException When the path leaves the workspace
     */
    #[Get('/{workspaceKey}/files', requirements: ['workspaceKey' => '[^/]+'])]
    #[Summary('AgentSandbox Files')]
    public function listAgentSandboxFiles(
        AgentSandboxFilesGetRequestDto $requestDto,
        AgentSandboxesAdminService $agentSandboxesAdminService
    ): AgentSandboxFilesGetResponseDto {
        $this->throwForbiddenUnlessSandboxesAccessAllowed();
        $responseDto = new AgentSandboxFilesGetResponseDto();
        $responseDto->directoryPath = trim(trim((string)$requestDto->path), '/');
        $responseDto->entries = $agentSandboxesAdminService->findFileEntries($requestDto->workspaceKey, (string)$requestDto->path);
        return $responseDto;
    }

    /**
     * One file of the workspace: raster images and PDF inline for a preview, UTF-8 text as text/plain (a preview cut
     * at 256 KB, header X-Content-Truncated), anything else an opaque attachment.
     *
     * @throws NotFoundException
     * @throws BadRequestException When the path leaves the workspace or the file is too large
     */
    #[Get('/{workspaceKey}/files/content', requirements: ['workspaceKey' => '[^/]+'])]
    #[Summary('AgentSandbox File Content')]
    public function getAgentSandboxFileContent(
        AgentSandboxFileContentGetRequestDto $requestDto,
        AgentSandboxesAdminService $agentSandboxesAdminService
    ): FileResponseDto {
        $this->throwForbiddenUnlessSandboxesAccessAllowed();
        return $agentSandboxesAdminService->buildFileContentResponse($requestDto->workspaceKey, $requestDto->path, $requestDto->isInlineWanted());
    }

    /**
     * What the workspace holds that the drive does not have yet.
     *
     * @throws NotFoundException
     */
    #[Get('/{workspaceKey}/driveStatus', requirements: ['workspaceKey' => '[^/]+'])]
    #[Summary('AgentSandbox Drive Status')]
    public function getAgentSandboxDriveStatus(
        AgentSandboxWorkspaceGetRequestDto $requestDto,
        AgentSandboxesAdminService $agentSandboxesAdminService
    ): AgentSandboxDriveStatusGetResponseDto {
        $this->throwForbiddenUnlessSandboxesAccessAllowed();
        $responseDto = new AgentSandboxDriveStatusGetResponseDto();
        $responseDto->driveStatus = $agentSandboxesAdminService->findDriveStatus($requestDto->workspaceKey);
        return $responseDto;
    }

    /**
     * The processes and background jobs of the session.
     *
     * @throws NotFoundException
     */
    #[Get('/{workspaceKey}/processes', requirements: ['workspaceKey' => '[^/]+'])]
    #[Summary('AgentSandbox Processes')]
    public function listAgentSandboxProcesses(
        AgentSandboxWorkspaceGetRequestDto $requestDto,
        AgentSandboxesAdminService $agentSandboxesAdminService
    ): AgentSandboxProcessesGetResponseDto {
        $this->throwForbiddenUnlessSandboxesAccessAllowed();
        $responseDto = new AgentSandboxProcessesGetResponseDto();
        $responseDto->processes = $agentSandboxesAdminService->findProcesses($requestDto->workspaceKey);
        return $responseDto;
    }

    /**
     * The last commands of the session with exit code, duration and output tails.
     *
     * @throws NotFoundException
     */
    #[Get('/{workspaceKey}/commands', requirements: ['workspaceKey' => '[^/]+'])]
    #[Summary('AgentSandbox Commands')]
    public function listAgentSandboxCommands(
        AgentSandboxListGetRequestDto $requestDto,
        AgentSandboxesAdminService $agentSandboxesAdminService
    ): AgentSandboxCommandsGetResponseDto {
        $this->throwForbiddenUnlessSandboxesAccessAllowed();
        $responseDto = new AgentSandboxCommandsGetResponseDto();
        $responseDto->commands = $agentSandboxesAdminService->findRecentCommands($requestDto->workspaceKey, $requestDto->getLimitOrDefault());
        return $responseDto;
    }

    /**
     * The newest entries of the session log (limits hit, refused system calls, timeouts).
     *
     * @throws NotFoundException
     */
    #[Get('/{workspaceKey}/events', requirements: ['workspaceKey' => '[^/]+'])]
    #[Summary('AgentSandbox Session Events')]
    public function listAgentSandboxSessionEvents(
        AgentSandboxListGetRequestDto $requestDto,
        AgentSandboxesAdminService $agentSandboxesAdminService
    ): AgentSandboxSessionEventsGetResponseDto {
        $this->throwForbiddenUnlessSandboxesAccessAllowed();
        $responseDto = new AgentSandboxSessionEventsGetResponseDto();
        $responseDto->sessionEvents = $agentSandboxesAdminService->findSessionEvents($requestDto->workspaceKey, $requestDto->getLimitOrDefault());
        return $responseDto;
    }

    /**
     * The newest root conversations of the workspace's agent space.
     *
     * @throws NotFoundException
     */
    #[Get('/{workspaceKey}/conversations', requirements: ['workspaceKey' => '[^/]+'])]
    #[Summary('AgentSandbox Conversations')]
    public function listAgentSandboxConversations(
        AgentSandboxListGetRequestDto $requestDto,
        AgentSandboxesAdminService $agentSandboxesAdminService
    ): AgentSandboxConversationsGetResponseDto {
        $this->throwForbiddenUnlessSandboxesAccessAllowed();
        $responseDto = new AgentSandboxConversationsGetResponseDto();
        $responseDto->conversations = $agentSandboxesAdminService->findConversations($requestDto->workspaceKey, $requestDto->getLimitOrDefault());
        return $responseDto;
    }

    /**
     * The audit of the actions on the workspace, refusals included.
     */
    #[Get('/{workspaceKey}/actions', requirements: ['workspaceKey' => '[^/]+'])]
    #[Summary('AgentSandbox Action Log')]
    public function listAgentSandboxActionLogEntries(
        AgentSandboxListGetRequestDto $requestDto,
        AgentSandboxesAdminService $agentSandboxesAdminService
    ): AgentSandboxActionLogEntriesGetResponseDto {
        $this->throwForbiddenUnlessSandboxesAccessAllowed();
        $responseDto = new AgentSandboxActionLogEntriesGetResponseDto();
        $responseDto->actionLogEntries = $agentSandboxesAdminService->findActionLogEntries($requestDto->workspaceKey, $requestDto->getLimitOrDefault());
        return $responseDto;
    }

    /**
     * Starts (warms) a session for the workspace.
     *
     * @throws NotFoundException
     * @throws BadRequestException
     * @throws ConflictException When a turn runs on the space and the action is not forced
     */
    #[Post('/{workspaceKey}/start', requirements: ['workspaceKey' => '[^/]+'])]
    #[Summary('AgentSandbox Start')]
    public function startAgentSandbox(
        AgentSandboxActionPostRequestDto $requestDto,
        AgentSandboxesAdminService $agentSandboxesAdminService
    ): AgentSandboxActionPostResponseDto {
        return $this->runAgentSandboxAction(AgentSandboxActionLogEntry::ACTION_START, $requestDto, null, $agentSandboxesAdminService);
    }

    /**
     * Commits the pending drive changes, then releases the session; the workspace persists.
     *
     * @throws NotFoundException
     * @throws BadRequestException
     * @throws ConflictException When a turn runs on the space and the action is not forced
     */
    #[Post('/{workspaceKey}/offload', requirements: ['workspaceKey' => '[^/]+'])]
    #[Summary('AgentSandbox Offload')]
    public function offloadAgentSandbox(
        AgentSandboxActionPostRequestDto $requestDto,
        AgentSandboxesAdminService $agentSandboxesAdminService
    ): AgentSandboxActionPostResponseDto {
        return $this->runAgentSandboxAction(AgentSandboxActionLogEntry::ACTION_OFFLOAD, $requestDto, null, $agentSandboxesAdminService);
    }

    /**
     * Kills one background job of the session.
     *
     * @throws NotFoundException
     * @throws BadRequestException
     * @throws ConflictException When a turn runs on the space and the action is not forced
     */
    #[Post('/{workspaceKey}/jobs/{jobId}/kill', requirements: ['workspaceKey' => '[^/]+', 'jobId' => '[^/]+'])]
    #[Summary('AgentSandbox Job Kill')]
    public function killAgentSandboxJob(
        AgentSandboxActionPostRequestDto $requestDto,
        AgentSandboxJobKillPostRequestDto $jobKillRequestDto,
        AgentSandboxesAdminService $agentSandboxesAdminService
    ): AgentSandboxActionPostResponseDto {
        return $this->runAgentSandboxAction(AgentSandboxActionLogEntry::ACTION_KILL_JOB, $requestDto, $jobKillRequestDto->jobId, $agentSandboxesAdminService);
    }

    /**
     * Ends the session without committing.
     *
     * @throws NotFoundException
     * @throws BadRequestException
     * @throws ConflictException When a turn runs on the space and the action is not forced
     */
    #[Post('/{workspaceKey}/end', requirements: ['workspaceKey' => '[^/]+'])]
    #[Summary('AgentSandbox End')]
    public function endAgentSandbox(
        AgentSandboxActionPostRequestDto $requestDto,
        AgentSandboxesAdminService $agentSandboxesAdminService
    ): AgentSandboxActionPostResponseDto {
        return $this->runAgentSandboxAction(AgentSandboxActionLogEntry::ACTION_END, $requestDto, null, $agentSandboxesAdminService);
    }

    /**
     * Wipes the workspace's local state and keeps the drive; needs the workspace's name as `confirmation`.
     *
     * @throws NotFoundException
     * @throws BadRequestException When the confirmation does not match
     * @throws ConflictException When a turn runs on the space and the action is not forced
     */
    #[Post('/{workspaceKey}/reset', requirements: ['workspaceKey' => '[^/]+'])]
    #[Summary('AgentSandbox Reset')]
    public function resetAgentSandbox(
        AgentSandboxActionPostRequestDto $requestDto,
        AgentSandboxesAdminService $agentSandboxesAdminService
    ): AgentSandboxActionPostResponseDto {
        return $this->runAgentSandboxAction(AgentSandboxActionLogEntry::ACTION_RESET, $requestDto, null, $agentSandboxesAdminService);
    }

    /**
     * @throws NotFoundException
     * @throws BadRequestException
     * @throws ConflictException
     */
    protected function runAgentSandboxAction(
        string $action,
        AgentSandboxActionPostRequestDto $requestDto,
        ?string $jobId,
        AgentSandboxesAdminService $agentSandboxesAdminService
    ): AgentSandboxActionPostResponseDto {
        $this->throwForbiddenUnlessSandboxesAccessAllowed();
        $responseDto = new AgentSandboxActionPostResponseDto();
        $responseDto->actionResult = $agentSandboxesAdminService->runAction(
            $requestDto->workspaceKey,
            $action,
            $jobId,
            $requestDto->force === true,
            $requestDto->reason,
            $requestDto->confirmation
        );
        return $responseDto;
    }

    /**
     * The access hook of every sandbox endpoint, called first. Empty by default: the application restricts the path
     * (`access_control`) and overrides this for an account predicate (operators only) by throwing a
     * {@see ForbiddenException}.
     *
     * @throws ForbiddenException
     */
    protected function throwForbiddenUnlessSandboxesAccessAllowed(): void
    {
    }
}
