<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `viewDriveDocumentPages`: pages of a PDF of a drive as images. */
class AgentDriveDocumentPagesViewRequestDto extends RequestDto
{
    /** @var string The PDF: a path of the own drive, `drivespaces/<space>/…` or a reference (`drive/<id>`, or `<source>/<id>` of another file source) (required) */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $path = '';

    /** @var int[] The page numbers to look at (1 = the first page; required, at most 4 by default) */
    #[Parameter(in: Parameter::BODY, required: true)]
    public array $pages = [];
}
