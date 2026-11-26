<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Domain\AI\Entities\Conversations\Subjects\AIConversationBoundSubjects;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AIConversationBoundSubjectsGetResponseDto extends RestResponseDto
{
    /**
     * @var AIConversationBoundSubjects The records the listed conversations are bound to, one element per bound
     * conversation (`aiConversationId`, entity class, id, the purpose of a purposed conversation, name and link); a
     * conversation without a record has no element
     */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public AIConversationBoundSubjects $subjects;
}
