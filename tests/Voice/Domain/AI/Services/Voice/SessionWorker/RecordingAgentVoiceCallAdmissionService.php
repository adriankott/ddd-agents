<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

use DDD\Domain\AI\Entities\ModelCalls\AIModelCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallRefusal;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallAdmissionService;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Common\Entities\Accounts\Account;

/**
 * The admission port as the session worker asks it: the running-call question takes the next scripted refusal (none
 * left = the call goes on) and is counted; the session's model call is built by the package's own method, and what it
 * was asked with is kept.
 */
class RecordingAgentVoiceCallAdmissionService extends AgentVoiceCallAdmissionService
{
    /** @var array<int, AgentVoiceCallRefusal|null> */
    public array $runningCallRefusals = [];

    /** @var array<int, int|null> The call id of every running-call question */
    public array $runningCallQuestionCallIds = [];

    /** @var array<int, array{billedAccountId: int|null, billedEntity: Entity|null, providerName: string|null}> */
    public array $sessionModelCallRequests = [];

    public function findRefusalOfRunningCall(AgentVoiceCall $agentVoiceCall): ?AgentVoiceCallRefusal
    {
        $this->runningCallQuestionCallIds[] = $agentVoiceCall->id;

        return array_shift($this->runningCallRefusals);
    }

    /** @var array<int, array{callId: int|null, modelCall: AIModelCall|null}> Every model call of a running call, as answered */
    public array $runningCallModelCalls = [];

    public function buildSessionModelCallOfRunningCall(AgentVoiceCall $agentVoiceCall): ?AIModelCall
    {
        $sessionModelCall = parent::buildSessionModelCallOfRunningCall($agentVoiceCall);
        $this->runningCallModelCalls[] = ['callId' => $agentVoiceCall->id, 'modelCall' => $sessionModelCall];

        return $sessionModelCall;
    }

    public function buildSessionModelCall(Account $billedAccount, ?Entity $billedEntity, ?string $providerName = null): AIModelCall
    {
        $this->sessionModelCallRequests[] = [
            'billedAccountId' => $billedAccount->id,
            'billedEntity' => $billedEntity,
            'providerName' => $providerName,
        ];

        return parent::buildSessionModelCall($billedAccount, $billedEntity, $providerName);
    }
}
