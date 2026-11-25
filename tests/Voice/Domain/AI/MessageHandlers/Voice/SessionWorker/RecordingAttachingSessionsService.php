<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\MessageHandlers\Voice\SessionWorker;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Services\Voice\SessionWorker\AgentLiveVoiceSessionsService;
use DDD\Domain\Base\Repo\DatabaseRepoEntity;
use Throwable;

/**
 * The session worker as the handler calls it: the attach is recorded with whether the rights restrictions applied
 * while it ran, and throws what the test scripts.
 */
class RecordingAttachingSessionsService extends AgentLiveVoiceSessionsService
{
    /** @var array<int, array{callId: int|null, async: bool, rightsRestrictionsApplied: bool}> */
    public array $attachRequests = [];

    public ?Throwable $attachFailure = null;

    public function attachToCallSession(AgentVoiceCall $agentVoiceCall, bool $async = false): void
    {
        $this->attachRequests[] = [
            'callId' => $agentVoiceCall->id,
            'async' => $async,
            'rightsRestrictionsApplied' => DatabaseRepoEntity::getApplyRightsRestrictions(),
        ];
        if ($this->attachFailure !== null) {
            throw $this->attachFailure;
        }
    }
}
