<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Domain\AI\Entities\Conversations\Subjects\AIConversationBoundSubject;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AIConversationsOfSubjectGetResponseDto extends AIConversationsGetResponseDto
{
    /**
     * @var AIConversationBoundSubject|null The record the list is about, as the application's describer names it
     * now (entity class, id, name and link; no purpose): what a client shows before a conversation about the record
     * exists
     */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?AIConversationBoundSubject $subject = null;
}
