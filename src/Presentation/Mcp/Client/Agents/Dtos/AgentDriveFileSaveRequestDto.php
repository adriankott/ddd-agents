<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `saveDriveFile`: files copied into a folder of another drive space (or of the own drive), on the server. */
class AgentDriveFileSaveRequestDto extends RequestDto
{
    /** @var string[] The files to copy: workspace paths (`reports/Zuordnung.xlsx`, `drivespaces/library/Brand/Logo.svg`) or references (`drive/<id>`, or `<source>/<id>` of another file source such as `media/901`); at most 50 */
    #[Parameter(in: Parameter::BODY, required: true)]
    public array $paths = [];

    /** @var string The target folder: `drivespaces/<space>/<folder>` or a folder of the own drive (`lib/brand`) */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $to = '';
}
