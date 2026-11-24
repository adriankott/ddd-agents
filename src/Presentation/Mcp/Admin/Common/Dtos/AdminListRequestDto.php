<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\Common\Dtos;

use DDD\Presentation\Mcp\Admin\Common\AdminToolResultLines;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The paging every find and list tool of the admin agent takes: `top` and `skip`, 25 rows by default, 50 at most.
 * A tool reads them through getPageSize() and getSkip(), never the raw properties.
 */
class AdminListRequestDto extends RequestDto
{
    /** @var int|null Rows per page, default 25, at most 50 */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $top = null;

    /** @var int|null Rows to skip (the result names the value for the next page) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $skip = null;

    /**
     * @return int
     */
    public function getPageSize(): int
    {
        return AdminToolResultLines::resolvePageSize($this->top);
    }

    /**
     * @return int
     */
    public function getSkip(): int
    {
        return AdminToolResultLines::resolveSkip($this->skip);
    }
}
