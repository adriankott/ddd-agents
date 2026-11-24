<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The screen openScreen opens. */
class OpenScreenRequestDto extends RequestDto
{
    /** @var string|null (required) A key from listScreens, e.g. order or maintenance.crons */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $target = null;

    /** @var int|null The record id of a record screen (an order, a customer, …) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $recordId = null;

    /** @var string|null One of the target's tabs, e.g. items */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $tab = null;

    /** @var string[]|null Filters of a list screen as key=value, keys from listScreens, e.g. ["customerId=3"] */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?array $filters = null;

    /** @var bool|null True: the answer carries the opened screen as getCurrentScreen reads it (saves the next call) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?bool $includeScreen = null;
}
