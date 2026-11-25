<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\CallLife;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCalls;
use DDD\Domain\AI\Services\Voice\Calls\AgentVoiceCallStore;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use RuntimeException;

/**
 * A call store in memory that records every question it is asked (method and arguments, in order). The end claim of a
 * call nobody answered is decided by the call id, as the table decides it by the row: the first claim of an id wins,
 * every later one loses, whichever object of the call it is made on. The answers of the reads are what the test put in.
 */
class InMemoryAgentVoiceCallStore implements AgentVoiceCallStore
{
    /** @var array<int, array{method: string, arguments: array<int, mixed>}> */
    public array $recordedCalls = [];

    /** @var array<int, true> The call ids whose unanswered end was claimed */
    public array $claimedEndCallIds = [];

    /** @var AgentVoiceCall[] The answer of the stale-call read */
    public array $staleRunningCalls = [];

    /** @var AgentVoiceCall[] The answer of the reads of a conversation's calls */
    public array $callsOfConversation = [];

    /** @var AgentVoiceCall[] The answer of the reads of an account's calls */
    public array $callsOfAccount = [];

    public ?AgentVoiceCall $openCallOfAccount = null;

    public ?AgentVoiceCall $callOfAccount = null;

    public int $bookedSecondsOfAccountOnDay = 0;

    /** @var string|null When set, markCallEnded throws a RuntimeException with this message */
    public ?string $markCallEndedFailureMessage = null;

    public function createCall(AgentVoiceCall $agentVoiceCall): AgentVoiceCall
    {
        $this->record('createCall', [$agentVoiceCall]);

        return $agentVoiceCall;
    }

    public function findCall(int $callId): ?AgentVoiceCall
    {
        $this->record('findCall', [$callId]);

        return null;
    }

    public function findCallOfAccount(int $callId, Account $account): ?AgentVoiceCall
    {
        $this->record('findCallOfAccount', [$callId, (int)$account->id]);

        return $this->callOfAccount;
    }

    public function findCallByProviderCallId(string $providerCallId): ?AgentVoiceCall
    {
        $this->record('findCallByProviderCallId', [$providerCallId]);

        return null;
    }

    public function findOpenCallOfAccount(Account $account): ?AgentVoiceCall
    {
        $this->record('findOpenCallOfAccount', [(int)$account->id]);

        return $this->openCallOfAccount;
    }

    public function findCallsOfConversation(AIConversation $conversation): AgentVoiceCalls
    {
        $this->record('findCallsOfConversation', [(int)$conversation->id]);

        return static::buildCallSet($this->callsOfConversation);
    }

    public function findRunningCallOfConversation(AIConversation $conversation): ?AgentVoiceCall
    {
        $this->record('findRunningCallOfConversation', [(int)$conversation->id]);

        return null;
    }

    public function findCallsOfAccount(Account $account, bool $onlyOpenCalls): AgentVoiceCalls
    {
        $this->record('findCallsOfAccount', [(int)$account->id, $onlyOpenCalls]);

        return static::buildCallSet($this->callsOfAccount);
    }

    public function claimRingingCallAnswer(AgentVoiceCall $agentVoiceCall): bool
    {
        $this->record('claimRingingCallAnswer', [(int)$agentVoiceCall->id]);

        return false;
    }

    public function claimUnansweredCallEnd(AgentVoiceCall $agentVoiceCall, string $endReason): bool
    {
        $this->record('claimUnansweredCallEnd', [(int)$agentVoiceCall->id, $endReason]);
        if (isset($this->claimedEndCallIds[(int)$agentVoiceCall->id])) {
            return false;
        }
        $this->claimedEndCallIds[(int)$agentVoiceCall->id] = true;
        $agentVoiceCall->endedAt = DateTime::fromTimestamp(time());
        $agentVoiceCall->endReason = $endReason;

        return true;
    }

    public function markCallStarted(AgentVoiceCall $agentVoiceCall): void
    {
        $this->record('markCallStarted', [(int)$agentVoiceCall->id]);
        $agentVoiceCall->markStarted();
    }

