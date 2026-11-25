<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Voice;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\ClientTools\AgentClientPresence;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeAudience;
use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeMessage;
use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent;
use DDD\Domain\AI\Entities\Voice\AgentVoiceConfiguration;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceRingCallerContext;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceRingPayload;
use DDD\Domain\AI\Listeners\Conversations\AIConversationTurnStartListener;
use DDD\Domain\AI\Services\Conversations\Realtime\AgentRealtimeChannelService;
use DDD\Domain\AI\Services\Conversations\TraceEvents\AIConversationTraceEventsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ConflictException;
use DDD\Infrastructure\Exceptions\GoneException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Libs\JWTPayload;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use Throwable;

/**
 * The ring of a call on the owner's open clients: ONE message of the type
 * {@see AgentVoiceRealtimeMessageTypeProviderService::MESSAGE_TYPE_RING} on the agent's realtime port
 * ({@see AgentRealtimeChannelService}), sent to one account, keyed by the call id and expiring at the call's ring
 * deadline. There is no ring row and no table: the call is the state of its ring.
 *
 * - WHO IS RUNG: one account, the account of the call. A call that rings is created for the account its conversation
 *   belongs to; without a conversation, or one without an account, for the account the ring was asked for
 *   ({@see resolveRingTargetAccountId()}). Every open client of that account shows the ring, so a user only ever sees
 *   their own rings, and the client that answers finds the call as a call of its own account.
 * - THE LISTENER CHECK ({@see isAnyClientOfAccountListening()}) is the realtime port's answer and fails closed.
 * - THE ANSWER TOKEN ({@see mintAnswerToken()}) rides the message: `{expiresUnix}.{hmac}`, bound to one call and
 *   expiring with the ring deadline; opaque to the client. Single use is the store's atomic answer claim: exactly one
 *   client answers ({@see answerRing()}), every other one is told `already_answered`. The client that won is
 *   remembered beside the call for the session open that follows ({@see isCallAnsweredByClient()}).
 * - A DECLINE in one client stops the ring in every client, because they share the one ring ({@see declineRing()});
 *   the call then ends DECLINED.
 * - EXPIRY: a ring nobody answered ends the call EXPIRED once its deadline passed ({@see expireOverdueRingOfCall()}):
 *   through the delayed deadline message of the ring; lazily whenever its account is looked at
 *   ({@see expireOverdueRingsOfAccount()}: before a new ring and before the open-call check of that account); and at
 *   the turn start of the call's conversation ({@see beforeTurnStarts()}), so the model of that turn reads that the
 *   owner did not pick up. The three meet in the one end claim of the call, so the end is written once.
 * - The message is CLEARED when a client answered or declined, when the deadline passed and when the call was
 *   withdrawn before an answer ({@see cancelRingsOfCallEndedBeforeAnswer()}). The clients also stop on their own at
 *   `ringExpiresAt`; clearing is the fast path.
 *
 * Every call of the realtime port here is best effort: a failure is logged and never breaks the call.
 *
 * Override points of an application's subclass at the same path: {@see buildCallerContext()} (what the application
 * tells the ringing client about who calls), {@see getCallerLabelKey()}, {@see getAnswerTokenSecret()}, and
 * {@see clearRing()} for an application that stops a ring of its own on a further ring target at the same moments.
 */
class AgentVoiceRingService extends Service implements AIConversationTurnStartListener
{
    /** @var string What the signature of an answer token is bound to besides the call and the expiry */
    public const string ANSWER_TOKEN_PURPOSE = 'agent-voice-answer';

    /** @var string The prefix of the shared cache key the answering client of a call is remembered under */
    public const string ANSWERING_CLIENT_CACHE_KEY_PREFIX = 'agent-voice-answering-client-';

    /** @var int How long the client that answered a ring is remembered beside the call, for the session open that follows */
    public const int ANSWERING_CLIENT_REMEMBERED_SECONDS = 300;

