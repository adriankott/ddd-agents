<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\CallLife;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCalls;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallsService;
use DDD\Infrastructure\Exceptions\InternalErrorException;

/**
 * The calls service as the ends service sees it: the account's open calls and its calls by id are what the test put
 * in (a call of another account is simply not in {@see $callsOfTheAccountById}); the goodbye request and the end now
 * are recorded. With {@see $goodbyeRequestFails} the goodbye request throws as without a shared cache.
 */
class ScriptedAgentVoiceCallsService extends AgentVoiceCallsService
{
    /** @var AgentVoiceCall[] */
    public array $openCallsOfTheAccount = [];

    /** @var array<int, AgentVoiceCall> */
    public array $callsOfTheAccountById = [];

    public bool $goodbyeRequestFails = false;

    /** @var string[] "<method>:<callId>:<endReason>", in order */
    public array $recordedActions = [];

    /** @var array<int, array{method: string, arguments: array<int, mixed>}> */
    public array $recordedReads = [];

    public function findOpenCallsOfAccountId(int $accountId): AgentVoiceCalls
    {
        $this->recordedReads[] = ['method' => 'findOpenCallsOfAccountId', 'arguments' => [$accountId]];

        return InMemoryAgentVoiceCallStore::buildCallSet($this->openCallsOfTheAccount);
    }

    public function findCallOfAccountIdById(int $callId, int $accountId): ?AgentVoiceCall
    {
        $this->recordedReads[] = ['method' => 'findCallOfAccountIdById', 'arguments' => [$callId, $accountId]];

        return $this->callsOfTheAccountById[$callId] ?? null;
    }

    public function requestCallEndAfterGoodbye(AgentVoiceCall $agentVoiceCall, string $endReason): void
    {
        $this->recordedActions[] = 'requestCallEndAfterGoodbye:' . $agentVoiceCall->id . ':' . $endReason;
        if ($this->goodbyeRequestFails) {
            throw new InternalErrorException('shared_cache_unavailable');
        }
    }

    public function endCallNow(AgentVoiceCall $agentVoiceCall, string $endReason): bool
    {
        $this->recordedActions[] = 'endCallNow:' . $agentVoiceCall->id . ':' . $endReason;

        return true;
    }
}
