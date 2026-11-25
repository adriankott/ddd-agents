<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStoreService;
use RuntimeException;

/**
 * The call store port as the session worker uses it: the fresh read of a call answers the call the test stored under
 * its id, the booked seconds are kept, and the next `$addBookedSecondsFailures` bookings throw.
 */
class RecordingAgentVoiceCallStoreService extends AgentVoiceCallStoreService
{
    public const string BOOKED_SECONDS_REFUSAL_MESSAGE = 'the call store refused the booked seconds';

    /** @var array<int, AgentVoiceCall> What a fresh read of a call answers, by call id */
    public array $storedCallsById = [];

    /** @var int[] The ids of every fresh read */
    public array $foundCallIds = [];

    /** @var array<int, array{callId: int|null, seconds: int}> Every booking that was written */
    public array $bookedSeconds = [];

    /** @var array<int, array{callId: int|null, seconds: int}> Every booking that was asked for, written or refused */
    public array $bookedSecondsRequests = [];

    public int $addBookedSecondsFailures = 0;

    public function findCall(int $callId): ?AgentVoiceCall
    {
        $this->foundCallIds[] = $callId;

        return $this->storedCallsById[$callId] ?? null;
    }

    public function addBookedSeconds(AgentVoiceCall $agentVoiceCall, int $seconds): void
    {
        $this->bookedSecondsRequests[] = ['callId' => $agentVoiceCall->id, 'seconds' => $seconds];
        if ($this->addBookedSecondsFailures > 0) {
            $this->addBookedSecondsFailures--;
            throw new RuntimeException(static::BOOKED_SECONDS_REFUSAL_MESSAGE);
        }
        $this->bookedSeconds[] = ['callId' => $agentVoiceCall->id, 'seconds' => $seconds];
    }
}
