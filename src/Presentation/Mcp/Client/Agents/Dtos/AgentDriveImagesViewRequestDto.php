<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Infrastructure\Validation\Constraints\Choice;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `viewImages`: pictures of any file source, shown to the model. */
class AgentDriveImagesViewRequestDto extends RequestDto
{
    /** @var string Long edge 512 px */
    public const string SIZE_SMALL = 'SMALL';

    /** @var string Long edge 1024 px (default) */
    public const string SIZE_MEDIUM = 'MEDIUM';

    /** @var string Long edge 2048 px: small text or fine detail */
    public const string SIZE_LARGE = 'LARGE';

    /** @var int[] The long edge of each size in pixels */
    public const array MAX_EDGE_PIXELS = [self::SIZE_SMALL => 512, self::SIZE_MEDIUM => 1024, self::SIZE_LARGE => 2048];

    /** @var string[] The pictures: file references (`media/901`, `drive/812`) or drive paths (required, at most 4) */
    #[Parameter(in: Parameter::BODY, required: true)]
    public array $files = [];

    /** @var string|null SMALL, MEDIUM (default) or LARGE (small text, fine detail) */
    #[Parameter(in: Parameter::BODY, required: false)]
    #[Choice(choices: [self::SIZE_SMALL, self::SIZE_MEDIUM, self::SIZE_LARGE])]
    public ?string $size = null;
}
