<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Calls\StoreAdditions;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCalls;
use DDD\Tests\Agents\Voice\Doubles\RecordingDBAgentVoiceCalls;

/** The recording repository of the call table with the reads added after the core tests: by several accounts and past the ring deadline. */
class AccountReadsRecordingDBAgentVoiceCalls extends RecordingDBAgentVoiceCalls
{
    public function findCallsOfAccounts(?array $ownerAccountIds): ?AgentVoiceCalls
    {
        return $this->recordCall('findCallsOfAccounts', [$ownerAccountIds]);
    }

    public function findCallOfAccountsById(int $callId, ?array $ownerAccountIds): ?AgentVoiceCall
    {
        return $this->recordCall('findCallOfAccountsById', [$callId, $ownerAccountIds]);
    }

    public function findUnansweredCallsPastRingDeadline(int $nowUnixTime, int $limit, int $ownerAccountId): ?AgentVoiceCalls
    {
        return $this->recordCall('findUnansweredCallsPastRingDeadline', [$nowUnixTime, $limit, $ownerAccountId]);
    }
}
