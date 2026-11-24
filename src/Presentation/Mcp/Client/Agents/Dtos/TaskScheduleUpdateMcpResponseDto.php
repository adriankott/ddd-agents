<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Result of changing, pausing or resuming a task schedule: the unchanged id, the next firing and what to tell the user. */
class TaskScheduleUpdateMcpResponseDto extends RestResponseDto
{
    /** @var int|null The schedule's id (unchanged — the run history stays attached) */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?int $scheduleId = null;

    /** @var DateTime|null The next firing moment after the change; null when it does not fire */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?DateTime $nextRunAt = null;

    /** @var string|null What to tell the user now */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $note = null;
}
