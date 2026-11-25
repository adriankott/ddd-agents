<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Admission;

use DDD\Domain\AI\Services\Voice\AgentVoiceCallAdmissionService;
use DDD\Domain\Common\Entities\Accounts\Account;
use Throwable;

/** The admission with its log line of a gate that could not be asked recorded (account id, error class, message). */
class GateLogRecordingAgentVoiceCallAdmissionService extends AgentVoiceCallAdmissionService
{
    /** @var array<int, array{accountId: int|null, errorClass: string, message: string}> */
    public array $loggedGateFailures = [];

    protected function logModelCallGateThatCouldNotBeAsked(?Account $billedAccount, Throwable $gateError): void
    {
        $this->loggedGateFailures[] = [
            'accountId' => $billedAccount?->id !== null ? (int)$billedAccount->id : null,
            'errorClass' => $gateError::class,
            'message' => $gateError->getMessage(),
        ];
        parent::logModelCallGateThatCouldNotBeAsked($billedAccount, $gateError);
    }
}
