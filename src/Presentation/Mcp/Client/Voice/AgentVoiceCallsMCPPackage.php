<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Voice;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPRequiresAccountRights;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolParallelSafe;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolSummary;
use DDD\Domain\AI\Entities\Voice\AgentVoiceAccountRights;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCalls;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallStart;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallAdmissionService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallEndsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStartsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Client\Voice\Dtos\AgentVoiceCallEndMcpResponseDto;
use DDD\Presentation\Mcp\Client\Voice\Dtos\AgentVoiceCallEndRequestDto;
use DDD\Presentation\Mcp\Client\Voice\Dtos\AgentVoiceCallsGetRequestDto;
use DDD\Presentation\Mcp\Client\Voice\Dtos\AgentVoiceCallsMcpResponseDto;
use DDD\Presentation\Mcp\Client\Voice\Dtos\AgentVoiceCallStartMcpResponseDto;
use DDD\Presentation\Mcp\Client\Voice\Dtos\AgentVoiceCallStartRequestDto;
use Throwable;

/**
 * The agent's voice calls with the owner, the tools of the skill `Agent.Voice` (package id `agent.voice`): CALL the
 * owner (`startVoiceCall`, thin over {@see AgentVoiceCallStartsService}, which rings the owner's open clients), SEE the
 * calls, the history and the ones ringing or running now (`getVoiceCalls`), END a call, above all the one the agent is
 * on, after its goodbye (`endVoiceCall`, thin over {@see AgentVoiceCallEndsService}).
 *
 * WHO MAY: the tools require the account right {@see AgentVoiceAccountRights::USE_VOICE}; the skill names the same
 * right. An application maps the right to its roles (`AI.accountRights`) or sets other rights per tool name
 * (`AI.toolOverlays`). WHILE THE VOICE CHANNEL CANNOT RUN ({@see AgentVoiceCallAdmissionService::canVoiceRunHere()}:
 * switched off, or switched on without the session worker's WebSocket library) the package is not available: no tool is
 * offered, and the skill bound to it alone is not offered either.
 *
 * WHOSE CALLS: restrictive by default. A call is started for the signed-in account; the calls are read and ended for
 * the account the conversation belongs to, else the signed-in account ({@see self::findOwnerAccountId()}), as a filter
 * of every read. `endVoiceCall` with a call id therefore ends only a call of that account. Reading or ending the calls
 * of another account is not part of these tools.
 *
 * Override points of an application's subclass: {@see self::findOwnerAccountId()} and
 * {@see self::requireSignedInAccount()} (whose calls), {@see self::findCarrierConversation()} (the conversation a
 * CURRENT call continues), {@see self::buildStartedCallResponse()} and {@see self::buildStartRefusal()} (the answer
 * and the refusal sentence of a call start, for an application whose call start knows further media or reasons).
 */
#[MCPRequiresAccountRights(AgentVoiceAccountRights::USE_VOICE)]
class AgentVoiceCallsMCPPackage extends MCPPackage
{
    public static ?string $name = 'agent.voice';

    public static ?string $description = 'Voice calls with the owner: call the owner now (startVoiceCall), the calls ringing, running or past (getVoiceCalls), ending a call (endVoiceCall).';

