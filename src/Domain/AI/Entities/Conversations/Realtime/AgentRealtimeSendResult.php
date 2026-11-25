<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Realtime;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What became of a sent realtime message: handed to the host's transport, kept in the mailbox the run-state read
 * delivers from, both, or neither (no transport and no shared cache: no client can receive it).
 */
class AgentRealtimeSendResult extends ValueObject
{
    /** @var bool Whether the host's transport accepted it */
    public bool $sentThroughTransport = false;

    /** @var bool Whether the mailbox keeps it for the next run-state read */
    public bool $keptInMailbox = false;

    public function isReachable(): bool
    {
        return $this->sentThroughTransport || $this->keptInMailbox;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)spl_object_id($this));
    }
}
