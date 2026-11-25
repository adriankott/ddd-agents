<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Voice;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentEligibilityLogContext;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent;
use DDD\Domain\AI\Entities\Voice\AgentVoiceConfiguration;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallReachableChannels;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallRefusal;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallRoute;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallStart;
use DDD\Domain\AI\Exceptions\Evals\EvalOutboundRecipientBlockedException;
use DDD\Domain\AI\MessageHandlers\Voice\AgentVoiceRingDeadlineMessage;
use DDD\Domain\AI\Services\Conversations\Eligibility\AgentAutomationEligibilityService;
use DDD\Domain\AI\Services\Conversations\Purposes\AIConversationPurposesService;
use DDD\Domain\AI\Services\Conversations\TraceEvents\AIConversationTraceEventsService;
use DDD\Domain\AI\Services\Eval\EvalOutboundRecipientsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Throwable;

/**
 * The agent calls the owner: the ONE start of a call that rings the owner, from the agent's tool
 * ({@see startVoiceCall()}) and from an application's "call me" route ({@see startRing()}).
 *
 * `startVoiceCall()` in order:
 * 1. nothing for an agent space or account that takes no automation any more (the eligibility gate of the agent
 *    package);
 * 2. a purpose only for a call in a NEW conversation, and only a purpose made for calls;
 * 3. never while the owner is on a call with the agent already: this conversation's own call, or a call of the
 *    owner's account elsewhere;
 * 4. the medium: {@see AgentVoiceCallRoute::resolveForRequest()} over whether the voice channel is available to the
 *    owner (the admission port) and whether a ring reaches the owner right now ({@see isAppRingable()}, free of side
 *    effects);
 * 5. the start: {@see startRing()}.
 *
 * RING TARGETS are a list ({@see getRingTargetNames()}) with one entry in this version, the owner's open clients
 * ({@see self::RING_TARGET_OPEN_CLIENTS}, the ring message of {@see AgentVoiceRingService}). A call is created once and
 * rung on every target that is reachable; a ring that reaches no target ends the call FAILED at once.
 *
 * Every call started through `startVoiceCall()` is stamped as started by the agent
 * ({@see AgentVoiceCallFollowUpsService::markCallStartedByAgent()}, the follow-up conversation = the chat the agent is
 * in). An unanswered call never turns into another call by itself: how it ended reaches the conversation as the call
 * end marker, and an EXPIRED end of such a call starts ONE follow-up turn in which the agent ASKS what to do; a
 * DECLINED ring starts none.
 *
 * Override points of an application's subclass at the same path:
 * - a further medium: the application overrides {@see startVoiceCall()} with its own arguments after
 *   the base's (the requested channel, the address to dial), decides with its subclass of {@see AgentVoiceCallRoute}
 *   and starts its own medium; {@see resolveCallRoute()} is the decision of the base, and the steps before and after
 *   it are `protected` methods the override composes;
 * - a further ring target: {@see getRingTargetNames()}, {@see isRingTargetReachableNow()},
 *   {@see ringCallOnTarget()} and {@see buildRingTargetTraceEvent()};
 * - {@see applyApplicationValuesToNewCall()}: the application's own columns on a new ringing call;
 * - {@see isAppIncluded()}, {@see findAdmissionRefusalCodeOfCallStart()} and
 *   {@see buildCallStartRefusedByAdmission()}: how the application's own entitlement reads and words itself;
 * - {@see isScopeRefusedForAutomation()}, {@see refuseRingInsideAnEvalRun()}: the agent's gates for automation and
 *   for evaluation runs, asked here.
 * The other `find*` / `start*` / `mark*` / `create*` methods are the points a test replaces.
 */
class AgentVoiceCallStartsService extends Service
{
    /** @var string The ring target "the owner's open clients": the ring message on the realtime port */
    public const string RING_TARGET_OPEN_CLIENTS = 'openClients';

    /** @var string The operation name the agent's eligibility gate logs a refused call start under */
    public const string AUTOMATION_OPERATION_NAME = 'voiceCall';

    /** @var int Seconds after the ring deadline at which the delayed deadline message looks at the ring */
    public const int RING_DEADLINE_CHECK_GRACE_SECONDS = 5;

