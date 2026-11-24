<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\System\Dtos;

use DDD\Presentation\Mcp\Admin\Common\Dtos\AdminListRequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Which runs of the crons to list. */
class ListCronExecutionsRequestDto extends AdminListRequestDto
{
    /** @var int|null Only runs of this cron (listCrons shows the id); default: every cron */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $cronId = null;

    /** @var string|null ALL (default), FAILED, SUCCESSFUL or RUNNING */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $result = null;
}