    public function markCallEnded(AgentVoiceCall $agentVoiceCall, string $endReason): void
    {
        $this->record('markCallEnded', [(int)$agentVoiceCall->id, $endReason]);
        if ($this->markCallEndedFailureMessage !== null) {
            throw new RuntimeException($this->markCallEndedFailureMessage);
        }
        $agentVoiceCall->markEnded($endReason);
    }

    public function setProviderCallIdOfCall(AgentVoiceCall $agentVoiceCall, string $providerCallId): void
    {
        $this->record('setProviderCallIdOfCall', [(int)$agentVoiceCall->id, $providerCallId]);
        $agentVoiceCall->providerCallId = $providerCallId;
    }

    public function setVoiceProviderOfCall(AgentVoiceCall $agentVoiceCall, string $voiceProviderName): void
    {
        $this->record('setVoiceProviderOfCall', [(int)$agentVoiceCall->id, $voiceProviderName]);
        $agentVoiceCall->voiceProvider = $voiceProviderName;
    }

    public function markCallStartedByAgentInConversation(AgentVoiceCall $agentVoiceCall, AIConversation $conversation): void
    {
        $this->record('markCallStartedByAgentInConversation', [(int)$agentVoiceCall->id, (int)$conversation->id]);
        $agentVoiceCall->startedByAgentInConversationId = (int)$conversation->id;
    }

    public function addBookedSeconds(AgentVoiceCall $agentVoiceCall, int $seconds): void
    {
        $this->record('addBookedSeconds', [(int)$agentVoiceCall->id, $seconds]);
    }

    public function findBookedSecondsOfAccountOnDay(Account $account, int $dayStartUnixTime): int
    {
        $this->record('findBookedSecondsOfAccountOnDay', [(int)$account->id, $dayStartUnixTime]);

        return $this->bookedSecondsOfAccountOnDay;
    }

    public function findRunningCallsStartedBefore(int $olderThanMinutes): AgentVoiceCalls
    {
        $this->record('findRunningCallsStartedBefore', [$olderThanMinutes]);

        return static::buildCallSet($this->staleRunningCalls);
    }

    public function findRunningCallOfConversationId(int $conversationId): ?AgentVoiceCall
    {
        $this->record('findRunningCallOfConversationId', [$conversationId]);

        return null;
    }

    public function findCallsOfAccountIds(?array $accountIds): AgentVoiceCalls
    {
        $this->record('findCallsOfAccountIds', [$accountIds]);

        return new AgentVoiceCalls();
    }

    public function findCallOfAccountIds(int $callId, ?array $accountIds): ?AgentVoiceCall
    {
        $this->record('findCallOfAccountIds', [$callId, $accountIds]);

        return null;
    }

    public function findUnansweredCallsOfAccountPastRingDeadline(Account $account, int $nowUnixTime): AgentVoiceCalls
    {
        $this->record('findUnansweredCallsOfAccountPastRingDeadline', [(int)$account->id, $nowUnixTime]);

        return new AgentVoiceCalls();
    }

    /** @return string[] The names of the recorded methods, in order */
    public function getRecordedMethodNames(): array
    {
        return array_column($this->recordedCalls, 'method');
    }

    /** @return array<int, array<int, mixed>> The arguments of every recorded call of `$methodName`, in order */
    public function getArgumentsOf(string $methodName): array
    {
        $argumentsOfMethod = [];
        foreach ($this->recordedCalls as $recordedCall) {
            if ($recordedCall['method'] === $methodName) {
                $argumentsOfMethod[] = $recordedCall['arguments'];
            }
        }

        return $argumentsOfMethod;
    }

    /** @param AgentVoiceCall[] $agentVoiceCalls */
    public static function buildCallSet(array $agentVoiceCalls): AgentVoiceCalls
    {
        $agentVoiceCallSet = new AgentVoiceCalls();
        foreach ($agentVoiceCalls as $agentVoiceCall) {
            $agentVoiceCallSet->add($agentVoiceCall);
        }

        return $agentVoiceCallSet;
    }

    /** @param array<int, mixed> $arguments */
    protected function record(string $methodName, array $arguments): void
    {
        $this->recordedCalls[] = ['method' => $methodName, 'arguments' => $arguments];
    }
}
