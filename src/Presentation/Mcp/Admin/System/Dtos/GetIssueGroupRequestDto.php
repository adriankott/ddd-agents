<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\System\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Which error group to read. */
class GetIssueGroupRequestDto extends RequestDto
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
}
