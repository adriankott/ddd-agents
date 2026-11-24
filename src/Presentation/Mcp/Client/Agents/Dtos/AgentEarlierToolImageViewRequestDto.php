<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\NotBlank;

/** Input of `viewEarlierToolImage`: the picture of an earlier tool result that left the context. */
class AgentEarlierToolImageViewRequestDto extends RequestDto
{
    /** @var string The image id of the placeholder line (`[image <id>, …]`, the first 12 characters of its sha256) */
    #[Parameter(in: Parameter::BODY, required: true)]
    #[NotBlank]
    public string $imageId = '';
}