    /** @var string The trace event of the ring message sent to the open clients (the stored kind of the running installations) */
    public const string TRACE_EVENT_RING_WRITTEN = 'ring.browser_tab_ring_written';

    /** @var string The trace event of the answer claim a client won */
    public const string TRACE_EVENT_ANSWER_CLAIMED = 'call.answer_claimed';

    /**
     * @var string The text key of the caller line for a call the agent started, as the package's web half
     * renders it: a key of its text manifest, sent unrendered; the client fills `%agentName%` and translates
     */
    public const string CALLER_LABEL_KEY_OF_AGENT_STARTED_CALL = '%agentName% wants to talk';

    /**
     * The ONE account a new ring goes to, decided before its call exists: the account the conversation belongs to (the
     * account that started the conversation). Without a conversation, or one without an account, `$requestedAccountId`:
     * the account a ring that creates its conversation creates it for, so both branches name the same user. Null only
     * when neither is known: then nobody is rung. The call is created for this account.
     */
    public function resolveRingTargetAccountId(?AIConversation $aiConversation, ?int $requestedAccountId): ?int
    {
        $conversationAccountId = $aiConversation?->accountId ?? null;
        if ($conversationAccountId !== null && $conversationAccountId > 0) {
            return (int)$conversationAccountId;
        }
        return $requestedAccountId !== null && $requestedAccountId > 0 ? $requestedAccountId : null;
    }

    /**
     * The rung account of an EXISTING call: its own account, the one it was created for
     * ({@see resolveRingTargetAccountId()}). What every clear uses, so the message is removed from exactly the mailbox
     * it was sent to.
     */
    public function findRingTargetAccountId(AgentVoiceCall $agentVoiceCall): ?int
    {
        return $agentVoiceCall->accountId !== null && $agentVoiceCall->accountId > 0 ? (int)$agentVoiceCall->accountId : null;
    }

    /**
     * Whether at least one client of this account listens right now, as the realtime port answers it. Fails CLOSED: a
     * failed read means "no client", so a ring is never promised to a client nobody can see.
     */
    public function isAnyClientOfAccountListening(?int $targetAccountId): bool
    {
        if ($targetAccountId === null) {
            return false;
        }
        try {
            return $this->getAgentRealtimeChannelService()->isClientListening(AgentRealtimeAudience::forAccount($targetAccountId));
        } catch (Throwable $listenerReadFailure) {
            DDDService::instance()->getLogger()->warning(
                'AgentVoiceRingService: listener read failed for account ' . $targetAccountId . ': ' . $listenerReadFailure->getMessage()
            );
            return false;
        }
    }

    /**
     * Sends the ring of `$agentVoiceCall` to the open clients of the call's account. Returns whether the message is
     * reachable for a client (carried by the host's transport or kept in the account's mailbox): the twin of "the
     * ring was delivered" for this ring target. False for a call without an account.
     */
    public function publishRing(AgentVoiceCall $agentVoiceCall, ?AgentSpace $agentSpace = null): bool
    {
        $targetAccountId = $this->findRingTargetAccountId($agentVoiceCall);
        if ($targetAccountId === null) {
            return false;
        }
        try {
            $ringPayloadJson = $this->buildRingPayload($agentVoiceCall, $this->mintAnswerToken($agentVoiceCall), $agentSpace)
                ->toPayloadJson();
            $agentRealtimeSendResult = $this->getAgentRealtimeChannelService()->sendMessage(AgentRealtimeMessage::create(
                AgentVoiceRealtimeMessageTypeProviderService::MESSAGE_TYPE_RING,
                (string)(int)$agentVoiceCall->id,
                $ringPayloadJson,
                DateTime::fromTimestamp($this->getRingDeadlineTimestamp($agentVoiceCall)),
                AgentRealtimeAudience::forAccount($targetAccountId)
            ));
            return $agentRealtimeSendResult->isReachable();
        } catch (Throwable $ringSendFailure) {
            DDDService::instance()->getLogger()->warning(
                'AgentVoiceRingService: ring message of call ' . $agentVoiceCall->id . ' was not sent: ' . $ringSendFailure->getMessage()
            );
            return false;
        }
    }

