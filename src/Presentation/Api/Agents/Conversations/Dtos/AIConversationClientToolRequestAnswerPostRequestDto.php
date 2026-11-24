<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Domain\AI\Entities\ClientTools\AgentClientToolRequest;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The answer of the user's client to a client tool request of `Agent.UI` (the request came with the run-state read).
 */
class AIConversationClientToolRequestAnswerPostRequestDto extends RequestDto
{
    /** @var int The id of the conversation */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $aiConversationId;

    /** @var int The id of the request */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $requestId;

    /** @var string The answering client (the client id the request was addressed to) */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $clientId;

    /** @var string `OK` for a request carried out, else the client's refusal code (`UNSAVED_CHANGES`, `DIALOG_OPEN`, …) */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $outcome;

    /** @var string|null The answer (screen Markdown, the marked elements), at most 24,000 characters */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $text = null;

    /** @var string|null Small facts as one flat JSON object (route, title, counts), at most 1,024 characters */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $detailsJson = null;

    /** The longest answer text the route takes ({@see AgentClientToolRequest::MAX_ANSWER_TEXT_CHARS}) */
    public static function getMaxAnswerTextChars(): int
    {
        return AgentClientToolRequest::MAX_ANSWER_TEXT_CHARS;
    }
}
