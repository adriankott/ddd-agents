<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Ring;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCalls;
use DDD\Domain\AI\Services\Voice\Calls\AgentVoiceCallEntityStoreService;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use LogicException;
use RuntimeException;

/**
 * The `entity` binding of the call store without a table: the calls live in {@see $storedCallsById}, and the two
 * claims are decided on the STORED state, the way the table's atomic UPDATE decides them: two objects of one call (two
 * clients that loaded it) compete for one claim, and exactly one wins. Every method the ring and the call start ask
 * records its name, its subject and whether the entity rights applied at that moment; a method the test did not stage throws, so an unexpected read is visible.
 *
 * With {@see $failingMethodNames} a method throws a RuntimeException naming it.
 */
class InMemoryAgentVoiceCallStoreDouble extends AgentVoiceCallEntityStoreService
{
    /** @var array<int, AgentVoiceCall> The stored calls by id */
    public array $storedCallsById = [];

    /** @var array<int, array{method: string, subject: mixed, rightsApplied: bool}> The calls of the store, in order */
    public array $recordedCalls = [];

    /** @var string[] Methods that throw */
    public array $failingMethodNames = [];

    /** @var int The id the next created call gets */
    public int $nextCallId = 501;

    /** @var array<int, int> The `$nowUnixTime` every past-deadline read was asked with */
    public array $pastDeadlineReadTimes = [];

    public function __construct()
    {
    }

    public function storeCall(AgentVoiceCall $agentVoiceCall): AgentVoiceCall
    {
        $this->storedCallsById[(int)$agentVoiceCall->id] = $agentVoiceCall;
        return $agentVoiceCall;
    }

    /** A second object of a stored call, with the stored state at this moment (what another process loads). */
    public function loadCopyOfCall(int $callId): AgentVoiceCall
    {
        $storedCall = $this->storedCallsById[$callId];
        $loadedCall = new ($storedCall::class)();
        foreach (['id', 'accountId', 'aiConversationId', 'direction', 'ringDeadlineAt', 'startedAt', 'endedAt', 'endReason', 'callTask', 'startedByAgentInConversationId'] as $propertyName) {
            $loadedCall->$propertyName = $storedCall->$propertyName;
        }
        return $loadedCall;
    }

    /** @return string[] */
    public function getRecordedMethodNames(): array
    {
        return array_column($this->recordedCalls, 'method');
    }

    public function createCall(AgentVoiceCall $agentVoiceCall): AgentVoiceCall
    {
        $this->record('createCall', $agentVoiceCall);
        $agentVoiceCall->id = $this->nextCallId++;
        return $this->storeCall($agentVoiceCall);
    }

    public function findCall(int $callId): ?AgentVoiceCall
    {
        $this->record('findCall', $callId);
        return isset($this->storedCallsById[$callId]) ? $this->loadCopyOfCall($callId) : null;
    }

    public function findOpenCallOfAccount(Account $account): ?AgentVoiceCall
    {
        $this->record('findOpenCallOfAccount', (int)$account->id);
        foreach ($this->storedCallsById as $storedCall) {
            if ((int)$storedCall->accountId === (int)$account->id && $storedCall->isOpenAt(time())) {
                return $storedCall;
            }
        }
        return null;
    }

    public function findCallsOfConversation(AIConversation $conversation): AgentVoiceCalls
    {
        $this->record('findCallsOfConversation', (int)$conversation->id);
        $agentVoiceCalls = new AgentVoiceCalls();
        foreach (array_reverse($this->storedCallsById, true) as $storedCall) {
            if ((int)$storedCall->aiConversationId === (int)$conversation->id) {
                $agentVoiceCalls->add($storedCall);
            }
        }
        return $agentVoiceCalls;
    }

    public function findRunningCallOfConversation(AIConversation $conversation): ?AgentVoiceCall
    {
        $this->record('findRunningCallOfConversation', (int)$conversation->id);
        foreach ($this->storedCallsById as $storedCall) {
            if ((int)$storedCall->aiConversationId === (int)$conversation->id && $storedCall->isRunning()) {
                return $storedCall;
            }
        }
        return null;
    }