    /**
     * Removes the ring message of the call, so every open client stops ringing. Called when the ring ends for the
     * clients: an answer, a decline, the deadline, a withdrawal. A call without an id or without an account has no
     * message. Never throws.
     */
    public function clearRing(AgentVoiceCall $agentVoiceCall): void
    {
        $targetAccountId = $this->findRingTargetAccountId($agentVoiceCall);
        if (($agentVoiceCall->id ?? null) === null || $targetAccountId === null) {
            return;
        }
        try {
            $this->getAgentRealtimeChannelService()->clearMessage(
                AgentRealtimeAudience::forAccount($targetAccountId),
                AgentVoiceRealtimeMessageTypeProviderService::MESSAGE_TYPE_RING,
                (string)(int)$agentVoiceCall->id
            );
        } catch (Throwable $ringClearFailure) {
            DDDService::instance()->getLogger()->warning(
                'AgentVoiceRingService: ring clear failed for call ' . $agentVoiceCall->id . ': ' . $ringClearFailure->getMessage()
            );
        }
    }

    /**
     * The payload of the ring message. Every value is what a client needs to show the ring and to answer it (`answer`
     * with the token, then the session open with the call id); nothing of the conversation's content.
     * The caller context is what the application fills ({@see buildCallerContext()}), empty in the base. The document
     * a client reads is rendered by {@see AgentVoiceRingPayload::toPayloadJson()}.
     */
    public function buildRingPayload(AgentVoiceCall $agentVoiceCall, string $answerToken, ?AgentSpace $agentSpace = null): AgentVoiceRingPayload
    {
        return AgentVoiceRingPayload::create(
            (int)$agentVoiceCall->id,
            $agentVoiceCall->aiConversationId !== null ? (int)$agentVoiceCall->aiConversationId : null,
            $answerToken,
            $this->getRingDeadlineTimestamp($agentVoiceCall),
            $this->getCallerLabelKey($agentVoiceCall),
            $this->buildCallerContext($agentVoiceCall, $agentSpace)
        );
    }

    /**
     * `{expiresUnix}.{hmac}`: the signature binds the call and the expiry together, so the token identifies the ring
     * it was sent with without any storage, and a token of one call never redeems another. Opaque to the client.
     */
    public function mintAnswerToken(AgentVoiceCall $agentVoiceCall): string
    {
        $expiresUnixTime = $this->getRingDeadlineTimestamp($agentVoiceCall);

        return $expiresUnixTime . '.' . $this->buildAnswerTokenHmac((int)$agentVoiceCall->id, $expiresUnixTime);
    }

    /**
     * Verifies a presented answer token against this call: its signature, then its expiry. This only proves that the
     * token is genuine and still alive; the atomic first-wins claim happens after it ({@see answerRing()}).
     *
     * @throws BadRequestException when the token is malformed or its signature does not verify (`invalid_answer_token`)
     * @throws GoneException when the token's ring window has passed (`ring_expired`)
     */
    public function requireRedeemableAnswerToken(AgentVoiceCall $agentVoiceCall, string $answerToken): void
    {
        $tokenSegments = explode('.', trim($answerToken));
        if (count($tokenSegments) !== 2 || !ctype_digit($tokenSegments[0])) {
            throw new BadRequestException('invalid_answer_token');
        }
        [$expiresUnixTime, $presentedHmac] = [(int)$tokenSegments[0], $tokenSegments[1]];

        $expectedHmac = $this->buildAnswerTokenHmac((int)$agentVoiceCall->id, $expiresUnixTime);
        if (!hash_equals($expectedHmac, $presentedHmac)) {
            throw new BadRequestException('invalid_answer_token');
        }
        if (time() > $expiresUnixTime) {
            throw new GoneException('ring_expired');
        }
    }

