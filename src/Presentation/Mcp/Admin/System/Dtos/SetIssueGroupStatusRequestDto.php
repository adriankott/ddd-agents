<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\System\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The new status of one error group. */
class SetIssueGroupStatusRequestDto extends RequestDto
{
    /** @var int|null The id of the error group (the #number of a listIssueGroups row). Pass it or groupHash */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $issueGroupId = null;

    /** @var string|null The hash of the error group, as an alert or the issues screen shows it */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $groupHash = null;

    /** @var string|null Only with groupHash, when the hash exists in more than one environment */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $environment = null;

    /** @var string|null (required) The status the operator asked for: RESOLVED (fixed; a new event reopens it as a regression), IGNORED (never alerts again) or OPEN (reopen). A request to delete a group names no status: ask, do not pick one */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $status = null;
}
