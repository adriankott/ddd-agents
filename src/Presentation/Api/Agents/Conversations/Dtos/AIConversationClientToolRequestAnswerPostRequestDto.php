<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Domain\AI\Entities\ClientTools\AgentClientToolRequest;
use DDD\Domain\AI\Entities\ClientTools\AgentUIState;
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

    /**
     * @var string|null A picture of the screen (base64 of WebP, JPEG or PNG; a `READ_SCREEN` request with
     * `format: picture`); checked as untrusted bytes: the type from the magic bytes, the size from the pinned decoder
     */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $pictureBase64 = null;

    /**
     * @var string|null What the client's UI shows after it carried out the request, as one JSON object of the shape of
     * `AgentUIState`; recorded as the client's statement (presence and the `[SIGNAL:ui]` note), a value that is no JSON
     * object is ignored
     */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $uiStateJson = null;

    /** The UI state the answer carries; null when it carries none */
    public function findUIState(): ?AgentUIState
    {
        // setPropertiesFromObject() takes its argument by reference: it needs a variable, never an expression
        $uiStateObject = json_decode((string)($this->uiStateJson ?? ''));
        if (!is_object($uiStateObject)) {
            return null;
        }
        $agentUIState = new AgentUIState();
        $agentUIState->setPropertiesFromObject($uiStateObject);
        return $agentUIState;
    }

    /** The longest answer text the route takes ({@see AgentClientToolRequest::MAX_ANSWER_TEXT_CHARS}) */
    public static function getMaxAnswerTextChars(): int
    {
        return AgentClientToolRequest::MAX_ANSWER_TEXT_CHARS;
    }
}