    /**
     * A client answers the ring: the token is redeemed and the call is claimed. The claim is atomic and exactly one
     * client per call ever wins it; the winner is remembered beside the call, the ring message is cleared so every
     * other client stops ringing, and the winner goes on to open the session with this call id.
     *
     * @param string|null $clientId the id the answering client states for itself, when it states one
     * @throws BadRequestException when the token is malformed or forged (`invalid_answer_token`)
     * @throws GoneException when the ring window has passed (`ring_expired`)
     * @throws ConflictException when another client already won, or the ring was declined or withdrawn (`already_answered`)
     */
    public function answerRing(AgentVoiceCall $agentVoiceCall, string $answerToken, ?string $clientId = null): void
    {
        $answerReceivedAtUnixMs = AIConversationTraceEvent::nowUnixMs();
        $this->requireRedeemableAnswerToken($agentVoiceCall, $answerToken);

        if (!$this->getAgentVoiceCallStoreService()->claimRingingCallAnswer($agentVoiceCall)) {
            // The atomic claim said no: either the ring is over for another reason, or the deadline passed between the
            // token check and the claim. The loser is told which of the two it was.
            if ($agentVoiceCall->ringDeadlineAt !== null && time() > $agentVoiceCall->ringDeadlineAt->getTimestamp()) {
                throw new GoneException('ring_expired');
            }
            throw new ConflictException('already_answered');
        }

        $this->rememberAnsweringClient($agentVoiceCall, $clientId);
        $this->clearRing($agentVoiceCall);
        $this->recordAnswerClaimedTraceEvent($agentVoiceCall, $clientId, $answerReceivedAtUnixMs);
    }

    /**
     * A client declines the ring. The open clients share the one ring, so a decline in one stops it in all of them,
     * and the call nobody answered ends DECLINED with the call end marker in its conversation: the agent reads at its
     * next turn that the owner declined (no follow-up turn: the owner refused). The end is written exactly once, so a
     * deadline expiry at the same moment writes nothing twice. Idempotent within the ring window: a ring that is
     * already over there (answered, declined, withdrawn) simply acknowledges. After the window the token is no longer
     * redeemable and the decline is answered `ring_expired` like an answer: the deadline has ended the call.
     *
     * @throws BadRequestException when the token is malformed or forged (`invalid_answer_token`)
     * @throws GoneException when the ring window has passed (`ring_expired`)
     */
    public function declineRing(AgentVoiceCall $agentVoiceCall, string $answerToken): void
    {
        $this->requireRedeemableAnswerToken($agentVoiceCall, $answerToken);
        if ($agentVoiceCall->startedAt !== null || $agentVoiceCall->endedAt !== null) {
            return;
        }
        $this->clearRing($agentVoiceCall);
        $this->persistUnansweredCallEndOnce($agentVoiceCall, AgentVoiceCall::END_REASON_DECLINED);
    }

    /**
     * The id of the client that won the answer claim of this call, as it stated it; null when it stated none, when the
     * memory has run out ({@see self::ANSWERING_CLIENT_REMEMBERED_SECONDS}) or without a shared cache.
     */
    public function findAnsweringClientIdOfCall(AgentVoiceCall $agentVoiceCall): ?string
    {
        if (($agentVoiceCall->id ?? null) === null) {
            return null;
        }
        try {
            $answeringClientId = $this->getAgentRealtimeChannelService()->getSharedCache()?->get($this->buildAnsweringClientCacheKey($agentVoiceCall));
        } catch (Throwable $cacheReadFailure) {
            DDDService::instance()->getLogger()->warning(
                'AgentVoiceRingService: answering client of call ' . $agentVoiceCall->id . ' not read: ' . $cacheReadFailure->getMessage()
            );
            return null;
        }
        return is_string($answeringClientId) && $answeringClientId !== '' ? $answeringClientId : null;
    }

