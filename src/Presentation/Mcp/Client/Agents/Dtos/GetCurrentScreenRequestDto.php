<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** What getCurrentScreen reads. */
class GetCurrentScreenRequestDto extends RequestDto
{
    /** @var string|null One reference of the last read to read in full (a long text, all columns of a table), e.g. f.description */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $focus = null;

    /** @var string|null `text` (default: Markdown with references) or `picture` (an image of the screen, for layout, colours and charts the text cannot carry) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $format = null;

    /** @var string|null For a picture: `small`, `medium` (default) or `large` */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $pictureSize = null;

    /** @var string|null For a picture: `colour` (default), `greyscale`, or `machine` (64 grey levels, smaller, for reading the layout) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $renderMode = null;
}
