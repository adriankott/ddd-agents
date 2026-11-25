<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Doubles;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCalls;
use DDD\Domain\AI\Repo\DB\Voice\Calls\DBAgentVoiceCalls;
use DDD\Domain\Base\Repo\DB\DBEntity;
use RuntimeException;

/**
 * The call table's repository without a database: every read and write the `entity` binding asks records its method,
 * its arguments and whether the entity rights applied at that moment, and answers what the test put into
 * {@see $answersByMethod}. With {@see $failureMessage} set, every method throws.
 */
class RecordingDBAgentVoiceCalls extends DBAgentVoiceCalls
{
    /** @var array<int, array{method: string, arguments: array<int, mixed>, rightsApplied: bool}> The calls, in order */
    public array $recordedCalls = [];

    /** @var array<string, mixed> Method name => the answer it gives (null when not set) */
    public array $answersByMethod = [];

    /** @var string|null When set, every method throws a RuntimeException with this message */
    public ?string $failureMessage = null;

    public function findOpenCalls(int $nowUnixTime, int $limit, int $ownerAccountId): ?AgentVoiceCalls
    {
        return $this->recordCall('findOpenCalls', [$nowUnixTime, $limit, $ownerAccountId]);
    }

    public function findCallsOfAccount(int $ownerAccountId): ?AgentVoiceCalls
    {
        return $this->recordCall('findCallsOfAccount', [$ownerAccountId]);
    }

    public function findCallOfAccountById(int $callId, int $ownerAccountId): ?AgentVoiceCall
    {
        return $this->recordCall('findCallOfAccountById', [$callId, $ownerAccountId]);
    }

    public function findCallById(int $callId): ?AgentVoiceCall
    {
        return $this->recordCall('findCallById', [$callId]);
    }

    public function findCallByProviderCallId(string $providerCallId): ?AgentVoiceCall
    {
        return $this->recordCall('findCallByProviderCallId', [$providerCallId]);
    }

    public function findCallsByConversationId(int $aiConversationId, int $limit = 50): ?AgentVoiceCalls
    {
        return $this->recordCall('findCallsByConversationId', [$aiConversationId, $limit]);
    }

    public function findRunningCallsStartedBefore(int $olderThanMinutes): ?AgentVoiceCalls
    {
        return $this->recordCall('findRunningCallsStartedBefore', [$olderThanMinutes]);
    }

    public function findOpenAnsweredCallByConversationId(int $aiConversationId): ?AgentVoiceCall
    {
        return $this->recordCall('findOpenAnsweredCallByConversationId', [$aiConversationId]);
    }

    public function findBookedSecondsOfAccountOnDay(int $ownerAccountId, int $dayStartUnixTime): int
    {
        return (int)$this->recordCall('findBookedSecondsOfAccountOnDay', [$ownerAccountId, $dayStartUnixTime]);
    }

    public function claimRingingCallAnswer(int $callId, int $nowUnixTime): bool
    {
        return (bool)$this->recordCall('claimRingingCallAnswer', [$callId, $nowUnixTime]);
    }

    public function claimUnansweredCallEnd(int $callId, string $endReason, int $nowUnixTime): bool
    {
        return (bool)$this->recordCall('claimUnansweredCallEnd', [$callId, $endReason, $nowUnixTime]);
    }

    public function addBookedSeconds(int $callId, int $seconds): void
    {
        $this->recordCall('addBookedSeconds', [$callId, $seconds]);
    }

    /** @return string[] The names of the recorded methods, in order */
    public function getRecordedMethodNames(): array
    {
        return array_column($this->recordedCalls, 'method');
    }

    /** @param array<int, mixed> $arguments */
    protected function recordCall(string $methodName, array $arguments): mixed
    {
        $this->recordedCalls[] = ['method' => $methodName, 'arguments' => $arguments, 'rightsApplied' => DBEntity::getApplyRightsRestrictions()];
        if ($this->failureMessage !== null) {
            throw new RuntimeException($this->failureMessage);
        }
        return $this->answersByMethod[$methodName] ?? null;
    }
}
