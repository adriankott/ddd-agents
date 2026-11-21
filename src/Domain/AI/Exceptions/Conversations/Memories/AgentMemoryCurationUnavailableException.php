<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Exceptions\Conversations\Memories;

use RuntimeException;

/**
 * The curation's model gave no answer at all after its retries (an outage, a rate limit): the conversation's new
 * messages stay unharvested, so the curation message is dispatched again later. An answer of the wrong shape is no such
 * case: it is logged and the messages count as harvested.
 */
class AgentMemoryCurationUnavailableException extends RuntimeException
{
}
