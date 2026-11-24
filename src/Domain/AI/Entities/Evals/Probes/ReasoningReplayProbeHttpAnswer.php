<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\Probes;

use DDD\Domain\Base\Entities\ValueObject;

/** The raw answer to one probe request: status and body, or the transport error. */
class ReasoningReplayProbeHttpAnswer extends ValueObject
{
    /** @var int|null The HTTP status; null when the transport failed */
    public ?int $statusCode = null;

    /** @var string The response body as received */
    public string $rawBody = '';

    /** @var string|null The transport error (timeout, refused, …); null when an answer arrived */
    public ?string $transportError = null;

    /** @var int Wall time in milliseconds */
    public int $durationMs = 0;
}
