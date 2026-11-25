<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Domain\AI\Entities\Conversations\Subjects\AIConversationSubject;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The start of a purposed conversation by the client ({@see \DDD\Presentation\Api\Agents\Conversations\Controller\AgentConversationPurposeStartTrait::startPurpose()}).
 */
class AIConversationStartPurposePostRequestDto extends RequestDto
{
    /** @var string The name of the purpose, e.g. `Support.AnswerTicket` (a registered purpose that may be started through the API) */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $purposeId;

    /**
     * @var int|null The id of the space the purpose runs in. A purpose always runs in a space: an application whose
     * route names none answers with its own space ({@see \DDD\Presentation\Api\Agents\Conversations\Controller\AgentConversationControllerHooksTrait::findAgentSpaceOfPurposeStartRequest()}).
     */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $agentSpaceId = null;

    /**
     * @var AIConversationSubject|null The record the conversation works on (the record's entity class as its
     * `objectType` and its id). With it the open conversation of the same space, purpose and record is reopened;
     * without it the one of the space and purpose. A record the caller cannot read answers 404.
     */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?AIConversationSubject $subject = null;

    /**
     * @var string|null Text added to the purpose's kickoff of a NEW conversation (e.g. what the user asked for); not
     * delivered when an open conversation is reopened (`prefillDelivered` false). At most 4,000 characters.
     */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $prefill = null;
}
