<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\ClientTools;

use DDD\Domain\AI\Entities\ClientTools\AgentClientToolRequestTypes;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The port through which a package or the host registers client tool request types (`Agent.UI`): every service
 * implementing it is collected by its tag ({@see AgentClientToolRequestsService::setAgentClientToolRequestTypeProviders()}).
 * A host ships its provider as a service in a public `…\Services\` namespace; autoconfiguration tags it. The client
 * side executes a type the server registered through an adapter of its own (the npm runner's `customRequestHandlers`).
 * A type registered twice keeps the first registration.
 */
#[AutoconfigureTag(AgentClientToolRequestTypeProvider::TAG)]
interface AgentClientToolRequestTypeProvider
{
    public const string TAG = 'ai.agent_client_tool_request_type_provider';

    /** @return AgentClientToolRequestTypes The types this provider registers */
    public function getClientToolRequestTypes(): AgentClientToolRequestTypes;
}
