<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations;

use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationSpawnService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;

/** The spawn service that admits no queued child and records every tree it is asked to offer freed slots to. */
class RecordingQueuePromotionSpawnService extends AIConversationDelegationSpawnService
{
    /** @var int[] The root conversation id of every call, in call order */
    public array $rootConversationIdsOfferedFreedSlots = [];

    /** The messages service this spawn service writes through instead of the container's; null = the container's */
    public ?AIConversationMessagesService $messagesServiceInsteadOfTheContainers = null;

    public function promoteQueuedDelegations(int $rootConversationId): array
    {
        $this->rootConversationIdsOfferedFreedSlots[] = $rootConversationId;
        return [];
    }

    protected function messagesService(): AIConversationMessagesService
    {
        return $this->messagesServiceInsteadOfTheContainers ?? parent::messagesService();
    }
}
