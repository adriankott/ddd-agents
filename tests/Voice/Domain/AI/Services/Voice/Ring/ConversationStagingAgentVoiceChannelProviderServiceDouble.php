<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Ring;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Voice\AgentVoiceChannelProviderService;

/**
 * The channel provider with its one database read replaced: the conversation the core asks about comes from
 * {@see $conversationsById} instead of the conversation table (the rights it lifts around that read cannot be
 * observed without the table).
 */
class ConversationStagingAgentVoiceChannelProviderServiceDouble extends AgentVoiceChannelProviderService
{
    /** @var array<int, AIConversation> */
    public array $conversationsById = [];

    /** @var int[] The ids looked up, in order */
    public array $lookedUpConversationIds = [];

    protected function findConversation(int $conversationId): ?AIConversation
    {
        $this->lookedUpConversationIds[] = $conversationId;
        return $this->conversationsById[$conversationId] ?? null;
    }
}
