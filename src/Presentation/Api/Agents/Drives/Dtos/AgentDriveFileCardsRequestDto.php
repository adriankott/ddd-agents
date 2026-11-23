<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Drives\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The references of a `DriveFiles` widget block, to hydrate its cards */
class AgentDriveFileCardsRequestDto extends RequestDto
{
    /** @var string The block's drive store, e.g. `clouddrive` */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $store = '';

    /** @var string[] The block's references, e.g. `drive/812`; at most 20 */
    #[Parameter(in: Parameter::BODY, required: true)]
    public array $references = [];

    /** @var int|null The block's self-hydration key (the conversation's agent space) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $widgetSelfHydrationKey = null;
}
