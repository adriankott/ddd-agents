<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Voice;

use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent;
use DDD\Domain\AI\Entities\Voice\AgentVoiceConfiguration;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallEnd;
use DDD\Domain\AI\Services\Conversations\TraceEvents\AIConversationTraceEventsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * The agent ENDS a call: the `endVoiceCall` tool, on the owner's request.
 *
 * 1. Which call: the one named by id when it is a call of the conversation OWNER's account (a query filter,
 *    {@see AgentVoiceCallsService::findCallOfAccountIdById()}, never the read rights alone: they are lifted inside many
 *    service paths of a turn), else the call ringing or running in THIS conversation (the chat's root conversation the
 *    agent answers in) among the owner's open calls; none is a refusal that names the owner's other open calls.
 * 2. The call the agent is ON (answered, running, in this very conversation, accompanied by a session worker) is not
 *    cut off: the worker is asked to hang up after the turn's answer, the goodbye, was spoken
 *    ({@see AgentVoiceCallsService::requestCallEndAfterGoodbye()}, read by the worker on every tick).
 * 3. Every other call ends at once through the one end path {@see AgentVoiceCallsService::endCallNow()}: a ringing
 *    call stops ringing, a running call's provider session is hung up; the call-end marker lands in the call's
 *    conversation.
 *
 * The end reason is {@see AgentVoiceCall::END_REASON_ENDED_BY_AGENT}. Each end leaves a trace event on the call.
 *
 * The override point of an application with further media or providers is {@see isCallAccompaniedBySessionWorker()}:
 * a call no worker of this package accompanies cannot be ended after a goodbye and is ended at once. The `find*`,
 * `request*`, `end*` and `record*` methods are the points a test replaces.
 */
class AgentVoiceCallEndsService extends Service
{
    /** The trace event of a call the agent ended at once. */
    public const string TRACE_EVENT_TYPE_ENDED_BY_AGENT = 'call.ended_by_agent';

    /** The trace event of a call the agent asked to end after its goodbye. */
    public const string TRACE_EVENT_TYPE_END_AFTER_GOODBYE_REQUESTED = 'call.end_after_goodbye_requested';

    /**
     * Ends `$requestedCallId`, or without one the call of `$currentConversationId` (the root conversation the agent
     * answers in). Always a call of `$ownerAccountId`, the account the conversation belongs to: another account's call
     * id is refused as not found.
     */
    public function endVoiceCall(int $ownerAccountId, ?int $currentConversationId, ?int $requestedCallId): AgentVoiceCallEnd
    {
        $nowUnixTime = time();
        $openCalls = $this->findOpenCalls($ownerAccountId);
        if ($requestedCallId !== null) {
            $agentVoiceCall = $this->findCall($requestedCallId, $ownerAccountId);
            if ($agentVoiceCall === null) {
                return AgentVoiceCallEnd::forRefused(AgentVoiceCallEnd::REFUSED_CALL_NOT_FOUND, null, $this->collectCallIds($openCalls));
            }
            if ($agentVoiceCall->endedAt !== null) {
                return AgentVoiceCallEnd::forRefused(AgentVoiceCallEnd::REFUSED_CALL_ALREADY_ENDED, $agentVoiceCall, $this->collectCallIds($openCalls));
            }
        } else {
            $agentVoiceCall = $this->findOpenCallOfConversation($openCalls, $currentConversationId, $nowUnixTime);
            if ($agentVoiceCall === null) {
                return AgentVoiceCallEnd::forRefused(AgentVoiceCallEnd::REFUSED_NO_CALL_IN_THIS_CONVERSATION, null, $this->collectCallIds($openCalls));
            }
        }

        $callStateBeforeTheEnd = $agentVoiceCall->getCallStateAt($nowUnixTime);
        if ($this->isTheCallTheAgentIsOn($agentVoiceCall, $currentConversationId, $callStateBeforeTheEnd)
            && $this->requestEndAfterGoodbye($agentVoiceCall)
        ) {
            $this->recordTraceEvent(
                $agentVoiceCall,
                static::TRACE_EVENT_TYPE_END_AFTER_GOODBYE_REQUESTED,
                $this->getAgentLabel() . ' ended the call it is on: hang-up after the goodbye'
            );

            return AgentVoiceCallEnd::forEnded(AgentVoiceCallEnd::OUTCOME_ENDS_AFTER_GOODBYE, $agentVoiceCall, $callStateBeforeTheEnd);
        }
        $this->endCallNow($agentVoiceCall);
        $this->recordTraceEvent(
            $agentVoiceCall,
            static::TRACE_EVENT_TYPE_ENDED_BY_AGENT,
            $this->getAgentLabel() . ($callStateBeforeTheEnd === AgentVoiceCall::CALL_STATE_RINGING ? ' withdrew the ringing call' : ' hung the call up')
        );

        return AgentVoiceCallEnd::forEnded(AgentVoiceCallEnd::OUTCOME_ENDED_NOW, $agentVoiceCall, $callStateBeforeTheEnd);
    }

    /**
     * The call the agent is on: answered and running, carried by the conversation the agent answers in, and accompanied
     * by a session worker that can hang up after the goodbye ({@see isCallAccompaniedBySessionWorker()}).
     */
    protected function isTheCallTheAgentIsOn(AgentVoiceCall $agentVoiceCall, ?int $currentConversationId, string $callState): bool
    {
        return $callState === AgentVoiceCall::CALL_STATE_IN_PROGRESS
            && $currentConversationId !== null
            && $agentVoiceCall->aiConversationId === $currentConversationId
            && $this->isCallAccompaniedBySessionWorker($agentVoiceCall);
    }

