<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Realtime;

use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeMessageTypes;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The port through which a package (or the host) registers its realtime message types: every service implementing it
 * is collected by its tag ({@see AgentRealtimeChannelService::setAgentRealtimeMessageTypeProviders()}). A package ships
 * its provider as a service in a public `…\Services\` namespace; autoconfiguration tags it. A type registered twice
 * keeps the first registration (container order) and is logged.
 */
#[AutoconfigureTag(AgentRealtimeMessageTypeProvider::TAG)]
interface AgentRealtimeMessageTypeProvider
{
    public const string TAG = 'ai.agent_realtime_message_type_provider';

    /** @return AgentRealtimeMessageTypes The types this provider registers */
    public function getRealtimeMessageTypes(): AgentRealtimeMessageTypes;
}
