<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedules;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use DDD\Presentation\Base\QueryOptions\DtoQueryOptions;
use DDD\Presentation\Base\QueryOptions\DtoQueryOptionsTrait;

/**
 * Input of `getTaskSchedules`: the task schedules of one space (or, without a space, of the account), narrowed by query
 * options. The scope and the hiding of ARCHIVED schedules are mandatory filters the call cannot escape.
 */
#[DtoQueryOptions(baseEntity: AIConversationSchedules::class)]
class TaskSchedulesGetRequestDto extends RequestDto
{
    use DtoQueryOptionsTrait;

    /** @var bool|null Also include ARCHIVED schedules (deleted after they ran — hidden by default) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?bool $includeArchived = null;

    /** @var int|null The space whose schedules to list; omit it for the space this conversation works for */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $agentSpaceId = null;
}