    /**
     * Available while the voice channel can run in the installation
     * ({@see AgentVoiceCallAdmissionService::canVoiceRunHere()}). An answer that cannot be read is a no.
     */
    public static function isAvailable(): bool
    {
        try {
            /** @var AgentVoiceCallAdmissionService $agentVoiceCallAdmissionService */
            $agentVoiceCallAdmissionService = DDDService::instance()->getService(AgentVoiceCallAdmissionService::class);
            return $agentVoiceCallAdmissionService->canVoiceRunHere();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * CALL the owner NOW and work the task on the call: the app rings on every open client of the owner at once, for
     * the seconds the answer names. `task` is the call's complete mission written like a user request; leave it out
     * when the owner gave no topic: the call then opens by asking what the owner needs. `conversation` CURRENT
     * (default) continues this chat (what is said on the call lands right here); NEW gives the call its own
     * conversation, only for a goal different from this chat's topic. `purposeName` (NEW only) only when the owner
     * asked for exactly that purpose. A call at a later moment or after a delay is a schedule whose task says to call.
     * Answer the owner right away that the app is ringing. If nobody answers, the call ends unanswered and a call-end
     * note appears here.
     *
     * @example {"task": "Go through the open points of this week with the owner and ask which one comes first."}
     * @example {}
     * @example {"conversation": "NEW", "task": "Walk the owner through the first steps and answer their questions."}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::WRITE, identicalRepeatDoesNewWork: true)]
    #[MCPToolSummary('Call the owner now: the app rings on every open client of the owner; optional task, CURRENT or NEW conversation. Immediate calls only; timed calls go through a schedule.')]
    public function startVoiceCall(
        AgentVoiceCallStartRequestDto $requestDto,
        AgentVoiceCallStartsService $agentVoiceCallStartsService,
        AgentToolCallProvenance $callProvenance
    ): AgentVoiceCallStartMcpResponseDto {
        $agentVoiceCallStart = $agentVoiceCallStartsService->startVoiceCall(
            $this->requireSignedInAccount(),
            $callProvenance->agentSpace,
            $this->findCarrierConversation($callProvenance),
            $requestDto->asksForNewConversation(),
            $requestDto->getCallTaskOrNull(),
            $requestDto->getPurposeNameOrNull()
        );
        if ($agentVoiceCallStart->isRefused()) {
            throw new BadRequestException($this->buildStartRefusal($agentVoiceCallStart));
        }
        return $this->buildStartedCallResponse($agentVoiceCallStart);
    }

    /**
     * The account's calls with you, newest first: per call its id, medium, direction, state (RINGING / IN_PROGRESS
     * with the seconds so far / ENDED), answered time and duration, who started it (the owner, or you with
     * startVoiceCall), the end reason and the linked conversation; the call carried by THIS conversation is marked.
     * `onlyRunning: true` lists only the calls ringing or running right now ("which calls are running?", before
     * endVoiceCall when several run); without it the history (query options: `filters` on `startedAt`, `top`, `skip`).
     * What was SAID on a call lives in its linked conversation.
     *
     * @example {"onlyRunning": true}
     * @example {}
     * @example {"filters": "startedAt bw ['2026-01-01 00:00:00','2026-01-31 23:59:59']"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolParallelSafe('reads the account\'s calls')]
    #[MCPToolSummary('The account\'s calls with you (state RINGING / IN_PROGRESS / ENDED, direction, who started it, duration, linked conversation; the call of this conversation is marked). onlyRunning=true = only the calls ringing or running now.')]
    public function getVoiceCalls(
        AgentVoiceCallsGetRequestDto $requestDto,
        AgentVoiceCallsService $agentVoiceCallsService,
        AgentToolCallProvenance $callProvenance
    ): AgentVoiceCallsMcpResponseDto {
        $ownerAccountId = $this->findOwnerAccountId($callProvenance);
        $responseDto = new AgentVoiceCallsMcpResponseDto();
        $responseDto->onlyRunning = $requestDto->onlyRunning;
        $responseDto->currentConversationId = $callProvenance->rootConversationId ?? $callProvenance->conversationId;
        if ($requestDto->onlyRunning) {
            $responseDto->voiceCalls = $agentVoiceCallsService->findOpenCallsOfAccountId($ownerAccountId)->getOpenCallsAt(time());
            return $responseDto;
        }
        // The query options are kept per class and outlive this call inside the agent's process: the change is scoped
        // to this one read.
        AgentVoiceCalls::setDefaultQueryOptionsSnapshot();
        $throwErrorsBefore = $agentVoiceCallsService->enableThrowErrors();
        try {
            AgentVoiceCalls::applyQueryOptionsFromRequestDto($requestDto);
            $responseDto->voiceCalls = $agentVoiceCallsService->findCallsOfAccountId($ownerAccountId);
        } finally {
            $agentVoiceCallsService->restoreThrowErrors($throwErrorsBefore);
            AgentVoiceCalls::restoreDefaultQueryOptionsSnapshot();
        }
        return $responseDto;
    }

    /**
     * ENDS a call, only when the owner asks for it ("hang up", "end the call", "bye, that's all"). Without `callId`
     * it ends the call of THIS conversation: on the call you are on, the line drops right after your answer is spoken,
     * so answer with ONE short goodbye; a call of this conversation that still rings is withdrawn at once. With
     * `callId` (from getVoiceCalls) it ends that call of the account at once: a ringing one stops ringing, a running
     * one is hung up. Refused when no such call rings or runs; the refusal names the calls that do.
     *
     * @example {}
     * @example {"callId": 8815}
     */
    #[MCPTool]
    // No `callId` means the call of this conversation that runs NOW: the same empty arguments end a DIFFERENT call
    // after the next call started, so an identical repeat must not be answered as "already done".
    #[MCPToolEffect(MCPToolEffect::WRITE, identicalRepeatDoesNewWork: true)]
    #[MCPToolSummary('End a call on the owner\'s request: no callId = the call of this conversation (the one you are on hangs up right after your one-sentence goodbye); callId = that running or ringing call at once.')]
    public function endVoiceCall(
        AgentVoiceCallEndRequestDto $requestDto,
        AgentVoiceCallEndsService $agentVoiceCallEndsService,
        AgentToolCallProvenance $callProvenance
    ): AgentVoiceCallEndMcpResponseDto {
        $agentVoiceCallEnd = $agentVoiceCallEndsService->endVoiceCall(
            $this->findOwnerAccountId($callProvenance),
            $callProvenance->rootConversationId ?? $callProvenance->conversationId,
            $requestDto->callId
        );
        if ($agentVoiceCallEnd->isRefused()) {
            throw new BadRequestException(AgentVoiceCallEndMcpResponseDto::buildRefusal($agentVoiceCallEnd));
        }
        return AgentVoiceCallEndMcpResponseDto::forEndedCall($agentVoiceCallEnd);
    }

