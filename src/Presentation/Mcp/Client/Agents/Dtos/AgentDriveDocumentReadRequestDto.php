<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `readDriveDocument`: the text of a document of a drive, page by page. */
class AgentDriveDocumentReadRequestDto extends RequestDto
{
    /** @var string The document: a path of the own drive, `drivespaces/<space>/…` or a reference (`drive/<id>`, or `<source>/<id>` of another file source) (required) */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $path = '';

    /** @var int|null The first page (sheet, slide) to read; default 1. A longer read names the next page */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $fromPage = null;

    /** @var int|null The last page to read; default: as many as fit into one read */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $toPage = null;
}
