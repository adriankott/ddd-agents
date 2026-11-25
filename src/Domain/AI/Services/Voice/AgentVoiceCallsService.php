<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Voice;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\OwnerDevice;
use DDD\Domain\AI\Entities\Conversations\SignalSources\PhoneConversationSignalSource;
use DDD\Domain\AI\Entities\Voice\AgentVoiceConfiguration;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCalls;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationPurposeRunsService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationRealtimeSignalService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\Realtime\AgentRealtimeChannelService;
use DDD\Domain\AI\Services\Voice\Providers\RealtimeVoiceProviderAdapter;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use Throwable;

/**
 * The life of a voice call after it exists: the state writes (answered, ended), the ONE end path, the request to hang
 * up after the agent's goodbye, the call markers and the transcript rows in the call's conversation, the conversation
 * a call without one runs on, and the sweep of calls a dead worker left running. The state transitions themselves are
 * pure methods of {@see AgentVoiceCall}.
 *
 * Every read and write of a call goes through {@see AgentVoiceCallStoreService}, so this service works on whichever
 * call store is bound. The finders keep the names their callers know and take ids; a caller that holds the entities asks the store
 * directly.
 *
 * The base knows one medium, the application's own window, and one provider family. An application with more adds its
 * own in a subclass at the same path. The override points:
 * - {@see applyApplicationValuesToNewCall()}: its own columns on a new call;
 * - {@see applyApplicationValuesToNewCallConversation()}: what it sets on the conversation of a call (a model, a
 *   budget exemption);
 * - {@see expireOverdueRingsOfAccount()}: the rings past their deadline that are ended when the account is looked at;
 * - {@see stopRingingCallBestEffort()}: stopping the ring of a further medium;
 * - {@see endFurtherLegsOfAnsweredCallBestEffort()}: a leg of an answered call beside its provider session;
 * - {@see hangUpProviderSession()}: the control call of a further provider;
 * - {@see getOwnerDeviceOfCallChannelType()}: the device a spoken sentence of a further medium came from;
 * - {@see getLifecycleSignalSource()}: the stored names of the call markers.
 *
 * @method AgentVoiceCall|null find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method AgentVoiceCalls findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 */