    /**
     * Whether `$clientId` may open the session of this rung call: the call was answered, and by this client. A call
     * nobody answered yet is false (the route answers `call_not_answered`). Clients are told apart only when both the
     * answer and the open stated a client id; otherwise every client of the call's account passes.
     */
    public function isCallAnsweredByClient(AgentVoiceCall $agentVoiceCall, ?string $clientId): bool
    {
        if ($agentVoiceCall->startedAt === null) {
            return false;
        }
        if ($clientId === null) {
            return true;
        }
        $answeringClientId = $this->findAnsweringClientIdOfCall($agentVoiceCall);
        return $answeringClientId === null || $answeringClientId === $clientId;
    }

    /**
     * Stops a ring whose call ended before any client answered: the agent withdrew it, or the call was hung up while
     * it rang. The ring message is removed; the call itself is ended by the caller. Best effort: the clients' own ring
     * timer is the guarantee.
     */
    public function cancelRingsOfCallEndedBeforeAnswer(AgentVoiceCall $agentVoiceCall): void
    {
        $this->clearRing($agentVoiceCall);
    }

    /**
     * Ends the call as EXPIRED when its ring is overdue: never answered, not ended, and past its ring deadline. The
     * end is written exactly once with the call end marker in its conversation, and a ring the agent itself started
     * then gets the agent's one follow-up turn. For an overdue ring the ring message is removed, whether this look or
     * one at the same moment wrote the end; a ring inside its window keeps its message. True when the ring was overdue
     * (the call has ended by this look or by one at the same moment); false for a call that is answered, already
     * ended or still inside its ring window.
     */
    public function expireOverdueRingOfCall(AgentVoiceCall $agentVoiceCall): bool
    {
        if (!$this->isRingOverdue($agentVoiceCall)) {
            return false;
        }
        $this->persistUnansweredCallEndOnce($agentVoiceCall, AgentVoiceCall::END_REASON_EXPIRED);
        $this->clearRing($agentVoiceCall);

        return true;
    }

    /**
     * The lazy expiry of ONE account's overdue rings: before a new ring of that account and before its open-call
     * check, so a ring whose deadline message is late or was never routed ends the moment the account is looked at.
     * One read of the account's unanswered calls past their ring deadline (newest first, as many as the store answers
     * in one read; a further look takes the rest); every such call ends EXPIRED once through the one end path of a call
     * nobody answered, and its ring message is cleared. Runs with the rights lifted: the scope is the account filter
     * of the read, and the call end marker goes into a conversation the signed-in account may not own. Returns how
     * many rings it ended (a ring that a look at the same moment ended counts as well). May throw: a caller for which
     * the expiry is a side step catches and goes on.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function expireOverdueRingsOfAccount(Account $account): int
    {
        if (!isset($account->id)) {
            return 0;
        }
        $endedRingCount = 0;
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $overdueCalls = $this->getAgentVoiceCallStoreService()->findUnansweredCallsOfAccountPastRingDeadline($account, time());
            foreach ($overdueCalls->getElements() as $agentVoiceCall) {
                $endedRingCount += $this->expireOverdueRingOfCall($agentVoiceCall) ? 1 : 0;
            }
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }

        return $endedRingCount;
    }

    /**
     * The lazy expiry of the overdue rings of the calls on ONE conversation: at its turn start, so the EXPIRED marker
     * is in the conversation before the turn's model call. One read of the conversation's calls, newest first (the
     * store answers the newest calls of a conversation in one read, and an overdue ring is among them: a ring is
     * minutes old when it expires). Runs with the rights lifted: the marker goes into a conversation the signed-in
     * account may not own. Returns how many overdue rings it saw.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function expireOverdueRingsOfConversation(AIConversation $aiConversation): int
    {
        $overdueRingCount = 0;
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            foreach ($this->getAgentVoiceCallStoreService()->findCallsOfConversation($aiConversation)->getElements() as $agentVoiceCall) {
                $overdueRingCount += $this->expireOverdueRingOfCall($agentVoiceCall) ? 1 : 0;
            }
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }

        return $overdueRingCount;
    }

    /**
     * The turn start of a conversation ({@see AIConversationTurnStartListener}): the overdue rings of its calls
     * expire, so the model of this turn reads that the owner did not pick up. Nothing while the voice channel is not
     * enabled (no call exists, and the call table need not either). A delegation child carries no call; a transient
     * conversation has no calls to read. True when a call ended (its marker row was written).
     */
    public function beforeTurnStarts(AIConversation $conversation): bool
    {
        if (!AgentVoiceConfiguration::current()->enabled || !isset($conversation->id) || $conversation->isDelegation()) {
            return false;
        }
        return $this->expireOverdueRingsOfConversation($conversation) > 0;
    }

