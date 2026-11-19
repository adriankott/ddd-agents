<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Api\Agents\Conversations;

use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationPostMessagePostRequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** An application's post-message request with one parameter of its own. */
class ApplicationPostMessageRequestDtoDouble extends AIConversationPostMessagePostRequestDto
{
    /** @var string|null The title of the page the owner writes from */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $pageTitle = null;
}
