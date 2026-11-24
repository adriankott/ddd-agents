<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\System\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Which worker transport, and how far back its message statistics go. */
class GetWorkerTransportRequestDto extends RequestDto
{
    /** @var string|null (required) The name of the transport as getWorkersOverview shows it, e.g. "send_push_notification" */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $transport = null;

    /** @var int|null Hours of message statistics, default 24, at most 168 */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $sinceHours = null;
}
