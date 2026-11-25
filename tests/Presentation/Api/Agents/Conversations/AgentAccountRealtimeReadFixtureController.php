<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Api\Agents\Conversations;

use DDD\Domain\AI\Services\Conversations\Realtime\AgentRealtimeChannelService;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AgentAccountRealtimeMessagesGetRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AgentAccountRealtimeMessagesGetResponseDto;

/** The traits' controller with the realtime port the test binds (a fake transport over a shared cache double). */
class AgentAccountRealtimeReadFixtureController extends AgentConversationTraitsFixtureController
{
    public function __construct(public AgentRealtimeChannelService $agentRealtimeChannelService)
    {
    }

    protected function getAgentRealtimeChannelService(): AgentRealtimeChannelService
    {
        return $this->agentRealtimeChannelService;
    }

    public function callBuildAccountRealtimeMessagesResponse(AgentAccountRealtimeMessagesGetRequestDto $requestDto): AgentAccountRealtimeMessagesGetResponseDto
    {
        return $this->buildAccountRealtimeMessagesResponse($requestDto);
    }
}