    /**
     * What the application tells the ringing client about who calls, as the `callerContext` object of the ring
     * payload (which of the owner's agent spaces rings, a display name). Empty in the base. An application declares
     * its fields on its subclass of {@see AgentVoiceRingCallerContext} at the same path and fills them here.
     */
    protected function buildCallerContext(AgentVoiceCall $agentVoiceCall, ?AgentSpace $agentSpace): AgentVoiceRingCallerContext
    {
        return AgentVoiceRingCallerContext::newInstance();
    }

    /**
     * The text KEY the incoming call shows as its caller line, never a sentence: the client owns the text, fills
     * `%agentName%` from the agent's environment and translates. The base answers the key the web half of this
     * package renders for a call the agent started. A key the client does not know is shown as it came, with its
     * placeholders filled but untranslated, so an application that answers a key of its own registers it in its
     * client as well.
     */
    protected function getCallerLabelKey(AgentVoiceCall $agentVoiceCall): string
    {
        return static::CALLER_LABEL_KEY_OF_AGENT_STARTED_CALL;
    }

    /**
     * The secret the answer tokens are signed with: the application's secret, else the key the framework signs its
     * own tokens with. Never empty in an installation that can sign in a user.
     */
    protected function getAnswerTokenSecret(): string
    {
        $applicationSecret = (string)Config::getEnv('APP_SECRET');
        return $applicationSecret !== '' ? $applicationSecret : JWTPayload::getHashKey();
    }

    /** The signature that binds the call and the expiry into one token nobody else can make. */
    protected function buildAnswerTokenHmac(int $callId, int $expiresUnixTime): string
    {
        return hash_hmac(
            'sha256',
            static::ANSWER_TOKEN_PURPOSE . ":{$callId}:{$expiresUnixTime}",
            $this->getAnswerTokenSecret()
        );
    }

    /** When the ring of the call stops, as a unix time: its ring deadline, else the configured ring length from now. */
    protected function getRingDeadlineTimestamp(AgentVoiceCall $agentVoiceCall): int
    {
        return $agentVoiceCall->ringDeadlineAt?->getTimestamp() ?? time() + AgentVoiceConfiguration::current()->ringSeconds;
    }

    /** Whether the call still counts as ringing in its row although its ring deadline has passed. */
    protected function isRingOverdue(AgentVoiceCall $agentVoiceCall): bool
    {
        return ($agentVoiceCall->id ?? null) !== null
            && $agentVoiceCall->startedAt === null
            && $agentVoiceCall->endedAt === null
            && $agentVoiceCall->ringDeadlineAt !== null
            && $agentVoiceCall->ringDeadlineAt->getTimestamp() <= time();
    }

    /**
     * Remembers which client won the answer claim, beside the call: in the shared cache the realtime port keeps its
     * mailboxes in, for {@see self::ANSWERING_CLIENT_REMEMBERED_SECONDS}. Only a client id of the shape the agent
     * package's clients state is kept. Best effort.
     *
     * The call itself carries no column for it, so the entry is all that tells the clients of one account apart at
     * the session open. When it is gone (the time ran out, the cache dropped or lost it, the installation has no
     * shared cache, or the write failed), {@see isCallAnsweredByClient()} answers true for every client: the open of
     * an answered call is then refused to nobody of the call's account, and the first client that opens gets the
     * session. What still holds without the entry: the call must be a call of the signed-in account, it must be
     * answered and not ended, and it has one session (a second open is refused because the session exists already).
     */
    protected function rememberAnsweringClient(AgentVoiceCall $agentVoiceCall, ?string $clientId): void
    {
        if (!AgentClientPresence::isValidClientId($clientId)) {
            return;
        }
        try {
            $this->getAgentRealtimeChannelService()->getSharedCache()?->set(
                $this->buildAnsweringClientCacheKey($agentVoiceCall),
                $clientId,
                static::ANSWERING_CLIENT_REMEMBERED_SECONDS
            );
        } catch (Throwable $cacheWriteFailure) {
            DDDService::instance()->getLogger()->warning(
                'AgentVoiceRingService: answering client of call ' . $agentVoiceCall->id . ' not remembered: ' . $cacheWriteFailure->getMessage()
            );
        }
    }