class AgentVoiceCallsService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = AgentVoiceCall::class;

    /**
     * @var int The least age at which a call still marked running counts as having lost its worker; the sweep closes
     *          it at {@see getRunningCallStaleAfterMinutes()}, which is longer when longer calls are allowed.
     */
    public const int RUNNING_CALL_STALE_AFTER_MINUTES = 120;

    /**
     * @var int Seconds a "hang up after the goodbye" request lives in the shared cache
     * ({@see requestCallEndAfterGoodbye()}). The session worker reads it within one of its ticks and hangs up at most
     * {@see \DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallGoodbyeHangUp::MAXIMUM_WAIT_MILLISECONDS_FOR_THE_ANSWER}
     * later; a request nobody reads (no worker on the call) only expires.
     */
    public const int END_AFTER_GOODBYE_REQUEST_TTL_SECONDS = 900;

    /** @var string The end reason of a call the sweep closed ({@see closeStaleRunningCalls()}) */
    public const string END_REASON_REAPED_STALE = 'reaped_stale';

    /**
     * The call of one provider session, found by the provider's id for it. Also the idempotency read: a session
     * announced more than once must find this call instead of creating a second one.
     */
    public function findCallByProviderCallId(string $providerCallId): ?AgentVoiceCall
    {
        return $this->getCallStore()->findCallByProviderCallId($providerCallId);
    }

    /** The calls one conversation carried, newest first, at most the list limit of the call store. */
    public function findCallsForConversation(AIConversation $conversation): AgentVoiceCalls
    {
        return $this->getCallStore()->findCallsOfConversation($conversation);
    }

    /**
     * The calls that were answered but never hung up and are older than {@see getRunningCallStaleAfterMinutes()}:
     * what {@see closeStaleRunningCalls()} closes.
     */
    public function findStaleRunningCalls(): AgentVoiceCalls
    {
        return $this->getCallStore()->findRunningCallsStartedBefore($this->getRunningCallStaleAfterMinutes());
    }

    /**
     * The minutes after its answer at which a call still marked running has lost its worker: twice the longest call
     * the configuration allows (`AI.agentVoice.limits.maximumCallSeconds`), and never less than
     * {@see self::RUNNING_CALL_STALE_AFTER_MINUTES}. A worker ends its own call at the configured maximum, so a call
     * that is twice as old is driven by nobody; the sweep can never end a call that still runs.
     */
    public function getRunningCallStaleAfterMinutes(): int
    {
        $longestCallMinutes = (int)ceil(AgentVoiceConfiguration::current()->maximumCallSeconds / 60);

        return max(static::RUNNING_CALL_STALE_AFTER_MINUTES, 2 * $longestCallMinutes);
    }

    /**
     * The open answered call of a conversation, answered at most the maximum call length ago: the owner-presence probe
     * of the agent context.
     */
    public function findOpenAnsweredCallOfAnyChannelForConversation(AIConversation $conversation): ?AgentVoiceCall
    {
        return $this->getCallStore()->findRunningCallOfConversation($conversation);
    }

    /** The same probe by conversation id: a delegation child probes the id of its root conversation. */
    public function findOpenAnsweredCallOfAnyChannelForConversationId(int $conversationId): ?AgentVoiceCall
    {
        return $this->getCallStore()->findRunningCallOfConversationId($conversationId);
    }

    /**
     * The call of the account that rings or runs now, if any: the "one call per account" gate (`open` answers
     * `call_already_running` from it).
     *
     * The account's rings past their deadline are ended first ({@see expireOverdueRingsOfAccount()}): every caller of
     * this check is a moment the account is looked at, and the end of a ring nobody answered then stands in its
     * conversation before the next call starts. A failed expiry never blocks the check.
     */
    public function findOpenAppCallForAccountId(int $accountId): ?AgentVoiceCall
    {
        $account = $this->findAccountById($accountId);
        if ($account === null) {
            return null;
        }
        try {
            $this->expireOverdueRingsOfAccount($account);
        } catch (Throwable $expiryError) {
            DDDService::instance()->getLogger()->warning(
                'AgentVoiceCallsService: lazy ring expiry failed for account ' . $accountId . ': ' . $expiryError->getMessage()
            );
        }

        return $this->getCallStore()->findOpenCallOfAccount($account);
    }

    /**
     * The calls of ONE account that ring or run right now, newest first. The account is a query filter, not left to
     * the read rights: it is the conversation owner the agent works for. Free of side effects: a ring past its
     * deadline is simply not open any more, and this read never runs a ring expiry, so the tool that lists calls stays
     * a pure read.
     */
    public function findOpenCallsOfAccountId(int $accountId): AgentVoiceCalls
    {
        $account = $this->findAccountById($accountId);

        return $account !== null ? $this->getCallStore()->findCallsOfAccount($account, true) : AgentVoiceCalls::newInstance();
    }

    /**
     * The call log of ONE account under the default query options of {@see AgentVoiceCalls} (the caller applies the
     * request's filters, top and skip first). The account is a query filter.
     */
    public function findCallsOfAccountId(int $accountId): AgentVoiceCalls
    {
        $account = $this->findAccountById($accountId);

        return $account !== null ? $this->getCallStore()->findCallsOfAccount($account, false) : AgentVoiceCalls::newInstance();
    }

    /** One call by id when it belongs to the account, else null: another account's call reads as missing. */
    public function findCallOfAccountIdById(int $callId, int $accountId): ?AgentVoiceCall
    {
        $account = $this->findAccountById($accountId);

        return $account !== null ? $this->getCallStore()->findCallOfAccount($callId, $account) : null;
    }

    /**
     * The call of a session the owner opens in the application's window. Such a call is INBOUND (the owner calls the
     * agent) and has no ring: who is talking is known from the signed-in account. `startedAt` stays empty until the
     * provider accepted the session ({@see persistCallAnswered()}): a call that never connected must not trip the
     * "one call per account" gate. The provider is pinned at creation, so that attaching and hanging up resolve to the
     * adapter the session is created on.
     *
     * Without `$conversation` the call gets a new one ({@see createConversationForAppCall()}); when that fails the call
     * exists without a conversation and has no transcript.
     */
    public function createCallOfWindowSession(Account $account, ?AgentSpace $agentSpace, ?AIConversation $conversation): AgentVoiceCall
    {
        $agentVoiceCall = AgentVoiceCall::newInstance();
        $agentVoiceCall->direction = AgentVoiceCall::DIRECTION_INBOUND;
        $agentVoiceCall->accountId = (int)$account->id;
        $defaultProviderName = AgentVoiceConfiguration::current()->defaultProviderName;
        $agentVoiceCall->voiceProvider = $defaultProviderName !== '' ? $defaultProviderName : null;
        $this->applyApplicationValuesToNewCall($agentVoiceCall, $account, $agentSpace);
        if ($conversation === null) {
            $conversation = $this->createConversationForAppCall($agentVoiceCall, $account, $agentSpace);
        }
        if ($conversation?->id !== null) {
            $agentVoiceCall->aiConversationId = (int)$conversation->id;
        }

        return $this->getCallStore()->createCall($agentVoiceCall);
    }

    /**
     * Stamps the moment the call was ANSWERED. Only from here on does the call count as running, so a call that only
     * ever rang is never picked up by {@see findStaleRunningCalls()}.
     */
    public function persistCallAnswered(AgentVoiceCall $agentVoiceCall): void
    {
        $this->getCallStore()->markCallStarted($agentVoiceCall);
        // A call carries its conversation from creation: the call start is marked in the chat right away.
        $this->persistCallLifecycleSignalMessage($agentVoiceCall, callEnded: false);
    }

    /** Stamps the provider's id of the session onto a call that was created before its session existed. */
    public function persistProviderCallIdForOutboundCall(AgentVoiceCall $agentVoiceCall, string $providerCallId): void
    {
        $this->getCallStore()->setProviderCallIdOfCall($agentVoiceCall, $providerCallId);
    }

    /**
     * Ends a call nobody answered EXACTLY ONCE: the conditional claim on the call ({@see claimUnansweredCallEnd()}:
     * neither answered nor ended yet) decides the one writer, which then writes the end with its marker
     * ({@see persistUnansweredCallEnd()}). True for the writer.
     */
    public function persistUnansweredCallEndOnce(AgentVoiceCall $agentVoiceCall, string $endReason): bool
    {
        if (!$this->claimUnansweredCallEnd($agentVoiceCall, $endReason)) {
            return false;
        }
        $this->persistUnansweredCallEnd($agentVoiceCall, $endReason);

        return true;
    }

    /**
     * The end of a call nobody answered, written by the ONE caller that won its end claim: the call-end write with its
     * marker ({@see persistCallEnd()}), then, for a call the agent itself started and the owner did not refuse, the
     * agent's ONE follow-up turn ({@see AgentVoiceCallFollowUpsService::startFollowUpTurnIfAgentCallWentUnanswered()}).
     */
    public function persistUnansweredCallEnd(AgentVoiceCall $agentVoiceCall, string $endReason): void
    {
        $this->persistCallEnd($agentVoiceCall, $endReason);
        $this->startFollowUpTurnIfAgentCallWentUnanswered($agentVoiceCall);
    }

    /** Records the hang-up and why it happened, and marks the call end in the call's conversation. */
    public function persistCallEnd(AgentVoiceCall $agentVoiceCall, string $endReason): void
    {
        $this->getCallStore()->markCallEnded($agentVoiceCall, $endReason);
        $this->persistCallLifecycleSignalMessage($agentVoiceCall, callEnded: true);
    }

    /**
     * Ends a call NOW: the one hang-up of a call from outside its session worker (the `hangUp` route and the agent's
     * `endVoiceCall` from a chat). In order:
     * 1. the call: one nobody answered ends through the claimed unanswered end ({@see persistUnansweredCallEndOnce()}:
     *    exactly once against a ring deadline or a decline at the same moment; `$endReason` decides whether the agent
     *    follows up, COMPLETED and ENDED_BY_AGENT never do), an answered one through {@see persistCallEnd()}; both
     *    write the call-end marker into the call's conversation. A call that already ended is left as it is;
     * 2. a call that was still RINGING stops ringing ({@see stopRingingCallBestEffort()});
     * 3. the provider session is hung up ({@see hangUpProviderSessionBestEffort()}), also for a call that already
     *    ended, so a repeated hang-up can finish what a lost control call could not. The session worker's own check
     *    for an end from outside is the backstop of every best-effort step.
     *
     * True when THIS call ended it.
     */
    public function endCallNow(AgentVoiceCall $agentVoiceCall, string $endReason): bool
    {
        $endedTheCall = false;
        if ($agentVoiceCall->endedAt === null) {
            if ($agentVoiceCall->startedAt === null) {
                $endedTheCall = $this->persistUnansweredCallEndOnce($agentVoiceCall, $endReason);
                if ($endedTheCall) {
                    $this->stopRingingCallBestEffort($agentVoiceCall);
                }
            } else {
                $this->persistCallEnd($agentVoiceCall, $endReason);
                $endedTheCall = true;
                $this->endFurtherLegsOfAnsweredCallBestEffort($agentVoiceCall);
            }
        }
        $this->hangUpProviderSessionBestEffort($agentVoiceCall);

        return $endedTheCall;
    }

    /**
     * Hangs up the provider session of a call. Never throws; a call without a provider id (a ring nobody answered) has
     * no session.
     */
    public function hangUpProviderSessionBestEffort(AgentVoiceCall $agentVoiceCall): void
    {
        if ($agentVoiceCall->providerCallId === null) {
            return;
        }
        try {
            $this->hangUpProviderSession($agentVoiceCall);
        } catch (Throwable) {
            // The control call did not get through: the session worker's check for an end from outside closes the session.
        }
    }

    /**
     * Asks the session worker of a RUNNING call to hang up once the agent's goodbye has been spoken: `endVoiceCall` on
     * the call the agent is on. The tool runs inside the call's own agent turn, whose answer (the goodbye) is spoken
     * only after the turn ended, so an immediate hang-up would cut it off. The request is an entry of the shared cache
     * under the call id (no column: the worker reads it on every tick, {@see findRequestedCallEndAfterGoodbye()}); the
     * worker then waits until the answer was handed to the voice model and the voice went quiet
     * ({@see \DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallGoodbyeHangUp}) and ends the call with `$endReason`.
     *
     * @throws Throwable when there is no shared cache or it refuses the write; the caller then ends the call at once
     */
    public function requestCallEndAfterGoodbye(AgentVoiceCall $agentVoiceCall, string $endReason): void
    {
        $sharedCache = $this->getSharedCache();
        if ($sharedCache === null) {
            throw new InternalErrorException('shared_cache_unavailable');
        }
        $sharedCache->set(
            static::buildEndAfterGoodbyeCacheKey((int)$agentVoiceCall->id),
            $endReason,
            static::END_AFTER_GOODBYE_REQUEST_TTL_SECONDS
        );
    }

    /** The end reason a "hang up after the goodbye" request names for this call; null when none is pending. Never throws. */
    public function findRequestedCallEndAfterGoodbye(AgentVoiceCall $agentVoiceCall): ?string
    {
        if ($agentVoiceCall->id === null) {
            return null;
        }
        try {
            $requestedEndReason = $this->getSharedCache()?->get(static::buildEndAfterGoodbyeCacheKey((int)$agentVoiceCall->id));
        } catch (Throwable) {
            return null;
        }

        return is_string($requestedEndReason) && $requestedEndReason !== '' ? $requestedEndReason : null;
    }

    /** Removes a "hang up after the goodbye" request once the worker acted on it. Never throws. */
    public function clearRequestedCallEndAfterGoodbye(AgentVoiceCall $agentVoiceCall): void
    {
        if ($agentVoiceCall->id === null) {
            return;
        }
        try {
            $this->getSharedCache()?->delete(static::buildEndAfterGoodbyeCacheKey((int)$agentVoiceCall->id));
        } catch (Throwable) {
            // the entry expires on its own
        }
    }

    /**
     * The conversation a call the owner opened runs on when the client attached no existing chat. Who is talking is
     * known before the call exists, so the conversation is created right at session open, owned by `$account` and
     * working for `$agentSpace` (none = the account as a whole). Best-effort: a failed creation never blocks the call,
     * it then simply has no transcript; null on failure, the error in the log.
     */
    public function createConversationForAppCall(AgentVoiceCall $agentVoiceCall, Account $account, ?AgentSpace $agentSpace): ?AIConversation
    {
        return $this->createCallConversation($agentVoiceCall, $account, $agentSpace);
    }

    /**
     * A NEW call conversation that carries a purpose made for calls (one that names a spoken call task): the same
     * conversation as that of any call, WITHOUT a seeded task message (the spoken task rides the call) and WITH the
     * purpose attached ({@see AIConversationPurposeRunsService::attachPurposeToPhoneConversation()}: seeded plan,
     * preloaded skill, no kickoff turn). Best-effort like every call conversation: null on failure, the error in the
     * log.
     */
    public function createPurposedCallConversation(Account $ownerAccount, ?AgentSpace $agentSpace, string $purposeName): ?AIConversation
    {
        $conversation = $this->createCallConversation(null, $ownerAccount, $agentSpace);
        if ($conversation === null) {
            return null;
        }
        try {
            /** @var AIConversationPurposeRunsService $aIConversationPurposeRunsService */
            $aIConversationPurposeRunsService = DDDService::instance()->getService(AIConversationPurposeRunsService::class);
            $aIConversationPurposeRunsService->attachPurposeToPhoneConversation($conversation, $purposeName);
        } catch (Throwable $purposeAttachError) {
            DDDService::instance()->getLogger()->error(
                "AgentVoiceCallsService: attaching the purpose {$purposeName} to call conversation {$conversation->id} failed: "
                . $purposeAttachError->getMessage()
            );

            return null;
        }

        return $conversation;
    }

    /**
     * One spoken sentence into the call's conversation: the owner's speech as a USER message, the agent's speech as an
     * ASSISTANT message, appended through the conversation's own `appendMessage()` (index, skill carry-forward) and
     * persisted at once, so the transcript grows while the call runs. Returns the persisted row: its
     * `indexInConversation` is what the conversation trace links the sentence to. `$callChannelType` is the call's
     * {@see AgentVoiceCall::getChannelType()}: it decides the device the owner's sentence carries
     * ({@see getOwnerDeviceOfCallChannelType()}).
     */
    public function persistTranscriptMessage(
        AIConversation $conversation,
        string $role,
        string $spokenSentence,
        string $callChannelType = AgentVoiceCall::CHANNEL_TYPE_APP
    ): AIConversationMessage {
        $transcriptMessage = AIConversationMessage::newInstance();
        $conversation->appendMessage(
            $transcriptMessage,
            AIConversationMessage::MESSAGE_TEXT,
            $spokenSentence,
            role: $role,
            authorType: $role === AIConversationMessage::ROLE_USER
                ? AIConversationMessage::AUTHOR_USER
                : AIConversationMessage::AUTHOR_ASSISTANT
        );
        if ($role === AIConversationMessage::ROLE_USER) {
            $transcriptMessage->ownerDevice = $this->getOwnerDeviceOfCallChannelType($callChannelType);
        }
        $transcriptMessage->dropCarriedLoadedCapabilities();
        // Under the conversation's index lock (never a bare upsert): a turn worker writes the same conversation while
        // the owner speaks, and an unlocked upsert on the index it just took would merge the sentence into its row.
        $this->getConversationMessagesService()->persistInboundRowIndexSafe($conversation, $transcriptMessage);
        // The owner's SPOKEN row is marked as a voice transcript on the conversation's working state: it was persisted
        // outside the loop and never screened by the turn-start injection check, so an intake of owner input releases
        // it only once the agent answered; a row the owner TYPED during the call is not marked and waits for its
        // verdict. Best-effort: a marker failure never blocks the transcript.
        if ($role === AIConversationMessage::ROLE_USER && isset($conversation->id, $transcriptMessage->indexInConversation)) {
            try {
                $agentWorkingState = $conversation->getOrCreateAgentWorkingState();
                $agentWorkingState->markOwnerMessageAsVoiceTranscript((int)$transcriptMessage->indexInConversation);
                $this->getConversationsService()->persistAgentWorkingStateSlice($conversation, 'voiceTranscriptOwnerMessageIndexes');
            } catch (AgentRunClaimLostException $claimLost) {
                throw $claimLost; // another worker drives the conversation now: never swallowed as a marker failure
            } catch (Throwable $markerError) {
                DDDService::instance()->getLogger()->warning(
                    "Voice transcript marker failed for conversation {$conversation->id}: " . $markerError->getMessage()
                );
            }
        }
        // Change signal, best-effort: without it an open conversation view stands still while a call is pure speech
        // (only agent turns publish it otherwise).
        $this->publishConversationChangeSignalBestEffort($conversation);

        return $transcriptMessage;
    }

    /**
     * Closes the calls that are still marked running long after they were answered: such a call has lost its session
     * worker (the worker's time limit counts CPU time, which a sleeping event loop never spends, so this sweep is the
     * real hard stop). The provider is asked to end the session first (a live voice session is charged per second,
     * silence included, and with the worker gone nobody else sends the hang-up), then the call is ended as
     * {@see self::END_REASON_REAPED_STALE}; the conversation needs nothing, its transcript is stored per sentence.
     *
     * Only the calls of THIS environment's conversations (a call carries no environment of its own): the sweep of one
     * environment must not hang up a call another one is driving. A call without a conversation has no owner; the
     * production environment adopts it.
     *
     * An application runs this from its stale-session sweep. Returns the calls it closed.
     *
     * @return AgentVoiceCall[]
     */
    public function closeStaleRunningCalls(): array
    {
        $staleRunningCalls = $this->findStaleRunningCalls()->getElements();
        $conversationIdsOfStaleCalls = [];
        foreach ($staleRunningCalls as $staleRunningCall) {
            if ($staleRunningCall->aiConversationId !== null) {
                $conversationIdsOfStaleCalls[] = (int)$staleRunningCall->aiConversationId;
            }
        }
        $ownConversationIds = array_flip(
            $this->getConversationsService()->keepConversationIdsOfCurrentEnvironment($conversationIdsOfStaleCalls)
        );
        $sweepRunsInProduction = $this->isProductionEnvironment();
        $closedCalls = [];
        foreach ($staleRunningCalls as $staleRunningCall) {
            $callConversationId = $staleRunningCall->aiConversationId;
            if ($callConversationId === null ? !$sweepRunsInProduction : !isset($ownConversationIds[$callConversationId])) {
                continue;
            }
            if ($staleRunningCall->providerCallId !== null) {
                try {
                    $this->hangUpProviderSession($staleRunningCall);
                } catch (Throwable $hangUpError) {
                    // reported, and the call is closed anyway
                    DDDService::instance()->getLogger()->warning(
                        'AgentVoiceCallsService: provider hang-up failed for stale call ' . $staleRunningCall->id . ': ' . $hangUpError->getMessage()
                    );
                }
            }
            $this->persistCallEnd($staleRunningCall, static::END_REASON_REAPED_STALE);
            $closedCalls[] = $staleRunningCall;
        }

        return $closedCalls;
    }

    /** The end claim of a call nobody answered ({@see AgentVoiceCallStoreService::claimUnansweredCallEnd()}). */
    protected function claimUnansweredCallEnd(AgentVoiceCall $agentVoiceCall, string $endReason): bool
    {
        return $this->getCallStore()->claimUnansweredCallEnd($agentVoiceCall, $endReason);
    }

    /** {@see AgentVoiceCallFollowUpsService::startFollowUpTurnIfAgentCallWentUnanswered()} */
    protected function startFollowUpTurnIfAgentCallWentUnanswered(AgentVoiceCall $agentVoiceCall): void
    {
        /** @var AgentVoiceCallFollowUpsService $agentVoiceCallFollowUpsService */
        $agentVoiceCallFollowUpsService = DDDService::instance()->getService(AgentVoiceCallFollowUpsService::class);
        $agentVoiceCallFollowUpsService->startFollowUpTurnIfAgentCallWentUnanswered($agentVoiceCall);
    }

    /**
     * Step 2 of {@see endCallNow()} for a call that ended while it rang. Never throws. The base stops the ring on the
     * owner's open clients; a subclass with a further medium stops its own ring here.
     */
    protected function stopRingingCallBestEffort(AgentVoiceCall $agentVoiceCall): void
    {
        if ($agentVoiceCall->direction !== AgentVoiceCall::DIRECTION_OUTBOUND) {
            return; // a call the owner opened is accepted at once: nobody rings on the agent's side
        }
        try {
            $this->cancelAppRingsOfCallEndedBeforeAnswer($agentVoiceCall);
        } catch (Throwable $ringCancelFailure) {
            DDDService::instance()->getLogger()->warning(
                'AgentVoiceCallsService: ring of call ' . $agentVoiceCall->id . ' not cancelled at its end: '
                . $ringCancelFailure->getMessage()
            );
        }
    }

    /**
     * Takes the ring of a call that ended before anyone answered off the owner's open clients
     * ({@see AgentVoiceRingService::cancelRingsOfCallEndedBeforeAnswer()}).
     */
    protected function cancelAppRingsOfCallEndedBeforeAnswer(AgentVoiceCall $agentVoiceCall): void
    {
        /** @var AgentVoiceRingService $agentVoiceRingService */
        $agentVoiceRingService = DDDService::instance()->getService(AgentVoiceRingService::class);
        $agentVoiceRingService->cancelRingsOfCallEndedBeforeAnswer($agentVoiceCall);
    }

    /**
     * A leg of an answered call beside its provider session, ended at {@see endCallNow()}. The base has none: a call in
     * the application's window is its provider session and nothing else. Never throws.
     */
    protected function endFurtherLegsOfAnsweredCallBestEffort(AgentVoiceCall $agentVoiceCall): void
    {
    }

    /**
     * The control call that ends the provider session of `$agentVoiceCall`, sent through the adapter of the provider
     * pinned on the call ({@see RealtimeVoiceProviderAdapter::forCall()}). It is a plain request to the provider: it
     * needs neither the session worker nor its WebSocket library, so a call is hung up from every process. May throw:
     * the callers decide what a failed control call means.
     */
    protected function hangUpProviderSession(AgentVoiceCall $agentVoiceCall): void
    {
        RealtimeVoiceProviderAdapter::forCall($agentVoiceCall)->hangUpSession((string)$agentVoiceCall->providerCallId);
    }

    /**
     * Ends the rings of ONE account nobody answered before their deadline, before its open call is read
     * ({@see AgentVoiceRingService::expireOverdueRingsOfAccount()}): each such call ends as expired through the one end
     * path and its ring is taken off the owner's clients. The delayed message of a ring does the same at its deadline;
     * this is the look that does not depend on that message arriving. An application with rings of its own (a ring on
     * a device) expires them here too. May throw: the caller logs it and goes on.
     */
    protected function expireOverdueRingsOfAccount(Account $account): void
    {
        /** @var AgentVoiceRingService $agentVoiceRingService */
        $agentVoiceRingService = DDDService::instance()->getService(AgentVoiceRingService::class);
        $agentVoiceRingService->expireOverdueRingsOfAccount($account);
    }

    /**
     * What an application sets on a new call beside the base's columns (the columns of its subclass of the call). The
     * base sets nothing.
     */
    protected function applyApplicationValuesToNewCall(AgentVoiceCall $agentVoiceCall, Account $account, ?AgentSpace $agentSpace): void
    {
    }

    /**
     * What an application sets on the new conversation of a call before it is stored (a model for its calls, an
     * exemption from its budget). The base sets nothing. `$agentVoiceCall` is null for a conversation created before
     * its call ({@see createPurposedCallConversation()}).
     */
    protected function applyApplicationValuesToNewCallConversation(AIConversation $conversation, ?AgentVoiceCall $agentVoiceCall): void
    {
    }

    /** The shared construction of every call conversation. Best-effort: null on failure, the error in the log. */
    protected function createCallConversation(?AgentVoiceCall $agentVoiceCall, Account $account, ?AgentSpace $agentSpace): ?AIConversation
    {
        try {
            $conversation = AIConversation::newInstance();
            $conversation->accountId = (int)$account->id;
            // a space that is not stored throws instead of being stored as "no space"
            $conversation->agentSpaceId = AgentSpace::getIdOfStoredAgentSpace($agentSpace);
            $conversation->initiatorType = AIConversation::INITIATOR_TYPE_PHONE_CALL;
            $conversation->messages = AIConversationMessages::newInstance();
            $this->applyApplicationValuesToNewCallConversation($conversation, $agentVoiceCall);
            $conversation->update(0); // stored now, so that the transcript rows can be written against its id

            // The episodic history at creation: the call conversation carries the narrative across sessions from its
            // first message on. Best-effort.
            try {
                $this->getConversationsService()->composeAndPersistEpisodicHistory($conversation);
            } catch (Throwable $episodicHistoryError) {
                DDDService::instance()->getLogger()->error(
                    "AgentVoiceCallsService: episodic history failed for call conversation {$conversation->id}: "
                    . $episodicHistoryError->getMessage()
                );
            }

            return $conversation;
        } catch (Throwable $conversationCreationError) {
            DDDService::instance()->getLogger()->error(
                'AgentVoiceCallsService: conversation creation failed for call ' . ($agentVoiceCall?->providerCallId ?? '(none yet)') . ': '
                . $conversationCreationError->getMessage()
            );

            return null;
        }
    }

    /**
     * The call-start or call-end marker as an out-of-band SIGNAL in the call's conversation: the chat renders it as a
     * call marker, the agent reads it as context ("a call is running" / "nobody is on the line any more"). It owes no
     * turn. BEST-EFFORT: the END fires from the session worker or a route while a turn may be RUNNING on the same index
     * sequence, so the row is written under the conversation's index lock (`persistInboundRowIndexSafe()` re-anchors a
     * taken index); a lock miss is retried once; a marker that still fails must never break the call teardown.
     */
    protected function persistCallLifecycleSignalMessage(AgentVoiceCall $agentVoiceCall, bool $callEnded): void
    {
        if ($agentVoiceCall->aiConversationId === null) {
            return;
        }
        $lifecycleSignalText = $agentVoiceCall->buildLifecycleSignalText($callEnded);
        $lifecycleSignalSource = $this->getLifecycleSignalSource($callEnded);
        for ($appendAttempt = 0; $appendAttempt < 2; $appendAttempt++) {
            try {
                $freshConversation = AIConversation::getService()->find((int)$agentVoiceCall->aiConversationId, false);
                if (!$freshConversation instanceof AIConversation) {
                    return;
                }
                // The marker goes at the END: the registry of a long-lived worker still holds the message set from the
                // call's start, and an append on that stale set would land the end marker right after the start marker.
                // So the rows are read again before the index is taken: the bounded tail above the compaction cover
                // (the insert re-anchors its index anyway).
                $freshConversation->messages = $this->getConversationMessagesService()->findContextTailMessages($freshConversation);
                $lifecycleSignalMessage = AIConversationMessage::newInstance();
                $freshConversation->appendMessage(
                    $lifecycleSignalMessage,
                    AIConversationMessage::MESSAGE_SIGNAL,
                    $lifecycleSignalText,
                    role: AIConversationMessage::ROLE_SYSTEM,
                    signalSource: $lifecycleSignalSource
                );
                $lifecycleSignalMessage->dropCarriedLoadedCapabilities();
                $this->getConversationMessagesService()->persistInboundRowIndexSafe($freshConversation, $lifecycleSignalMessage);
                // Change signal, best-effort: the open chat view renders the marker without a reload.
                $this->publishConversationChangeSignalBestEffort($freshConversation);

                return;
            } catch (Throwable $lifecycleSignalError) {
                // A collision on (conversation, index) means a concurrent turn took the index: read again and retry
                // once; what persists is logged and swallowed (a best-effort marker).
                if ($appendAttempt === 1) {
                    DDDService::instance()->getLogger()->error(
                        'AgentVoiceCallsService: call lifecycle signal failed for call '
                        . $agentVoiceCall->id . ': ' . $lifecycleSignalError->getMessage()
                    );
                }
            }
        }
    }

    /**
     * The signal source a call marker is stored with. Stored values: an application with rows of an earlier spelling
     * returns its own.
     */
    protected function getLifecycleSignalSource(bool $callEnded): string
    {
        return $callEnded
            ? PhoneConversationSignalSource::PHONE_CALL_ENDED->value
            : PhoneConversationSignalSource::PHONE_CALL_STARTED->value;
    }

    /**
     * The device the owner's spoken sentence came from, for a call of `$callChannelType`. The base knows the
     * application's window: the owner keeps the screen during the call.
     */
    protected function getOwnerDeviceOfCallChannelType(string $callChannelType): OwnerDevice
    {
        return OwnerDevice::inAppVoiceCall();
    }

    /** Tells the open views of `$conversation` that it changed. Never throws: a dead signal never blocks a write. */
    protected function publishConversationChangeSignalBestEffort(AIConversation $conversation): void
    {
        try {
            /** @var AIConversationRealtimeSignalService $aIConversationRealtimeSignalService */
            $aIConversationRealtimeSignalService = DDDService::instance()->getService(AIConversationRealtimeSignalService::class);
            $aIConversationRealtimeSignalService->publishChangeSignal($conversation, AIConversationRealtimeSignalService::STATUS_IDLE);
        } catch (Throwable) {
            // nothing to do: the view catches up with its next read
        }
    }

    protected static function buildEndAfterGoodbyeCacheKey(int $callId): string
    {
        return 'voice-call-end-after-goodbye-' . $callId;
    }

    /** Whether this process runs in production, which adopts a stale call that has no conversation. */
    protected function isProductionEnvironment(): bool
    {
        return AIConversationsService::currentEnvironment() === AIConversationsService::ENVIRONMENT_PRODUCTION;
    }

    /**
     * The account with this id, read without the entity rights: the callers resolved the account themselves (a route
     * from the signed-in account, a tool from the owner of its conversation, a worker without a signed-in account).
     */
    protected function findAccountById(int $accountId): ?Account
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $account = Account::getService()->find($accountId);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }

        return $account instanceof Account ? $account : null;
    }

    /** The cache the web requests and the workers share: the one the agent's realtime port uses. */
    protected function getSharedCache(): ?Cache
    {
        /** @var AgentRealtimeChannelService $agentRealtimeChannelService */
        $agentRealtimeChannelService = DDDService::instance()->getService(AgentRealtimeChannelService::class);

        return $agentRealtimeChannelService->getSharedCache();
    }

    protected function getCallStore(): AgentVoiceCallStoreService
    {
        /** @var AgentVoiceCallStoreService $agentVoiceCallStoreService */
        $agentVoiceCallStoreService = DDDService::instance()->getService(AgentVoiceCallStoreService::class);

        return $agentVoiceCallStoreService;
    }

    protected function getConversationsService(): AIConversationsService
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = AIConversation::getService();

        return $aIConversationsService;
    }

    protected function getConversationMessagesService(): AIConversationMessagesService
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);

        return $aIConversationMessagesService;
    }
}
