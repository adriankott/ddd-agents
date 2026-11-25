<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Voice\Calls;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCalls;
use DDD\Domain\Common\Entities\Accounts\Account;

/**
 * Where calls are kept: one store per name `AI.agentVoice.callStore` can carry. The rest of the voice channel never
 * asks a store directly; it asks {@see AgentVoiceCallStoreService}, which hands each question to the configured store.
 *
 * Every write here is a targeted one (the columns it names and no others), because a ring, an answer, the session
 * worker and an end can write the same call within the same second.
 */
interface AgentVoiceCallStore
{
    /** Stores a new call and returns it with its id. */
    public function createCall(AgentVoiceCall $agentVoiceCall): AgentVoiceCall;

    /** The call with this id, whoever it belongs to; null when there is none. Always a fresh read. */
    public function findCall(int $callId): ?AgentVoiceCall;

    /** The call with this id when it is a call of `$account`; another account's call reads as missing. */
    public function findCallOfAccount(int $callId, Account $account): ?AgentVoiceCall;

    /** The call of the provider session `$providerCallId`; null when no call carries it. */
    public function findCallByProviderCallId(string $providerCallId): ?AgentVoiceCall;

    /** The youngest call of `$account` that rings or runs now ("one call at a time"); null when there is none. */
    public function findOpenCallOfAccount(Account $account): ?AgentVoiceCall;

    /**
     * The calls `$conversation` carried, NEWEST first, at most the list limit of the binding: a conversation that
     * carried more calls answers its newest ones. A caller that wants them in the order they happened reverses the
     * list.
     */
    public function findCallsOfConversation(AIConversation $conversation): AgentVoiceCalls;

    /** The answered call of `$conversation` that runs now; null when nobody is on a call there. */
    public function findRunningCallOfConversation(AIConversation $conversation): ?AgentVoiceCall;

    /** The calls of `$account`, newest first; with `$onlyOpenCalls` only the ones ringing or running now. */
    public function findCallsOfAccount(Account $account, bool $onlyOpenCalls): AgentVoiceCalls;

    /**
     * The answer claim of a ringing call: true for exactly one caller, whose call is then answered (`startedAt` set on
     * `$agentVoiceCall` too). False when the call was answered or ended already or its ring deadline passed.
     */
    public function claimRingingCallAnswer(AgentVoiceCall $agentVoiceCall): bool;

    /**
     * The end claim of a call nobody answered: true for exactly one caller, whose end is then written (`endedAt` and
     * `endReason` set on `$agentVoiceCall` too). False when the call was answered or ended already.
     */
    public function claimUnansweredCallEnd(AgentVoiceCall $agentVoiceCall, string $endReason): bool;

    /** Writes that the call was answered now. */
    public function markCallStarted(AgentVoiceCall $agentVoiceCall): void;

    /** Writes the end of the call and why it ended. */
    public function markCallEnded(AgentVoiceCall $agentVoiceCall, string $endReason): void;

    /** Writes the provider's id of the session the call runs on. */
    public function setProviderCallIdOfCall(AgentVoiceCall $agentVoiceCall, string $providerCallId): void;

    /**
     * Writes the name of the provider adapter the session of the call was created on, for a call whose adapter is
     * decided only when its session is opened (a call created with its adapter needs no second write).
     */
    public function setVoiceProviderOfCall(AgentVoiceCall $agentVoiceCall, string $voiceProviderName): void;

    /** Writes the conversation in which the agent itself started the call. */
    public function markCallStartedByAgentInConversation(AgentVoiceCall $agentVoiceCall, AIConversation $conversation): void;

    /** Adds `$seconds` to what the call has booked as usage. */
    public function addBookedSeconds(AgentVoiceCall $agentVoiceCall, int $seconds): void;

    /** The seconds the calls of `$account` booked on the day that starts at `$dayStartUnixTime`. */
    public function findBookedSecondsOfAccountOnDay(Account $account, int $dayStartUnixTime): int;

    /** The answered calls that were never hung up and were answered more than `$olderThanMinutes` ago. */
    public function findRunningCallsStartedBefore(int $olderThanMinutes): AgentVoiceCalls;

    /**
     * The answered call that runs now on the conversation with this id; null when nobody is on a call there. The same
     * read as {@see findRunningCallOfConversation()} for a caller that holds only the id (the call questions of the
     * agent core name a conversation by id).
     */
    public function findRunningCallOfConversationId(int $conversationId): ?AgentVoiceCall;

    /**
     * The calls of the accounts `$accountIds`, for a list over several accounts (the admin list). The account ids are a
     * query filter: null = the calls of every account, an empty list = no call. The query options of the request
     * (filters, orderBy, top, skip) apply on top and cannot widen the filter; newest first when they name no order.
     * Which accounts a caller may read is the caller's decision, taken before it asks.
     *
     * @param int[]|null $accountIds
     */
    public function findCallsOfAccountIds(?array $accountIds): AgentVoiceCalls;

    /**
     * The call with this id when it is a call of one of the accounts `$accountIds`; a call of another account reads as
     * missing. Null = whichever account the call belongs to, an empty list = no call.
     *
     * @param int[]|null $accountIds
     */
    public function findCallOfAccountIds(int $callId, ?array $accountIds): ?AgentVoiceCall;

    /**
     * The calls of `$account` nobody answered whose ring deadline has passed at `$nowUnixTime`, newest first: not
     * answered, not ended, a ring deadline set and not after `$nowUnixTime`. The account is a query filter. What the
     * lazy expiry of an account's rings ends; at most the list limit of the binding per read, so a caller that ends
     * what it gets reaches the rest with its next read.
     */
    public function findUnansweredCallsOfAccountPastRingDeadline(Account $account, int $nowUnixTime): AgentVoiceCalls;
}
