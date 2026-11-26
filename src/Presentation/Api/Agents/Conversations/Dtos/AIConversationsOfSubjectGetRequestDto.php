<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Domain\AI\Entities\Conversations\AIConversations;
use DDD\Domain\AI\Entities\Conversations\Subjects\AIConversationSubject;
use DDD\Presentation\Api\Agents\QueryOptions\DtoQueryOptionsOfApplicationClassTrait;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use DDD\Presentation\Base\QueryOptions\DtoQueryOptions;

/**
 * The caller's conversations about one record: the record by its entity class and id, and the query options of the
 * conversations list (`orderBy`, `top`, `skip`, `select`).
 */
#[DtoQueryOptions(baseEntity: AIConversations::class)]
class AIConversationsOfSubjectGetRequestDto extends RequestDto
{
    use DtoQueryOptionsOfApplicationClassTrait;

    /** @var string The fully qualified entity class of the record, e.g. `App\Domain\Support\Entities\Tickets\SupportTicket` */
    #[Parameter(in: Parameter::QUERY, required: true)]
    public string $subjectEntityClass = '';

    /** @var int The id of the record */
    #[Parameter(in: Parameter::QUERY, required: true)]
    public int $subjectEntityId = 0;

    /** The record the request names. */
    public function toSubject(): AIConversationSubject
    {
        return AIConversationSubject::of($this->subjectEntityClass, $this->subjectEntityId);
    }
}
