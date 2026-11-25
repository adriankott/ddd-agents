<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Realtime;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A realtime message as a client receives it with its run-state read (the mailbox delivery of a host without a
 * transport): type, key, payload and how many seconds it still matters. A client acts on one type and key once.
 */
class AgentRealtimeMessageDelivery extends ValueObject
{
    /** @var string The registered type */
    public string $messageType;

    /** @var string The key within the type */
    public string $messageKey;

    /** @var string The payload: one JSON object */
    public string $payloadJson;

    /** @var int Seconds until the message stops mattering */
    public int $secondsLeft;

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->messageType . '|' . $this->messageKey);
    }
}