    /**
     * The account the voice tools read and end calls for
     * ({@see AgentToolCallProvenance::getConversationOwnerAccountIdOrAuthAccountId()}: the account the conversation
     * belongs to, else the signed-in account). Every read and every end is filtered on it in the query, never left to
     * the read rights.
     *
     * @throws BadRequestException when neither names an account
     */
    protected function findOwnerAccountId(AgentToolCallProvenance $callProvenance): int
    {
        $signedInAccount = AuthService::instance()->getAccount();
        $ownerAccountId = $callProvenance->getConversationOwnerAccountIdOrAuthAccountId(
            isset($signedInAccount->id) ? (int)$signedInAccount->id : null
        );
        if ($ownerAccountId === null) {
            throw new BadRequestException('This call has no account: the voice calls are read for the owner of the conversation.');
        }
        return $ownerAccountId;
    }

    /**
     * The account a call is started for: the signed-in account, the one the agent works for in this turn.
     *
     * @throws ForbiddenException without a signed-in account
     */
    protected function requireSignedInAccount(): Account
    {
        $signedInAccount = AuthService::instance()->getAccount();
        if (!$signedInAccount instanceof Account || !isset($signedInAccount->id)) {
            throw new ForbiddenException('No authenticated account');
        }
        return $signedInAccount;
    }

    /**
     * The conversation a CURRENT call continues: the ROOT of the conversation the tool call runs in (the call of a
     * delegated sub-task still lands in the owner's chat), the live one when this call runs in the root, else read by
     * id.
     */
    protected function findCarrierConversation(AgentToolCallProvenance $callProvenance): ?AIConversation
    {
        $carrierConversationId = $callProvenance->rootConversationId ?? $callProvenance->conversationId;
        if ($carrierConversationId === null) {
            return null;
        }
        if ($callProvenance->conversation !== null && (int)($callProvenance->conversation->id ?? 0) === $carrierConversationId) {
            return $callProvenance->conversation;
        }
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        $carrierConversation = $aIConversationsService->find($carrierConversationId);
        return $carrierConversation instanceof AIConversation ? $carrierConversation : null;
    }

    /** The answer of a call that started. An application whose call start knows a further medium answers its own DTO subclass here. */
    protected function buildStartedCallResponse(AgentVoiceCallStart $agentVoiceCallStart): AgentVoiceCallStartMcpResponseDto
    {
        return AgentVoiceCallStartMcpResponseDto::forStartedCall($agentVoiceCallStart);
    }

    /** The sentence a refused call start is answered with. An application with refusal reasons of its own words them through its DTO subclass here. */
    protected function buildStartRefusal(AgentVoiceCallStart $agentVoiceCallStart): string
    {
        return AgentVoiceCallStartMcpResponseDto::buildRefusal($agentVoiceCallStart);
    }
}
