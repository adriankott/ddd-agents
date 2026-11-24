<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Domain\AI\Entities\ClientTools\AgentUIState;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The run-state read of one conversation — the cheap metadata read a polling client repeats while a turn runs.
 */
class AIConversationRunStateGetRequestDto extends RequestDto
{
    /** @var int The id of the AIConversation. */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $aiConversationId;

    /** @var string|null The id of the reading client (random per mount of the agent window; `Agent.UI`) */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?string $clientId = null;

    /**
     * @var string|null What the client's UI shows, as one JSON object of the shape of `AgentUIState` (`Agent.UI`);
     * normalized on arrival, a value that is no JSON object is ignored
     */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?string $uiStateJson = null;

    /** The UI state the read carries; null when it carries none */
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
}
