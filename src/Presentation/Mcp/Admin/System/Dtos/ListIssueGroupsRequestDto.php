<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\System\Dtos;

use DDD\Presentation\Mcp\Admin\Common\Dtos\AdminListRequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Which error groups of the issues registry to list. */
class ListIssueGroupsRequestDto extends AdminListRequestDto
{
    /** @var string|null The environment, e.g. "prod"; default: the first one of the registry (the result names the others); "all": every environment */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $environment = null;

    /** @var string|null OPEN (default), RESOLVED, IGNORED or ALL */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $status = null;

    /** @var string|null A part of the error message, the exception type or the origin file */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $searchText = null;

    /** @var string|null Where the error happened: HTTP, CLI (commands and crons), MESSENGER (workers), WEB, WEB_SERVER or MOBILE */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $section = null;

    /** @var string|null Only groups first or last seen in this release (git sha) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $release = null;
}
