<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\NotNull;

/** Input of `pauseTaskSchedule`, `resumeTaskSchedule` and `deleteTaskSchedule`: which task schedule. */
class TaskScheduleIdRequestDto extends RequestDto
{
    /** @var int|null The schedule id (from getTaskSchedules) */
    #[Parameter(in: Parameter::BODY, required: true)]
    #[NotNull]
    public ?int $scheduleId = null;
}
