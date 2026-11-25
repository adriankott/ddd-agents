<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Voice;

use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeMessageType;
use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeMessageTypes;
use DDD\Domain\AI\Services\Conversations\Realtime\AgentRealtimeMessageTypeProvider;
use DDD\Infrastructure\Services\Service;

/**
 * The realtime message type of the voice channel: the ring of a call, addressed to every open client of one account.
 * Its key is the call id; its payload is what a client needs to show the incoming call and to answer it (`callId`,
 * `conversationId`, `answerToken`, `ringExpiresAt`, `ringExpiresAtUnixMs`, `callerLabelKey`, `callerContext`), built by
 * {@see AgentVoiceRingService::buildRingPayload()} and rendered by
 * {@see \DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceRingPayload::toPayloadJson()}. Nothing of the conversation's
 * content is in it.
 */
class AgentVoiceRealtimeMessageTypeProviderService extends Service implements AgentRealtimeMessageTypeProvider
{
    /** @var string The type of a ring */
    public const string MESSAGE_TYPE_RING = 'agent.voice.ring';

    /** @var string Who registers the type: the voice channel */
    public const string REGISTERED_BY = 'agent.voice';

    public function getRealtimeMessageTypes(): AgentRealtimeMessageTypes
    {
        $agentRealtimeMessageTypes = new AgentRealtimeMessageTypes();
        $ringMessageType = AgentRealtimeMessageType::create(
            static::MESSAGE_TYPE_RING,
            static::REGISTERED_BY,
            'The agent rings the owner: show the incoming call until ringExpiresAt, answer or decline it with the answerToken, and stop when the message is cleared.'
        );
        $agentRealtimeMessageTypes->add($ringMessageType);
        return $agentRealtimeMessageTypes;
    }
}
