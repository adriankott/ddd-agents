<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use RuntimeException;

/** Thrown by the model-call double at the first model call: the turn head is over, the test stops the loop there. */
class TurnHeadStoppedAtTheModelCallException extends RuntimeException
{
}
