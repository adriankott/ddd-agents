<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Domain\AI\Entities\Conversations\AIConversations;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use DDD\Presentation\Base\QueryOptions\DtoQueryOptions;
use DDD\Presentation\Base\QueryOptions\DtoQueryOptionsTrait;

/**
 * Input of `getTaskRuns`: the runs of every task schedule of one space (or, without a space, of the account), narrowed
 * by query options over the run conversations (`created` = the start moment, `scheduleId`, `runState`).
 */
#[DtoQueryOptions(baseEntity: AIConversations::class)]
class TaskRunsGetRequestDto extends RequestDto
{
    use DtoQueryOptionsTrait;

    /** @var int|null The space whose task runs to list; omit it for the space this conversation works for */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $agentSpaceId = null;
}
