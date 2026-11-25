<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Voice;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\SignalSources\PhoneConversationSignalSource;
use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallReachableChannels;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationTurnSignalsService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\TraceEvents\AIConversationTraceEventsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * The agent's own follow-up after a call IT started went unanswered: "You did not pick up, shall I ...?".
 *
 * 1. {@see markCallStartedByAgent()}: `startVoiceCall` stamps the call with the conversation the agent started it from
 *    ({@see AgentVoiceCall::$startedByAgentInConversationId}); a call the owner, an operator or the console started
 *    carries no stamp and never follows up.
 * 2. {@see startFollowUpTurnIfAgentCallWentUnanswered()}: called by the unanswered-end write AFTER it won the call's
 *    end claim ({@see AgentVoiceCallsService::persistUnansweredCallEndOnce()}), so it runs once per call. When the call
 *    qualifies ({@see AgentVoiceCall::isUnansweredEndTheAgentFollowsUpOn()}: started by the agent, never answered, not
 *    refused by the owner) and its conversation is eligible for automation, it injects ONE turn-owing signal into that
 *    conversation through the regular signal entry ({@see AIConversationTurnSignalsService::injectSignal()}: an idle
 *    conversation is resumed, a running turn takes it over at its next model call). The text names the outcome and
 *    what reaches the owner now, and says: ask, never call again unasked.
 *
 * The override points of an application: {@see getFollowUpSignalSource()} (the stored name of the signal),
 * {@see getAutomationOperationName()} (the name the eligibility check logs), {@see buildFollowUpTracePayload()} (what
 * its further media add to the trace) and {@see findReachableChannelsForOwner()} (the reads of its further media).
 */
class AgentVoiceCallFollowUpsService extends Service
{
    /** The trace event of a started follow-up, on the call's trace. */
    public const string TRACE_EVENT_TYPE_FOLLOW_UP_STARTED = 'call.unanswered_follow_up_started';

    /**
     * Stamps `$agentVoiceCall` as started by the agent in `$followUpConversation`. Best-effort: a failed stamp is
     * logged and only means no follow-up for this call; the call itself runs.
     */
    public function markCallStartedByAgent(AgentVoiceCall $agentVoiceCall, AIConversation $followUpConversation): void
    {
        if (!isset($agentVoiceCall->id, $followUpConversation->id)) {
            return;
        }
        try {
            $this->writeStartedByAgentInConversation($agentVoiceCall, $followUpConversation);
        } catch (Throwable $stampFailure) {
            DDDService::instance()->getLogger()->warning(
                'AgentVoiceCallFollowUpsService: call ' . $agentVoiceCall->id . ' not stamped as started by the agent: '
                . $stampFailure->getMessage()
            );
        }
    }

    /**
     * Starts the agent's follow-up turn when this call (just ended unanswered, its end claim won by the caller)
     * qualifies. Never throws: a failure is logged; the call-end marker is already written and waits for the owner's
     * next turn. True when the follow-up signal was injected.
     */
    public function startFollowUpTurnIfAgentCallWentUnanswered(AgentVoiceCall $agentVoiceCall): bool
    {
        try {
            if (!$agentVoiceCall->isUnansweredEndTheAgentFollowsUpOn()) {
                return false;
            }
            DDDService::instance()->deactivateEntityRightsRestrictions();
            try {
                $followUpConversation = $this->findFollowUpConversation($agentVoiceCall);
                if ($followUpConversation === null || ($followUpConversation->accountId ?? null) === null) {
                    return false;
                }
                $ownerAccount = $this->findAccount((int)$followUpConversation->accountId);
                $agentSpace = null;
                if (isset($followUpConversation->agentSpaceId)) {
                    $agentSpace = $followUpConversation->agentSpace ?? null;
                    if ($agentSpace === null) {
                        return false; // the space the conversation works for is gone
                    }
                }
                if ($ownerAccount === null || $this->isScopeRefusedForAutomation($followUpConversation)) {
                    return false;
                }
                $agentVoiceCallReachableChannels = $this->findReachableChannelsForOwner($ownerAccount, $agentSpace, $followUpConversation);
                $this->injectFollowUpSignal(
                    $followUpConversation,
                    $ownerAccount,
                    $agentVoiceCall->buildUnansweredFollowUpSignalText($agentVoiceCallReachableChannels)
                );
                $this->recordFollowUpTraceEvent($agentVoiceCall, $followUpConversation, $agentVoiceCallReachableChannels);

                return true;
            } finally {
                DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
            }
        } catch (Throwable $followUpFailure) {
            DDDService::instance()->getLogger()->error(
                'AgentVoiceCallFollowUpsService: follow-up of unanswered call ' . $agentVoiceCall->id . ' failed: '
                . $followUpFailure->getMessage()
            );

            return false;
        }
    }

    /**
     * The stamp's write: a write of that one value through the call store, so a ring or end write of the same call
     * running at the same time is never overwritten.
     */
    protected function writeStartedByAgentInConversation(AgentVoiceCall $agentVoiceCall, AIConversation $followUpConversation): void
    {
        /** @var AgentVoiceCallStoreService $agentVoiceCallStoreService */
        $agentVoiceCallStoreService = DDDService::instance()->getService(AgentVoiceCallStoreService::class);
        $agentVoiceCallStoreService->markCallStartedByAgentInConversation($agentVoiceCall, $followUpConversation);
    }

