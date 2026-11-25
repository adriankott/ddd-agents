<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Realtime;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Who a realtime message is for ({@see AgentRealtimeMessage}): the open clients of one account, or the clients that
 * show one conversation (the root of its delegation tree) of that account.
 */
class AgentRealtimeAudience extends ValueObject
{
    /** @var int The account whose clients receive the message */
    public int $accountId;

    /** @var int|null The root conversation whose clients receive it; null = every open client of the account */
    public ?int $conversationId = null;

    public static function forAccount(int $accountId): static
    {
        $agentRealtimeAudience = new static();
        $agentRealtimeAudience->accountId = $accountId;
        return $agentRealtimeAudience;
    }

    public static function forConversation(int $accountId, int $rootConversationId): static
    {
        $agentRealtimeAudience = static::forAccount($accountId);
        $agentRealtimeAudience->conversationId = $rootConversationId;
        return $agentRealtimeAudience;
    }

    public function isConversationAudience(): bool
    {
        return $this->conversationId !== null;
    }

    /** The name of the audience's mailbox: `conversation-<id>` or `account-<id>` */
    public function getMailboxName(): string
    {
        return $this->conversationId !== null ? 'conversation-' . $this->conversationId : 'account-' . $this->accountId;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->getMailboxName());
    }
}
