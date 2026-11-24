<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Result of creating a task schedule (one-off, one-time or recurring): its id, the run started now, the next firing and what to tell the user. */
class TaskScheduleCreateMcpResponseDto extends RestResponseDto
{
    /** @var int|null The created schedule's id */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?int $scheduleId = null;

    /** @var int|null The background run started right now (`startBackgroundTask` only) — its transcript fills as it works */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?int $conversationId = null;

    /** @var DateTime|null The next firing moment */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?DateTime $nextRunAt = null;

    /** @var string|null What to tell the user now */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $note = null;
}
