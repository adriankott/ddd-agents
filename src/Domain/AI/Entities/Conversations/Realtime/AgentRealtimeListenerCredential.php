<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Realtime;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * What a client needs to listen on the host's realtime transport (a token of a realtime database, the path it may
 * read). Issued or reused by the host's transport binding
 * ({@see \DDD\Domain\AI\Services\Conversations\Realtime\AgentRealtimeChannelService::findListenerCredential()}); the
 * package itself has no transport and issues none.
 */
class AgentRealtimeListenerCredential extends ValueObject
{
    /** @var string The transport the credential is for (`firebase`, `websocket`, …) */
    public string $transportName;

    /** @var string The credential itself (a token) */
    public string $credential;

    /** @var string|null Where the client listens (a path, a channel name); null when the credential names it */
    public ?string $listenPath = null;

    /** @var DateTime|null When the credential stops working; the client asks again before */
    public ?DateTime $expiresAt = null;

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->transportName . '|' . ($this->listenPath ?? ''));
    }
}
