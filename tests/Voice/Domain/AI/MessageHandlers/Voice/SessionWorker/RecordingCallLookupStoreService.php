<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\MessageHandlers\Voice\SessionWorker;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStoreService;

/** The call store as the handler polls it: the call appears after `$lookupsBeforeTheCallAppears` misses (null: never). */
class RecordingCallLookupStoreService extends AgentVoiceCallStoreService
{
    public ?AgentVoiceCall $agentVoiceCall = null;

    public ?int $lookupsBeforeTheCallAppears = 0;

    /** @var string[] The provider call ids looked up, in order */
    public array $lookedUpProviderCallIds = [];

    public function findCallByProviderCallId(string $providerCallId): ?AgentVoiceCall
    {
        $this->lookedUpProviderCallIds[] = $providerCallId;
        if ($this->lookupsBeforeTheCallAppears === null || count($this->lookedUpProviderCallIds) <= $this->lookupsBeforeTheCallAppears) {
            return null;
        }

        return $this->agentVoiceCall;
    }
}
