<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\AI\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\NotNull;

/** Input of `getTaskRunReport`: the run whose report to read. */
class TaskRunReportGetRequestDto extends RequestDto
{
    /** @var int|null The run conversation's id (from getTaskRuns / getTaskSchedules) */
    #[Parameter(in: Parameter::BODY, required: true)]
    #[NotNull]
    public ?int $conversationId = null;
}
