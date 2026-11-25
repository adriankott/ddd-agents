<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Api\Agents\Conversations;

use DDD\Domain\AI\Entities\ClientTools\AgentUIState;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\ClientTools\AgentClientPresenceService;
use DDD\Domain\AI\Services\Conversations\Realtime\AgentRealtimeChannelService;

/**
 * An application's controller with a presence service of its own (the override point
 * `getAgentClientPresenceService()`), and both stamps reachable: the account-level realtime read and the statement of
 * a client that shows a conversation.
 */
class AgentRealtimeReadOwnPresenceServiceFixtureController extends AgentAccountRealtimeReadFixtureController
{
    public function __construct(AgentRealtimeChannelService $agentRealtimeChannelService, public AgentClientPresenceService $ownAgentClientPresenceService)
    {
        parent::__construct($agentRealtimeChannelService);
    }

    protected function getAgentClientPresenceService(): AgentClientPresenceService
    {
        return $this->ownAgentClientPresenceService;
    }

    public function callStampUIClientOfConversation(AIConversation $conversation, ?string $clientId, ?AgentUIState $agentUIState, bool $isOwnerSend): void
    {
        $this->stampUIClientOfConversation($conversation, $clientId, $agentUIState, $isOwnerSend);
    }
}
