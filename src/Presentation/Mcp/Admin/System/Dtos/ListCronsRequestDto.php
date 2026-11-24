<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\System\Dtos;

use DDD\Presentation\Mcp\Admin\Common\Dtos\AdminListRequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Which crons to list. */
class ListCronsRequestDto extends AdminListRequestDto
{
    /** @var string|null Only crons in this state: OK, FAILING (last run failed), OVERDUE, RUNNING, PAUSED, MISSING_COMMAND */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $status = null;

    /** @var string|null A part of the name or of the command, e.g. "import" */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $searchText = null;
}