    public function findUnansweredCallsOfAccountPastRingDeadline(Account $account, int $nowUnixTime): AgentVoiceCalls
    {
        $this->record('findUnansweredCallsOfAccountPastRingDeadline', (int)$account->id);
        $this->pastDeadlineReadTimes[] = $nowUnixTime;
        $agentVoiceCalls = new AgentVoiceCalls();
        foreach (array_reverse($this->storedCallsById, true) as $storedCall) {
            if ((int)$storedCall->accountId === (int)$account->id && $storedCall->startedAt === null && $storedCall->endedAt === null
                && $storedCall->ringDeadlineAt !== null && $storedCall->ringDeadlineAt->getTimestamp() <= $nowUnixTime
            ) {
                $agentVoiceCalls->add($storedCall);
            }
        }
        return $agentVoiceCalls;
    }

    public function claimRingingCallAnswer(AgentVoiceCall $agentVoiceCall): bool
    {
        $this->record('claimRingingCallAnswer', (int)$agentVoiceCall->id);
        $storedCall = $this->storedCallsById[(int)$agentVoiceCall->id] ?? null;
        if ($storedCall === null || $storedCall->startedAt !== null || $storedCall->endedAt !== null
            || ($storedCall->ringDeadlineAt !== null && $storedCall->ringDeadlineAt->getTimestamp() <= time())
        ) {
            return false;
        }
        $storedCall->startedAt = DateTime::fromTimestamp(time());
        $agentVoiceCall->startedAt = $storedCall->startedAt;
        return true;
    }

    public function claimUnansweredCallEnd(AgentVoiceCall $agentVoiceCall, string $endReason): bool
    {
        $this->record('claimUnansweredCallEnd', (int)$agentVoiceCall->id . ':' . $endReason);
        $storedCall = $this->storedCallsById[(int)$agentVoiceCall->id] ?? null;
        if ($storedCall === null || $storedCall->startedAt !== null || $storedCall->endedAt !== null) {
            return false;
        }
        $storedCall->endedAt = DateTime::fromTimestamp(time());
        $storedCall->endReason = $endReason;
        $agentVoiceCall->endedAt = $storedCall->endedAt;
        $agentVoiceCall->endReason = $endReason;
        return true;
    }

    public function markCallEnded(AgentVoiceCall $agentVoiceCall, string $endReason): void
    {
        $this->record('markCallEnded', (int)$agentVoiceCall->id . ':' . $endReason);
        $agentVoiceCall->markEnded($endReason);
        if (isset($this->storedCallsById[(int)$agentVoiceCall->id])) {
            $this->storedCallsById[(int)$agentVoiceCall->id]->endedAt = $agentVoiceCall->endedAt;
            $this->storedCallsById[(int)$agentVoiceCall->id]->endReason = $endReason;
        }
    }

    public function markCallStartedByAgentInConversation(AgentVoiceCall $agentVoiceCall, AIConversation $conversation): void
    {
        $this->record('markCallStartedByAgentInConversation', (int)$agentVoiceCall->id . ':' . (int)$conversation->id);
        $agentVoiceCall->startedByAgentInConversationId = (int)$conversation->id;
    }

    public function findCallOfAccount(int $callId, Account $account): ?AgentVoiceCall
    {
        throw new LogicException('findCallOfAccount is not staged');
    }

    public function findCallsOfAccount(Account $account, bool $onlyOpenCalls): AgentVoiceCalls
    {
        throw new LogicException('findCallsOfAccount is not staged');
    }

    public function markCallStarted(AgentVoiceCall $agentVoiceCall): void
    {
        throw new LogicException('markCallStarted is not staged');
    }

    protected function record(string $methodName, mixed $subject): void
    {
        $this->recordedCalls[] = ['method' => $methodName, 'subject' => $subject, 'rightsApplied' => DBEntity::getApplyRightsRestrictions()];
        if (in_array($methodName, $this->failingMethodNames, true)) {
            throw new RuntimeException($methodName . ' failed');
        }
    }
}
