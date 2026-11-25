<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Realtime;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * One typed message of the realtime port ({@see \DDD\Domain\AI\Services\Conversations\Realtime\AgentRealtimeChannelService}):
 * a type a package registered ({@see AgentRealtimeMessageType}), a key that names it within its type and audience (a
 * second message of the same type and key replaces it), a payload of one JSON object and the moment it stops
 * mattering. A client receives it through the host's transport, or, without one, with its next run-state read.
 *
 * The payload is what the client needs to act (a client tool request's type and arguments, a call's ring); content a
 * client reads under rights stays behind its REST read.
 */
class AgentRealtimeMessage extends ValueObject
{
    /** @var string The registered type, e.g. `agent.ui.clientToolRequest` */
    public string $messageType;

    /** @var string The key of the message within its type and audience: letters, digits, `.`, `_`, `:`, `-` */
    public string $messageKey;

    /** @var string The payload: one JSON object */
    public string $payloadJson = '{}';

    /** @var DateTime When the message stops mattering; it is neither delivered nor kept after it */
    public DateTime $expiresAt;

    /** @var AgentRealtimeAudience Whose clients receive it */
    public AgentRealtimeAudience $audience;

    /** @var string|null The one client it is for (a client id); null = every client of the audience */
    public ?string $targetClientId = null;

    /** @var string A message key */
    public const string MESSAGE_KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:\-]{0,127}$/';

    public static function create(
        string $messageType,
        string $messageKey,
        string $payloadJson,
        DateTime $expiresAt,
        AgentRealtimeAudience $audience,
        ?string $targetClientId = null
    ): static {
        $agentRealtimeMessage = new static();
        $agentRealtimeMessage->messageType = $messageType;
        $agentRealtimeMessage->messageKey = $messageKey;
        $agentRealtimeMessage->payloadJson = $payloadJson;
        $agentRealtimeMessage->expiresAt = $expiresAt;
        $agentRealtimeMessage->audience = $audience;
        $agentRealtimeMessage->targetClientId = $targetClientId;
        return $agentRealtimeMessage;
    }

    public static function isValidMessageKey(string $messageKey): bool
    {
        return preg_match(static::MESSAGE_KEY_PATTERN, $messageKey) === 1;
    }

    public function isExpired(\DateTimeInterface $now): bool
    {
        return $this->expiresAt->getTimestamp() <= $now->getTimestamp();
    }

    /** Whole seconds until the message expires; 0 once it has */
    public function getSecondsLeft(\DateTimeInterface $now): int
    {
        return max(0, $this->expiresAt->getTimestamp() - $now->getTimestamp());
    }

    /** Whether a client receives it: a message for every client, or the one addressed to it */
    public function isForClient(?string $clientId): bool
    {
        return $this->targetClientId === null || $this->targetClientId === $clientId;
    }

    /** What a client receives */
    public function toDelivery(\DateTimeInterface $now): AgentRealtimeMessageDelivery
    {
        $agentRealtimeMessageDelivery = new AgentRealtimeMessageDelivery();
        $agentRealtimeMessageDelivery->messageType = $this->messageType;
        $agentRealtimeMessageDelivery->messageKey = $this->messageKey;
        $agentRealtimeMessageDelivery->payloadJson = $this->payloadJson;
        $agentRealtimeMessageDelivery->secondsLeft = $this->getSecondsLeft($now);
        return $agentRealtimeMessageDelivery;
    }

    /**
     * The form the mailbox keeps (an implementation detail of the shared cache, never returned across the port).
     *
     * @return array<string, string|int|null>
     */
    public function toMailboxEntry(): array
    {
        return [
            'messageType' => $this->messageType,
            'messageKey' => $this->messageKey,
            'payloadJson' => $this->payloadJson,
            'expiresAt' => $this->expiresAt->getTimestamp(),
            'accountId' => $this->audience->accountId,
            'conversationId' => $this->audience->conversationId,
            'targetClientId' => $this->targetClientId,
        ];
    }

    /**
     * A message from its mailbox form; null for an entry that is no message.
     *
     * @param mixed $mailboxEntry
     */
    public static function fromMailboxEntry(mixed $mailboxEntry): ?static
    {
        if (!is_array($mailboxEntry) || !is_string($mailboxEntry['messageType'] ?? null) || !is_string($mailboxEntry['messageKey'] ?? null)
            || !is_int($mailboxEntry['expiresAt'] ?? null) || !is_int($mailboxEntry['accountId'] ?? null)
        ) {
            return null;
        }
        $agentRealtimeAudience = is_int($mailboxEntry['conversationId'] ?? null)
            ? AgentRealtimeAudience::forConversation($mailboxEntry['accountId'], $mailboxEntry['conversationId'])
            : AgentRealtimeAudience::forAccount($mailboxEntry['accountId']);
        return static::create(
            $mailboxEntry['messageType'],
            $mailboxEntry['messageKey'],
            is_string($mailboxEntry['payloadJson'] ?? null) ? $mailboxEntry['payloadJson'] : '{}',
            DateTime::fromTimestamp($mailboxEntry['expiresAt']),
            $agentRealtimeAudience,
            is_string($mailboxEntry['targetClientId'] ?? null) ? $mailboxEntry['targetClientId'] : null
        );
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->messageType . '|' . $this->messageKey);
    }
}