    /** @var string The error code of a ring no ring target can take (the stored spelling of the running installations) */
    public const string ERROR_CODE_NO_RING_TARGET_REACHABLE = 'no_registered_devices';

    /** @var string The trace event of a started ring: how many of the selected ring targets were rung */
    public const string TRACE_EVENT_RING_STARTED = 'call.ring_started';

    /**
     * Starts the agent's call to the owner.
     *
     * The conversation: `$currentConversation` is the chat's root conversation the agent is in (the on-a-call check
     * reads it either way); the call CONTINUES it unless `$callGetsNewConversation`: then the call gets a NEW
     * conversation of its own. A purpose made for calls ({@see AIConversationPurpose::getSpokenCallTask()} not null) is
     * possible only for a NEW conversation: the conversation then carries it and its spoken task is the call's task. A
     * conversation hidden from the owner never carries a ring (accepting it would open a chat the owner cannot see);
     * the ring then gets a new one. An empty task opens the call by asking how the agent can help.
     *
     * @param Account $ownerAccount the account the agent works for in this turn
     * @param AgentSpace|null $agentSpace the agent space the call works for; null = the account as a whole
     * @throws EvalOutboundRecipientBlockedException in an eval run (a ring has no test sink)
     */
    public function startVoiceCall(
        Account $ownerAccount,
        ?AgentSpace $agentSpace,
        ?AIConversation $currentConversation,
        bool $callGetsNewConversation,
        ?string $callTask,
        ?string $purposeName = null
    ): AgentVoiceCallStart {
        $carrierConversation = $callGetsNewConversation ? null : $currentConversation;
        if ($this->isScopeRefusedForAutomation($ownerAccount, $agentSpace, $currentConversation)) {
            return AgentVoiceCallStart::forRefused(AgentVoiceCallStart::REFUSED_SCOPE_NOT_ACTIVE);
        }
        $callPurpose = null;
        if ($purposeName !== null) {
            if ($carrierConversation !== null) {
                return AgentVoiceCallStart::forRefused(AgentVoiceCallStart::REFUSED_PURPOSE_NEEDS_NEW_CONVERSATION);
            }
            $callPurpose = $this->findCallablePurpose($purposeName);
            if ($callPurpose === null) {
                $purposeRefusal = AgentVoiceCallStart::forRefused(AgentVoiceCallStart::REFUSED_PURPOSE_NOT_CALLABLE);
                $purposeRefusal->callablePurposeNames = $this->findCallablePurposeNames();
                return $purposeRefusal;
            }
        }
        if ($this->isOwnerAlreadyOnACall($currentConversation, $ownerAccount)) {
            return AgentVoiceCallStart::forRefused(AgentVoiceCallStart::REFUSED_ALREADY_ON_A_CALL);
        }
        $appIsIncluded = $this->isAppIncluded($ownerAccount, $agentSpace, $carrierConversation);
        $ringConversation = $carrierConversation !== null && !$carrierConversation->isHiddenFromCustomer()
            ? $carrierConversation
            : null;
        $appIsRingable = $appIsIncluded && $this->isAppRingable($ownerAccount, $ringConversation);

        $agentVoiceCallRoute = $this->resolveCallRoute($appIsIncluded, $appIsRingable);
        if ($agentVoiceCallRoute->refusalReason !== null) {
            return AgentVoiceCallStart::forRefused($agentVoiceCallRoute->refusalReason, $appIsIncluded);
        }
        if ($callPurpose !== null) {
            $ringConversation = $this->createPurposedConversationForAppRing($ownerAccount, $agentSpace, $callPurpose);
            if ($ringConversation === null) {
                return AgentVoiceCallStart::forRefused(AgentVoiceCallStart::REFUSED_NO_CALL_CONVERSATION, true);
            }
            $callTask = $callPurpose->getSpokenCallTask();
        }
        $appRingStart = $this->ringTheApp($ownerAccount, $agentSpace, $ringConversation, $callTask);
        $this->markStartedCallAsStartedByAgent($appRingStart, $currentConversation);
        return $appRingStart;
    }

