<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Exceptions\Voice;

use DDD\Infrastructure\Exceptions\InternalErrorException;

/**
 * The provider session a worker was asked to accompany no longer exists at the provider (the attach answered that the
 * session id is unknown): the call is over, or its session was never created. Permanent by nature: the attach handler
 * turns it into an unrecoverable Messenger failure, so that the transport does not retry into a session that is gone.
 */
class LiveSessionGoneException extends InternalErrorException
{
}
