<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\AI\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `listDriveFiles`: one folder of the own drive or of a mounted space, without a download. */
class AgentDriveFilesListRequestDto extends RequestDto
{
    /** @var string|null A folder or file: a workspace path (`reports`, `drivespaces/library/Brand`) or a reference `drive/<id>`; omit it for the own drive's root, `drivespaces` lists the mounted spaces */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $path = null;

    /** @var string|null The `nextCursor` of the previous page */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $cursor = null;

    /** @var int|null Entries per page (default 100, at most 500) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $limit = null;
}
