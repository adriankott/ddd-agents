<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** What highlightOnScreen marks. */
class HighlightOnScreenRequestDto extends RequestDto
{
    /** @var string[]|null (required) 1 to 5 references of the newest screen read (f.endDate, row.12, b.save, s.texts) or anchors (a.order.save) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?array $references = null;

    /** @var string|null A short note beside the first mark, at most 120 characters, plain text */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $note = null;
}
