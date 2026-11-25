<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\TaskPlanRecord;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;

/** A purpose that answers the connection state of a channel from a fixed map and records every read. */
class PurposeRecordingTheChannelsItReadsDouble extends AIConversationPurpose
{
    /** @var array<string, bool|null> */
    public array $connectionStateByChannel = [];

    /** @var string[] */
    public array $readChannels = [];

    /** @var int[] */
    public array $readAgentSpaceIds = [];

    public function findChannelConnectionStateOfAgentSpace(AgentSpace $agentSpace, string $connectedChannel): ?bool
    {
        $this->readChannels[] = $connectedChannel;
        $this->readAgentSpaceIds[] = $agentSpace->id;
        return $this->connectionStateByChannel[$connectedChannel] ?? null;
    }
}