    /**
     * Rings the owner for a call of the agent: ONE call, rung on every ring target that reaches the owner now.
     *
     * The call is OUTBOUND (the agent calls the owner). It is created for the ONE account that is rung
     * ({@see AgentVoiceRingService::resolveRingTargetAccountId()}: the account of the conversation, else
     * `$ownerAccount`), and answering it requires that account's sign-in, so who answers is known before any audio
     * exists. `startedAt` stays empty until a client wins the answer claim; the ring deadline bounds the unanswered
     * state, and a delayed message looks at the ring right after it ({@see AgentVoiceRingDeadlineMessage}).
     *
     * A ring that reaches no target comes back as an ENDED call (FAILED), not as an error: the call exists, it is a
     * finished call and not a failed request.
     *
     * @param Account $ownerAccount the account that is asked for; the admission is asked for it
     * @param AgentSpace|null $agentSpace the agent space the call works for; null = the account as a whole
     * @param AIConversation|null $aiConversation the conversation the call continues; null creates one
     * @param string|null $callTask what the agent works on in this call; it becomes the task of the session briefing
     * @throws ForbiddenException when the admission refuses the call; the message is the admission's code
     * @throws BadRequestException when no ring target reaches the owner now (`no_registered_devices`)
     * @throws EvalOutboundRecipientBlockedException in an eval run (a ring has no test sink)
     */
    public function startRing(
        Account $ownerAccount,
        ?AgentSpace $agentSpace,
        ?AIConversation $aiConversation = null,
        ?string $callTask = null
    ): AgentVoiceCall {
        $admissionRefusalCode = $this->findAdmissionRefusalCodeOfCallStart($ownerAccount, $agentSpace, $aiConversation);
        if ($admissionRefusalCode !== null) {
            throw new ForbiddenException($admissionRefusalCode);
        }
        // A ring of this account that passed its deadline ends now, before the new one starts (never blocks the ring).
        $this->expireOverdueRingsOfOwner($ownerAccount);
        // An eval run never rings: a ring reaches a real person and has no test sink.
        $this->refuseRingInsideAnEvalRun($ownerAccount, $agentSpace, $aiConversation);
        $reachableRingTargetNames = $this->findReachableRingTargetNames($ownerAccount, $aiConversation);
        if ($reachableRingTargetNames === []) {
            throw new BadRequestException(static::ERROR_CODE_NO_RING_TARGET_REACHABLE);
        }

        $agentVoiceCall = AgentVoiceCall::newInstance();
        $agentVoiceCall->direction = AgentVoiceCall::DIRECTION_OUTBOUND;
        $agentVoiceCall->accountId = $this->getAgentVoiceRingService()->resolveRingTargetAccountId(
            $aiConversation,
            isset($ownerAccount->id) ? (int)$ownerAccount->id : null
        );
        $agentVoiceCall->ringDeadlineAt = DateTime::fromTimestamp(time() + AgentVoiceConfiguration::current()->ringSeconds);
        if ($callTask !== null && trim($callTask) !== '') {
            $agentVoiceCall->callTask = trim($callTask);
        }
        $this->applyApplicationValuesToNewCall($agentVoiceCall, $ownerAccount, $agentSpace);

        if ($aiConversation === null) {
            $aiConversation = $this->createConversationForRing($agentVoiceCall, $ownerAccount, $agentSpace);
        }
        if ($aiConversation?->id !== null) {
            $agentVoiceCall->aiConversationId = (int)$aiConversation->id;
        }
        $agentVoiceCall = $this->getAgentVoiceCallStoreService()->createCall($agentVoiceCall);

        $this->ringCallOnTargets($agentVoiceCall, $reachableRingTargetNames, $agentSpace);
        if ($agentVoiceCall->endedAt === null) {
            $this->dispatchRingDeadlineCheck($agentVoiceCall);
        }

        return $agentVoiceCall;
    }

    /**
     * Which medium would reach the owner RIGHT NOW: the reads {@see startVoiceCall()} routes on, without starting
     * anything: whether the voice channel is available to the owner, and whether a ring reaches the owner now (a
     * conversation hidden from the owner never carries a ring, so the ring is read without it). The follow-up of an
     * unanswered call offers only this ({@see AgentVoiceCallFollowUpsService}).
     */
    public function findReachableChannelsForOwner(
        Account $ownerAccount,
        ?AgentSpace $agentSpace,
        ?AIConversation $conversation
    ): AgentVoiceCallReachableChannels {
        $agentVoiceCallReachableChannels = AgentVoiceCallReachableChannels::newInstance();
        $agentVoiceCallReachableChannels->appIsIncluded = $this->isAppIncluded($ownerAccount, $agentSpace, $conversation);
        $agentVoiceCallReachableChannels->appIsRingableNow = $agentVoiceCallReachableChannels->appIsIncluded
            && $this->isAppRingable($ownerAccount, $conversation !== null && !$conversation->isHiddenFromCustomer() ? $conversation : null);
        return $agentVoiceCallReachableChannels;
    }

