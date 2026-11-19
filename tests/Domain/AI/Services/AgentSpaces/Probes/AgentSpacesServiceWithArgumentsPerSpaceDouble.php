<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\AgentSpaces\Probes;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;

/** An application whose spaces fill a different argument by kind: space {@see PROJECT_SPACE_ID} fills `projectId`. */
class AgentSpacesServiceWithArgumentsPerSpaceDouble extends AgentSpacesService
{
    public const int PROJECT_SPACE_ID = 9;

    public const string PROJECT_ARGUMENT = 'projectId';

    /** @var array<int, array{0: int|null, 1: int|null}> the conversation id and the space id of each question */
    public array $askedConversationAndSpaceIds = [];

    public function getDescriptionPrefixesOfToolArgumentsFilledFromAgentSpaceOfConversation(AIConversation $conversation, ?AgentSpace $agentSpace): array
    {
        $this->askedConversationAndSpaceIds[] = [$conversation->id ?? null, $agentSpace?->id];
        if ((int)($agentSpace?->id ?? 0) === static::PROJECT_SPACE_ID) {
            return [static::PROJECT_ARGUMENT => 'OPTIONAL — the project of this conversation.'];
        }
        return parent::getDescriptionPrefixesOfToolArgumentsFilledFromAgentSpaceOfConversation($conversation, $agentSpace);
    }

    public function getToolArgumentNamesLeftOutOfTheReadDedupLine(): array
    {
        return [...parent::getToolArgumentNamesLeftOutOfTheReadDedupLine(), static::PROJECT_ARGUMENT];
    }
}
