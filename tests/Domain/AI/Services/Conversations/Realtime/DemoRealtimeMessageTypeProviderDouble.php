<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Realtime;

use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeMessageType;
use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeMessageTypes;
use DDD\Domain\AI\Services\Conversations\Realtime\AgentRealtimeMessageTypeProvider;

/** Another package's message types: one valid, one with an invalid name, one registered twice */
class DemoRealtimeMessageTypeProviderDouble implements AgentRealtimeMessageTypeProvider
{
    public const string MESSAGE_TYPE_RING = 'demo.voice.ring';

    public function getRealtimeMessageTypes(): AgentRealtimeMessageTypes
    {
        $agentRealtimeMessageTypes = new AgentRealtimeMessageTypes();
        foreach ([
            AgentRealtimeMessageType::create(static::MESSAGE_TYPE_RING, 'demo.voice', 'A call rings.', 256),
            AgentRealtimeMessageType::create('Not A Name', 'demo.voice', 'Skipped.'),
            AgentRealtimeMessageType::create(static::MESSAGE_TYPE_RING, 'demo.voice', 'Registered twice: the first stays.', 9999),
        ] as $agentRealtimeMessageType) {
            $agentRealtimeMessageTypes->add($agentRealtimeMessageType);
        }
        return $agentRealtimeMessageTypes;
    }
}
