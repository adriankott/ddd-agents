<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\System\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The error events over time: of an environment, or of one error group. */
class GetIssuesTrendRequestDto extends RequestDto
{
    /** @var string|null The environment, e.g. "prod"; default: the first one of the registry; "all": every watched environment summed */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $environment = null;

    /** @var int|null Only this error group (the #number of a listIssueGroups row) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $issueGroupId = null;

    /** @var string|null Only this error group, by its hash */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $groupHash = null;

    /** @var int|null Hours to look back, default 24, at most 744 (31 days) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $sinceHours = null;
}