    /**
     * Whether a session worker of this package accompanies the running call and reads the request to hang up after the
     * goodbye. Every call of the base has one. An application whose calls can run on a platform without such a worker
     * answers false for them: their call is then ended at once.
     */
    protected function isCallAccompaniedBySessionWorker(AgentVoiceCall $agentVoiceCall): bool
    {
        return true;
    }

    /**
     * The open call of this conversation: a running one before a ringing one (the open calls come newest first).
     *
     * @param AgentVoiceCall[] $openCalls
     */
    protected function findOpenCallOfConversation(array $openCalls, ?int $currentConversationId, int $nowUnixTime): ?AgentVoiceCall
    {
        if ($currentConversationId === null) {
            return null;
        }
        $ringingCallOfConversation = null;
        foreach ($openCalls as $openCall) {
            if ($openCall->aiConversationId !== $currentConversationId) {
                continue;
            }
            if ($openCall->getCallStateAt($nowUnixTime) === AgentVoiceCall::CALL_STATE_IN_PROGRESS) {
                return $openCall;
            }
            $ringingCallOfConversation ??= $openCall;
        }

        return $ringingCallOfConversation;
    }

    /**
     * @param AgentVoiceCall[] $agentVoiceCalls
     * @return int[]
     */
    protected function collectCallIds(array $agentVoiceCalls): array
    {
        return array_map(static fn (AgentVoiceCall $agentVoiceCall): int => (int)$agentVoiceCall->id, $agentVoiceCalls);
    }

    /**
     * The owner account's calls that ring or run now ({@see AgentVoiceCallsService::findOpenCallsOfAccountId()}).
     *
     * @return AgentVoiceCall[]
     */
    protected function findOpenCalls(int $ownerAccountId): array
    {
        return $this->getAgentVoiceCallsService()->findOpenCallsOfAccountId($ownerAccountId)->getOpenCallsAt(time());
    }

    /** One call of the owner account by id (filtered by account, read fresh); null when it is none of the account's. */
    protected function findCall(int $callId, int $ownerAccountId): ?AgentVoiceCall
    {
        return $this->getAgentVoiceCallsService()->findCallOfAccountIdById($callId, $ownerAccountId);
    }

    /**
     * {@see AgentVoiceCallsService::requestCallEndAfterGoodbye()}; false when the request could not be stored (the call
     * then ends at once).
     */
    protected function requestEndAfterGoodbye(AgentVoiceCall $agentVoiceCall): bool
    {
        try {
            $this->getAgentVoiceCallsService()->requestCallEndAfterGoodbye($agentVoiceCall, AgentVoiceCall::END_REASON_ENDED_BY_AGENT);

            return true;
        } catch (Throwable $requestFailure) {
            DDDService::instance()->getLogger()->warning(
                'AgentVoiceCallEndsService: end-after-goodbye request for call ' . $agentVoiceCall->id . ' failed, ending it now: '
                . $requestFailure->getMessage()
            );

            return false;
        }
    }

    /** {@see AgentVoiceCallsService::endCallNow()} with the reason "ended by the agent". */
    protected function endCallNow(AgentVoiceCall $agentVoiceCall): void
    {
        $this->getAgentVoiceCallsService()->endCallNow($agentVoiceCall, AgentVoiceCall::END_REASON_ENDED_BY_AGENT);
    }

    /** The decision on the call's trace, best-effort. */
    protected function recordTraceEvent(AgentVoiceCall $agentVoiceCall, string $eventType, string $summary): void
    {
        try {
            /** @var AIConversationTraceEventsService $aIConversationTraceEventsService */
            $aIConversationTraceEventsService = DDDService::instance()->getService(AIConversationTraceEventsService::class);
            $aIConversationTraceEventsService->recordEvents([
                $this->getAgentVoiceCallTraceEventsService()->buildTraceEventForCall(
                    $agentVoiceCall,
                    AIConversationTraceEvent::SOURCE_VOICE_WORKER,
                    $eventType,
                    summary: $summary,
                    payload: ['endReason' => AgentVoiceCall::END_REASON_ENDED_BY_AGENT, 'channelType' => $agentVoiceCall->getChannelType()]
                ),
            ]);
        } catch (Throwable) {
            // the trace is diagnostics: never a reason to fail the end
        }
    }

    /** What the agent is called in a trace summary: the persona's agent name, else "The agent". */
    protected function getAgentLabel(): string
    {
        $agentName = AgentVoiceConfiguration::current()->agentName;

        return $agentName !== '' ? $agentName : ucfirst(AgentVoiceCall::DEFAULT_AGENT_LABEL);
    }

    protected function getAgentVoiceCallsService(): AgentVoiceCallsService
    {
        /** @var AgentVoiceCallsService $agentVoiceCallsService */
        $agentVoiceCallsService = DDDService::instance()->getService(AgentVoiceCallsService::class);

        return $agentVoiceCallsService;
    }

    protected function getAgentVoiceCallTraceEventsService(): AgentVoiceCallTraceEventsService
    {
        /** @var AgentVoiceCallTraceEventsService $agentVoiceCallTraceEventsService */
        $agentVoiceCallTraceEventsService = DDDService::instance()->getService(AgentVoiceCallTraceEventsService::class);

        return $agentVoiceCallTraceEventsService;
    }
}