    protected function buildAnsweringClientCacheKey(AgentVoiceCall $agentVoiceCall): string
    {
        return static::ANSWERING_CLIENT_CACHE_KEY_PREFIX . (int)$agentVoiceCall->id;
    }

    /**
     * The answer claim in the conversation trace: who won it and how long the claim took. The claim is won before
     * this write, so a trace that cannot be written is logged and never fails the answer.
     */
    protected function recordAnswerClaimedTraceEvent(AgentVoiceCall $agentVoiceCall, ?string $clientId, int $answerReceivedAtUnixMs): void
    {
        $claimDurationMs = AIConversationTraceEvent::nowUnixMs() - $answerReceivedAtUnixMs;
        try {
            /** @var AgentVoiceCallTraceEventsService $agentVoiceCallTraceEventsService */
            $agentVoiceCallTraceEventsService = DDDService::instance()->getService(AgentVoiceCallTraceEventsService::class);
            /** @var AIConversationTraceEventsService $aIConversationTraceEventsService */
            $aIConversationTraceEventsService = DDDService::instance()->getService(AIConversationTraceEventsService::class);
            $aIConversationTraceEventsService->recordEvent($agentVoiceCallTraceEventsService->buildTraceEventForCall(
                $agentVoiceCall,
                AIConversationTraceEvent::SOURCE_VOICE_WORKER,
                static::TRACE_EVENT_ANSWER_CLAIMED,
                $clientId ?? 'openClients',
                'answer claimed by ' . ($clientId !== null ? 'client ' . $clientId : 'an open client') . ' after ' . $claimDurationMs . ' ms',
                ['clientId' => $clientId],
                $claimDurationMs,
                $answerReceivedAtUnixMs
            ));
        } catch (Throwable $traceWriteFailure) {
            DDDService::instance()->getLogger()->warning(
                'AgentVoiceRingService: answer claim of call ' . $agentVoiceCall->id . ' not traced: ' . $traceWriteFailure->getMessage()
            );
        }
    }

    /**
     * The one write of a call nobody answered: exactly once, with the call end marker in its conversation, and the
     * agent's follow-up turn where one is owed. True for the caller whose write it was.
     */
    protected function persistUnansweredCallEndOnce(AgentVoiceCall $agentVoiceCall, string $endReason): bool
    {
        /** @var AgentVoiceCallsService $agentVoiceCallsService */
        $agentVoiceCallsService = DDDService::instance()->getService(AgentVoiceCallsService::class);
        return $agentVoiceCallsService->persistUnansweredCallEndOnce($agentVoiceCall, $endReason);
    }

    protected function getAgentRealtimeChannelService(): AgentRealtimeChannelService
    {
        /** @var AgentRealtimeChannelService $agentRealtimeChannelService */
        $agentRealtimeChannelService = DDDService::instance()->getService(AgentRealtimeChannelService::class);
        return $agentRealtimeChannelService;
    }

    protected function getAgentVoiceCallStoreService(): AgentVoiceCallStoreService
    {
        /** @var AgentVoiceCallStoreService $agentVoiceCallStoreService */
        $agentVoiceCallStoreService = DDDService::instance()->getService(AgentVoiceCallStoreService::class);
        return $agentVoiceCallStoreService;
    }
}
