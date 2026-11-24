<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\System\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Which cron, or which of its runs. */
class GetCronRequestDto extends RequestDto
{
    /** @var int|null The id of the cron (listCrons shows it). Pass it or cronExecutionId */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $cronId = null;

    /** @var int|null The id of one run (listCronExecutions shows it): the cron of that run, with the end of that output */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $cronExecutionId = null;
}
