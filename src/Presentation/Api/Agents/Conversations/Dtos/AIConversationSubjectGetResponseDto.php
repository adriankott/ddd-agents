<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Domain\AI\Entities\Conversations\Subjects\AIConversationBoundSubject;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AIConversationSubjectGetResponseDto extends RestResponseDto
{
    /**
     * @var AIConversationBoundSubject|null The record the conversation is about (entity class, id, the purpose of a
     * purposed conversation, and the name and link the application's describer gave); null for a conversation
     * without a subject
     */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?AIConversationBoundSubject $subject = null;
}
