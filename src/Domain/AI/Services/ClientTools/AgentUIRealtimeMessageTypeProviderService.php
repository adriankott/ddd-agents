<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\ClientTools;

use DDD\Domain\AI\Entities\ClientTools\AgentClientToolRequest;
use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeMessageType;
use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeMessageTypes;
use DDD\Domain\AI\Services\Conversations\Realtime\AgentRealtimeMessageTypeProvider;
use DDD\Infrastructure\Services\Service;

/**
 * The realtime message type of `Agent.UI`: a client tool request, addressed to one client of a conversation. Its
 * payload is the request as the client executes it (`id`, `requestType`, `argumentsJson`, `secondsLeft`), the same
 * shape the run-state read delivers in `clientToolRequest`.
 */
class AgentUIRealtimeMessageTypeProviderService extends Service implements AgentRealtimeMessageTypeProvider
{
    /** @var string The type of a client tool request */
    public const string MESSAGE_TYPE_CLIENT_TOOL_REQUEST = 'agent.ui.clientToolRequest';

    public function getRealtimeMessageTypes(): AgentRealtimeMessageTypes
    {
        $agentRealtimeMessageTypes = new AgentRealtimeMessageTypes();
        $clientToolRequestMessageType = AgentRealtimeMessageType::create(
            static::MESSAGE_TYPE_CLIENT_TOOL_REQUEST,
            AgentUIClientToolRequestTypeProviderService::REGISTERED_BY,
            'A client tool request of Agent.UI for the target client: execute it once (ClientToolRequestRunner) and post the answer.',
            AgentClientToolRequest::MAX_ARGUMENTS_JSON_CHARS + 512
        );
        $agentRealtimeMessageTypes->add($clientToolRequestMessageType);
        return $agentRealtimeMessageTypes;
    }
}