    /**
     * {@see findReachableChannelsForOwner()} for a conversation: its owner account (the account that started it) and
     * its agent space, read with the rights lifted. The reading behind the `voiceAppRing` situation of a conversation
     * whose loaded skill keys on it ({@see AgentVoiceCallReachableChannels::putIntoVariants()}). Null when the
     * conversation has no account, or its account or the agent space it names is gone.
     */
    public function findReachableChannelsForConversation(AIConversation $conversation): ?AgentVoiceCallReachableChannels
    {
        if (empty($conversation->accountId)) {
            return null;
        }
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $ownerAccount = $this->findAccount((int)$conversation->accountId);
            if ($ownerAccount === null) {
                return null;
            }
            $agentSpace = null;
            if (isset($conversation->agentSpaceId)) {
                $agentSpace = $conversation->agentSpace ?? null;
                if ($agentSpace === null) {
                    return null;
                }
            }
            return $this->findReachableChannelsForOwner($ownerAccount, $agentSpace, $conversation);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /**
     * The ring targets of this installation, in the order they are rung.
     *
     * @return string[]
     */
    protected function getRingTargetNames(): array
    {
        return [static::RING_TARGET_OPEN_CLIENTS];
    }

    /**
     * Whether `$ringTargetName` would take a ring for this owner right now, without ringing anything. The open
     * clients: a client of the account that would be rung listens.
     */
    protected function isRingTargetReachableNow(string $ringTargetName, Account $ownerAccount, ?AIConversation $ringConversation): bool
    {
        if ($ringTargetName !== static::RING_TARGET_OPEN_CLIENTS) {
            return false;
        }
        $agentVoiceRingService = $this->getAgentVoiceRingService();
        return $agentVoiceRingService->isAnyClientOfAccountListening(
            $agentVoiceRingService->resolveRingTargetAccountId($ringConversation, isset($ownerAccount->id) ? (int)$ownerAccount->id : null)
        );
    }

    /**
     * The ring targets that would take a ring for this owner right now.
     *
     * @return string[]
     */
    protected function findReachableRingTargetNames(Account $ownerAccount, ?AIConversation $ringConversation): array
    {
        $reachableRingTargetNames = [];
        foreach ($this->getRingTargetNames() as $ringTargetName) {
            if ($this->isRingTargetReachableNow($ringTargetName, $ownerAccount, $ringConversation)) {
                $reachableRingTargetNames[] = $ringTargetName;
            }
        }
        return $reachableRingTargetNames;
    }

    /** Rings the call on ONE target; true when the ring was delivered there. The open clients: the ring message. */
    protected function ringCallOnTarget(string $ringTargetName, AgentVoiceCall $agentVoiceCall, ?AgentSpace $agentSpace): bool
    {
        if ($ringTargetName !== static::RING_TARGET_OPEN_CLIENTS) {
            return false;
        }
        return $this->getAgentVoiceRingService()->publishRing($agentVoiceCall, $agentSpace);
    }

    /**
     * Rings the call on every given target, one after the other. Nothing delivered ends the call FAILED; the whole
     * ring goes into the conversation trace as one batched write after the targets were rung, never before.
     *
     * @param string[] $ringTargetNames
     */
    protected function ringCallOnTargets(AgentVoiceCall $agentVoiceCall, array $ringTargetNames, ?AgentSpace $agentSpace): void
    {
        $ringStartedAtUnixMs = AIConversationTraceEvent::nowUnixMs();
        $deliveredRingCount = 0;
        $ringTraceEvents = [];
        foreach ($ringTargetNames as $ringTargetName) {
            $targetRingStartedAtUnixMs = AIConversationTraceEvent::nowUnixMs();
            $ringDelivered = $this->ringCallOnTarget($ringTargetName, $agentVoiceCall, $agentSpace);
            $deliveredRingCount += $ringDelivered ? 1 : 0;
            $ringTraceEvents[] = $this->buildRingTargetTraceEvent($ringTargetName, $agentVoiceCall, $ringDelivered, $targetRingStartedAtUnixMs);
        }
        if ($deliveredRingCount === 0) {
            // Nothing was delivered: "it never rang" must not stay open until the ring deadline.
            $this->getAgentVoiceCallStoreService()->markCallEnded($agentVoiceCall, AgentVoiceCall::END_REASON_FAILED);
        }
        $selectedRingTargetCount = count($ringTargetNames);
        $ringTraceEvents[] = $this->getAgentVoiceCallTraceEventsService()->buildTraceEventForCall(
            $agentVoiceCall,
            AIConversationTraceEvent::SOURCE_VOICE_WORKER,
            static::TRACE_EVENT_RING_STARTED,
            (string)$ringStartedAtUnixMs,
            $deliveredRingCount . ' of ' . $selectedRingTargetCount . ' devices rung',
            ['devicesRung' => $deliveredRingCount, 'devicesSelected' => $selectedRingTargetCount],
            AIConversationTraceEvent::nowUnixMs() - $ringStartedAtUnixMs,
            $ringStartedAtUnixMs
        );
        $this->recordRingTraceEvents($ringTraceEvents);
    }

    /**
     * The trace event of the ring on ONE target: whether it was delivered and how long it took. The base writes the
     * event of the open clients; a subclass with a further ring target writes its own kind and discriminator for it.
     */
    protected function buildRingTargetTraceEvent(
        string $ringTargetName,
        AgentVoiceCall $agentVoiceCall,
        bool $ringDelivered,
        int $targetRingStartedAtUnixMs
    ): AIConversationTraceEvent {
        return $this->getAgentVoiceCallTraceEventsService()->buildTraceEventForCall(
            $agentVoiceCall,
            AIConversationTraceEvent::SOURCE_VOICE_WORKER,
            AgentVoiceRingService::TRACE_EVENT_RING_WRITTEN,
            'browserTab',
            $ringDelivered
                ? 'ring message sent to the open clients of account ' . $agentVoiceCall->accountId
                : 'ring message for the open clients of account ' . $agentVoiceCall->accountId . ' FAILED',
            ['delivered' => $ringDelivered, 'targetAccountId' => $agentVoiceCall->accountId],
            AIConversationTraceEvent::nowUnixMs() - $targetRingStartedAtUnixMs,
            $targetRingStartedAtUnixMs
        );
    }

    /**
     * The trace events of a ring in one batched write.
     *
     * @param AIConversationTraceEvent[] $ringTraceEvents
     */
    protected function recordRingTraceEvents(array $ringTraceEvents): void
    {
        /** @var AIConversationTraceEventsService $aIConversationTraceEventsService */
        $aIConversationTraceEventsService = DDDService::instance()->getService(AIConversationTraceEventsService::class);
        $aIConversationTraceEventsService->recordEvents($ringTraceEvents);
    }

    /**
     * One delayed look at the ring right after its deadline: it ends a ring nobody answered as EXPIRED. A lost
     * dispatch is logged; the turn start of the call's conversation ends the ring then.
     */
    protected function dispatchRingDeadlineCheck(AgentVoiceCall $agentVoiceCall): void
    {
        $secondsUntilDeadline = $agentVoiceCall->ringDeadlineAt !== null
            ? max(0, $agentVoiceCall->ringDeadlineAt->getTimestamp() - time())
            : AgentVoiceConfiguration::current()->ringSeconds;
        try {
            (new AgentVoiceRingDeadlineMessage((int)$agentVoiceCall->id))->dispatch([
                new DelayStamp(($secondsUntilDeadline + static::RING_DEADLINE_CHECK_GRACE_SECONDS) * 1000),
            ]);
        } catch (Throwable $dispatchFailure) {
            DDDService::instance()->getLogger()->warning(
                'AgentVoiceCallStartsService: ring deadline check of call ' . $agentVoiceCall->id . ' not dispatched: '
                . $dispatchFailure->getMessage()
            );
        }
    }

    /**
     * The lazy expiry of the owner's overdue rings ({@see AgentVoiceRingService::expireOverdueRingsOfAccount()}) as a
     * side step of a new ring: a failure is logged and never blocks the ring.
     */
    protected function expireOverdueRingsOfOwner(Account $ownerAccount): void
    {
        try {
            $this->getAgentVoiceRingService()->expireOverdueRingsOfAccount($ownerAccount);
        } catch (Throwable $expiryFailure) {
            DDDService::instance()->getLogger()->warning(
                'AgentVoiceCallStartsService: lazy ring expiry failed for account ' . ($ownerAccount->id ?? null) . ': '
                . $expiryFailure->getMessage()
            );
        }
    }

    /**
     * What an application sets on a new ringing call beside the base's columns (the columns of its subclass of the
     * call), before the call is stored. The base sets nothing.
     */
    protected function applyApplicationValuesToNewCall(AgentVoiceCall $agentVoiceCall, Account $ownerAccount, ?AgentSpace $agentSpace): void
    {
    }

    /**
     * The conversation a ring runs on when its caller attached none: owned by `$ownerAccount` and working for
     * `$agentSpace`. Null when it could not be created: the call then rings without a conversation.
     */
    protected function createConversationForRing(AgentVoiceCall $agentVoiceCall, Account $ownerAccount, ?AgentSpace $agentSpace): ?AIConversation
    {
        return $this->getAgentVoiceCallsService()->createConversationForAppCall($agentVoiceCall, $ownerAccount, $agentSpace);
    }

    /** The agent's eval guard for a ring: a ring reaches a real person, so an eval run never rings. */
    protected function refuseRingInsideAnEvalRun(Account $ownerAccount, ?AgentSpace $agentSpace, ?AIConversation $aiConversation): void
    {
        /** @var EvalOutboundRecipientsService $evalOutboundRecipientsService */
        $evalOutboundRecipientsService = DDDService::instance()->getService(EvalOutboundRecipientsService::class);
        $evalOutboundRecipientsService->throwIfBlocked($evalOutboundRecipientsService->checkDeviceRecipient(
            EvalOutboundRecipientsService::CHANNEL_APP_CALL_RING,
            isset($ownerAccount->id) ? (int)$ownerAccount->id : null,
            $agentSpace,
            $aiConversation !== null && $evalOutboundRecipientsService->isConversationOfAnEvalRunTree($aiConversation)
        ));
    }

    /** The decision which medium the call starts on. The base knows the application's window only. */
    protected function resolveCallRoute(bool $appIsIncluded, bool $appIsRingable): AgentVoiceCallRoute
    {
        return AgentVoiceCallRoute::resolveForRequest($appIsIncluded, $appIsRingable);
    }

    protected function findAccount(int $accountId): ?Account
    {
        $account = Account::getService()->find($accountId);
        return $account instanceof Account ? $account : null;
    }

    /**
     * Stamps the call that just started as the agent's own, with the conversation the agent decided in (the chat's
     * root conversation; without one, the call's own conversation): the conversation its unanswered-call follow-up
     * runs in. Nothing for a refusal or a call that already ended (a ring that reached no target answers through the
     * tool's refusal).
     */
    protected function markStartedCallAsStartedByAgent(AgentVoiceCallStart $agentVoiceCallStart, ?AIConversation $currentConversation): void
    {
        $agentVoiceCall = $agentVoiceCallStart->agentVoiceCall;
        if ($agentVoiceCallStart->isRefused() || $agentVoiceCall === null || $agentVoiceCall->endedAt !== null) {
            return;
        }
        $followUpConversation = $currentConversation !== null && isset($currentConversation->id)
            ? $currentConversation
            : $this->findCallConversation($agentVoiceCallStart);
        if ($followUpConversation === null) {
            return;
        }
        $this->markCallStartedByAgent($agentVoiceCall, $followUpConversation);
    }

    /** The conversation the started call runs on, when the agent's call start carried no conversation of its own. */
    protected function findCallConversation(AgentVoiceCallStart $agentVoiceCallStart): ?AIConversation
    {
        if ($agentVoiceCallStart->callConversationId === null) {
            return null;
        }
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $callConversation = AIConversation::getService()->find($agentVoiceCallStart->callConversationId, false);
            return $callConversation instanceof AIConversation ? $callConversation : null;
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /** {@see AgentVoiceCallFollowUpsService::markCallStartedByAgent()}: best effort, never blocks the started call. */
    protected function markCallStartedByAgent(AgentVoiceCall $agentVoiceCall, AIConversation $followUpConversation): void
    {
        /** @var AgentVoiceCallFollowUpsService $agentVoiceCallFollowUpsService */
        $agentVoiceCallFollowUpsService = DDDService::instance()->getService(AgentVoiceCallFollowUpsService::class);
        $agentVoiceCallFollowUpsService->markCallStartedByAgent($agentVoiceCall, $followUpConversation);
    }

    /**
     * The ring with its two outcomes beyond a ringing call: the admission of the ring refuses (a refusal with its
     * reason), and a ring that reached no target at all (the call ended FAILED at once).
     */
    protected function ringTheApp(
        Account $ownerAccount,
        ?AgentSpace $agentSpace,
        ?AIConversation $ringConversation,
        ?string $callTask
    ): AgentVoiceCallStart {
        try {
            $agentVoiceCall = $this->startAppRing($ownerAccount, $agentSpace, $ringConversation, $callTask);
        } catch (ForbiddenException $admissionRefusal) {
            // startRing() refuses with Forbidden for exactly one reason: the admission; its code is the message.
            return $this->buildCallStartRefusedByAdmission($admissionRefusal->getMessage());
        }
        if ($agentVoiceCall->endedAt !== null) {
            return AgentVoiceCallStart::forRefused(AgentVoiceCallStart::REFUSED_APP_RING_DELIVERED_NOWHERE, true);
        }
        return AgentVoiceCallStart::forAppRinging($agentVoiceCall);
    }

    /** The refused call start of an admission code: which of the reasons the tool words. */
    protected function buildCallStartRefusedByAdmission(string $admissionRefusalCode): AgentVoiceCallStart
    {
        return match ($admissionRefusalCode) {
            AgentVoiceCallRefusal::CODE_NO_AI_BUDGET => AgentVoiceCallStart::forRefused(AgentVoiceCallStart::REFUSED_NO_AI_BUDGET, true),
            AgentVoiceCallRefusal::CODE_DAILY_LIMIT_REACHED => AgentVoiceCallStart::forRefused(AgentVoiceCallStart::REFUSED_DAILY_LIMIT_REACHED, true),
            default => AgentVoiceCallStart::forRefused(AgentVoiceCallStart::REFUSED_APP_NOT_INCLUDED),
        };
    }

    /** The named purpose when it is made for calls (it declares a spoken call task), else null. */
    protected function findCallablePurpose(string $purposeName): ?AIConversationPurpose
    {
        if (!AIConversationPurpose::isRegisteredPurposeName($purposeName)) {
            return null;
        }
        /** @var AIConversationPurposesService $aIConversationPurposesService */
        $aIConversationPurposesService = DDDService::instance()->getService(AIConversationPurposesService::class);
        $aIConversationPurpose = $aIConversationPurposesService->getPurposeByName($purposeName);
        return $aIConversationPurpose instanceof AIConversationPurpose && $aIConversationPurpose->getSpokenCallTask() !== null
            ? $aIConversationPurpose
            : null;
    }

    /**
     * The registered purposes a call conversation may carry: those that declare a spoken call task.
     *
     * @return string[]
     */
    protected function findCallablePurposeNames(): array
    {
        $callablePurposeNames = [];
        foreach (AIConversationPurpose::getAllPurposeNames() as $registeredPurposeName) {
            if ($this->findCallablePurpose($registeredPurposeName) !== null) {
                $callablePurposeNames[] = $registeredPurposeName;
            }
        }
        return $callablePurposeNames;
    }

    /** The purposed conversation a ring runs on: the owner's, created as every purposed call conversation. */
    protected function createPurposedConversationForAppRing(
        Account $ownerAccount,
        ?AgentSpace $agentSpace,
        AIConversationPurpose $callPurpose
    ): ?AIConversation {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            return $this->getAgentVoiceCallsService()->createPurposedCallConversation($ownerAccount, $agentSpace, (string)$callPurpose->name);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /**
     * The eligibility gate of every autonomous spender of the agent: an agent space, or without one the
     * account, that takes no automation any more gets no call.
     */
    protected function isScopeRefusedForAutomation(Account $ownerAccount, ?AgentSpace $agentSpace, ?AIConversation $currentConversation): bool
    {
        /** @var AgentAutomationEligibilityService $agentAutomationEligibilityService */
        $agentAutomationEligibilityService = DDDService::instance()->getService(AgentAutomationEligibilityService::class);
        $agentEligibilityLogContext = AgentEligibilityLogContext::forConversation(
            $currentConversation !== null && isset($currentConversation->id) ? (int)$currentConversation->id : null
        );
        if ($agentSpace !== null) {
            return $agentAutomationEligibilityService->refusesAutomationForAgentSpace($agentSpace, static::AUTOMATION_OPERATION_NAME, $agentEligibilityLogContext);
        }
        return isset($ownerAccount->id)
            && $agentAutomationEligibilityService->refusesAutomationForAccountId((int)$ownerAccount->id, static::AUTOMATION_OPERATION_NAME, $agentEligibilityLogContext);
    }

    /**
     * Whether the owner is on a call with the agent right now: an ANSWERED, still open call on this conversation, or a
     * call of the owner's account that rings or runs anywhere (the open-call check of the account, before which the
     * rings of that account past their deadline expire: {@see AgentVoiceCallsService::findOpenAppCallForAccountId()}).
     */
    protected function isOwnerAlreadyOnACall(?AIConversation $carrierConversation, Account $ownerAccount): bool
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            if ($carrierConversation !== null && isset($carrierConversation->id)
                && $this->getAgentVoiceCallStoreService()->findRunningCallOfConversation($carrierConversation) !== null
            ) {
                return true;
            }
            return isset($ownerAccount->id)
                && $this->getAgentVoiceCallsService()->findOpenAppCallForAccountId((int)$ownerAccount->id) instanceof AgentVoiceCall;
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /**
     * Whether the voice channel is available to this owner at all: the admission does not answer
     * {@see AgentVoiceCallRefusal::CODE_FEATURE_NOT_AVAILABLE}. A used-up budget or daily limit leaves the channel
     * included; the ring itself then refuses with that reason.
     */
    protected function isAppIncluded(Account $ownerAccount, ?AgentSpace $agentSpace, ?AIConversation $conversation): bool
    {
        return $this->findAdmissionRefusalCodeOfCallStart($ownerAccount, $agentSpace, $conversation) !== AgentVoiceCallRefusal::CODE_FEATURE_NOT_AVAILABLE;
    }

    /** The code the admission port refuses this call start with; null when the call may start. */
    protected function findAdmissionRefusalCodeOfCallStart(Account $ownerAccount, ?AgentSpace $agentSpace, ?AIConversation $conversation): ?string
    {
        /** @var AgentVoiceCallAdmissionService $agentVoiceCallAdmissionService */
        $agentVoiceCallAdmissionService = DDDService::instance()->getService(AgentVoiceCallAdmissionService::class);
        return $agentVoiceCallAdmissionService->findRefusalOfCallStart($ownerAccount, $agentSpace, $conversation)?->code;
    }

    /** Whether a ring would reach this owner right now on any ring target, without ringing anything. */
    protected function isAppRingable(Account $ownerAccount, ?AIConversation $ringConversation): bool
    {
        return $this->findReachableRingTargetNames($ownerAccount, $ringConversation) !== [];
    }

    /**
     * {@see startRing()} with the rights lifted: the ring writes a call and a conversation that the signed-in account
     * need not own, as on an application's ring route.
     *
     * @throws ForbiddenException when the admission refuses the ring
     */
    protected function startAppRing(
        Account $ownerAccount,
        ?AgentSpace $agentSpace,
        ?AIConversation $ringConversation,
        ?string $callTask
    ): AgentVoiceCall {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            return $this->startRing($ownerAccount, $agentSpace, $ringConversation, $callTask);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    protected function getAgentVoiceRingService(): AgentVoiceRingService
    {
        /** @var AgentVoiceRingService $agentVoiceRingService */
        $agentVoiceRingService = DDDService::instance()->getService(AgentVoiceRingService::class);
        return $agentVoiceRingService;
    }

    protected function getAgentVoiceCallStoreService(): AgentVoiceCallStoreService
    {
        /** @var AgentVoiceCallStoreService $agentVoiceCallStoreService */
        $agentVoiceCallStoreService = DDDService::instance()->getService(AgentVoiceCallStoreService::class);
        return $agentVoiceCallStoreService;
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
