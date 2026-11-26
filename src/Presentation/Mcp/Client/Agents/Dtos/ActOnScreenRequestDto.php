<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The action actOnScreen carries out on the user's open screen. */
class ActOnScreenRequestDto extends RequestDto
{
    /** @var string|null (required) setFilters, sort, openRow, switchTab or press */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $action = null;

    /** @var string|null A reference of the newest screen read: col.… for sort, row.… for openRow, t.… for switchTab, act.… for press */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $reference = null;

    /** @var string[]|null For setFilters: key=value of the open screen's own filters (its Filters line), an empty value clears one, e.g. ["status=OPEN", "priority="] */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?array $filters = null;

    /** @var string|null For sort: asc, desc, or none for the list's own order */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $direction = null;

    /** @var bool|null True: the answer carries the screen after the action as getCurrentScreen reads it (saves the next call) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?bool $includeScreen = null;
}