    protected function findFollowUpConversation(AgentVoiceCall $agentVoiceCall): ?AIConversation
    {
        $followUpConversation = AIConversation::getService()->find((int)$agentVoiceCall->startedByAgentInConversationId, false);

        return $followUpConversation instanceof AIConversation ? $followUpConversation : null;
    }

    protected function findAccount(int $accountId): ?Account
    {
        $account = Account::getService()->find($accountId);

        return $account instanceof Account ? $account : null;
    }

    /** The eligibility gate of everything the agent does on its own: the one a call start applies. */
    protected function isScopeRefusedForAutomation(AIConversation $followUpConversation): bool
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = AIConversation::getService();

        return $aIConversationsService->refusesAutomationForConversation($followUpConversation, $this->getAutomationOperationName());
    }

    /** The name of this operation in the log of a refused automation. */
    protected function getAutomationOperationName(): string
    {
        return 'voiceCallFollowUp';
    }

    /** {@see AgentVoiceCallStartsService::findReachableChannelsForOwner()}: the same reads a call start routes on. */
    protected function findReachableChannelsForOwner(
        Account $ownerAccount,
        ?AgentSpace $agentSpace,
        AIConversation $followUpConversation
    ): AgentVoiceCallReachableChannels {
        /** @var AgentVoiceCallStartsService $agentVoiceCallStartsService */
        $agentVoiceCallStartsService = DDDService::instance()->getService(AgentVoiceCallStartsService::class);

        return $agentVoiceCallStartsService->findReachableChannelsForOwner($ownerAccount, $agentSpace, $followUpConversation);
    }

    /**
     * The follow-up signal through the regular signal entry, under the owner's account (the resume it dispatches runs
     * the turn as that account); the caller's signed-in account is restored afterwards.
     */
    protected function injectFollowUpSignal(AIConversation $followUpConversation, Account $ownerAccount, string $followUpSignalText): void
    {
        $previousAuthAccount = AuthService::instance()->getAccount();
        AuthService::instance()->setAccount($ownerAccount);
        try {
            /** @var AIConversationTurnSignalsService $aIConversationTurnSignalsService */
            $aIConversationTurnSignalsService = DDDService::instance()->getService(AIConversationTurnSignalsService::class);
            $aIConversationTurnSignalsService->injectSignal(
                $followUpConversation,
                $followUpSignalText,
                $this->getFollowUpSignalSource(),
                async: true
            );
        } finally {
            AuthService::instance()->setAccount($previousAuthAccount);
        }
    }

    /** The signal source the follow-up is stored with. A stored value: an application returns the one its rows carry. */
    protected function getFollowUpSignalSource(): string
    {
        return PhoneConversationSignalSource::PHONE_CALL_UNANSWERED_FOLLOW_UP->value;
    }

    /** The decision on the call's trace, best-effort. */
    protected function recordFollowUpTraceEvent(
        AgentVoiceCall $agentVoiceCall,
        AIConversation $followUpConversation,
        AgentVoiceCallReachableChannels $agentVoiceCallReachableChannels
    ): void {
        try {
            /** @var AIConversationTraceEventsService $aIConversationTraceEventsService */
            $aIConversationTraceEventsService = DDDService::instance()->getService(AIConversationTraceEventsService::class);
            $aIConversationTraceEventsService->recordEvents([
                $this->getAgentVoiceCallTraceEventsService()->buildTraceEventForCall(
                    $agentVoiceCall,
                    AIConversationTraceEvent::SOURCE_VOICE_WORKER,
                    static::TRACE_EVENT_TYPE_FOLLOW_UP_STARTED,
                    summary: 'unanswered (' . $agentVoiceCall->endReason . '): follow-up turn started in conversation ' . $followUpConversation->id,
                    payload: $this->buildFollowUpTracePayload($agentVoiceCall, $followUpConversation, $agentVoiceCallReachableChannels)
                ),
            ]);
        } catch (Throwable) {
            // the trace is diagnostics: never a reason to fail the follow-up
        }
    }

    /**
     * What the trace event of a started follow-up carries. A subclass with further media adds what they could reach.
     *
     * @return array<string, mixed>
     */
    protected function buildFollowUpTracePayload(
        AgentVoiceCall $agentVoiceCall,
        AIConversation $followUpConversation,
        AgentVoiceCallReachableChannels $agentVoiceCallReachableChannels
    ): array {
        return [
            'endReason' => $agentVoiceCall->endReason,
            'followUpConversationId' => (int)$followUpConversation->id,
            'appIsRingableNow' => $agentVoiceCallReachableChannels->appIsRingableNow,
        ];
    }

    protected function getAgentVoiceCallTraceEventsService(): AgentVoiceCallTraceEventsService
    {
        /** @var AgentVoiceCallTraceEventsService $agentVoiceCallTraceEventsService */
        $agentVoiceCallTraceEventsService = DDDService::instance()->getService(AgentVoiceCallTraceEventsService::class);

        return $agentVoiceCallTraceEventsService;
    }
}
