<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Doubles;

use DDD\Domain\AI\Repo\DB\Voice\Calls\DBAgentVoiceCalls;
use DDD\Domain\AI\Services\Voice\Calls\AgentVoiceCallEntityStoreService;

/** The `entity` binding over a repository the test hands in (the override point {@see getCallsRepository()}). */
class RepositoryDoubleAgentVoiceCallEntityStoreService extends AgentVoiceCallEntityStoreService
{
    public function __construct(public DBAgentVoiceCalls $callsRepository)
    {
    }

    protected function getCallsRepository(): DBAgentVoiceCalls
    {
        return $this->callsRepository;
    }
}
