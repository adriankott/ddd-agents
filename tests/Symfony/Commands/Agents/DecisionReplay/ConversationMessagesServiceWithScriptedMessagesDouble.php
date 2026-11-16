<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\DecisionReplay;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\Base\Repo\DB\DBEntity;

/**
 * The messages service answering the whole-history read with the messages the test scripted per conversation id; it
 * records whether the entity rights were lifted while it was asked. Reads nothing.
 */
class ConversationMessagesServiceWithScriptedMessagesDouble extends AIConversationMessagesService
{
    /** @var array<int, AIConversationMessages> The messages by conversation id */
    public array $messagesByConversationId = [];

    /** @var bool[] Per read: whether the entity rights were applied */
    public array $rightsAppliedPerRead = [];

    public function findAllMessagesForConversationInOrder(
        AIConversation $aiConversation,
        ?int $offset = null,
        ?int $limit = null,
        bool $useEntityRegistrCache = true
    ): ?AIConversationMessages {
        $this->rightsAppliedPerRead[] = DBEntity::getApplyRightsRestrictions();
        return $this->messagesByConversationId[(int)$aiConversation->id] ?? null;
    }
}
