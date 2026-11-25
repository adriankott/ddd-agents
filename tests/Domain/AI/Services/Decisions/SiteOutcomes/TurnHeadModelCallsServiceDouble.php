<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\ModelResponse;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationModelCallsService;

/**
 * The model calls of the turn head: the system prompt is empty, no tool is offered, and the first model call is
 * recorded and stops the loop ({@see TurnHeadStoppedAtTheModelCallException}). No model is asked.
 */
class TurnHeadModelCallsServiceDouble extends AIConversationModelCallsService
{
    public function __construct(public DecisionSiteWorldLog $worldLog)
    {
    }

    public function resolveBaseSystemPrompt(AIConversation $conversation): string
    {
        $this->worldLog->record('turn head: system prompt');
        return '';
    }

    public function buildOfferedToolSchemas(AIConversation $conversation, bool $allowThreads = true, ?AIConversationMessage $anchorMessage = null, ?AgentRun $agentRun = null): array
    {
        return [];
    }

    public function performModelCallOfStep(
        AIConversation $conversation,
        array $toolSchemas,
        string|array|null $forceToolChoice = null,
        ?string $modelNameOverride = null,
        string $baseSystemPrompt = '',
        ?AgentRun $agentRun = null
    ): ModelResponse {
        $this->worldLog->record('turn head: model call 1');
        throw new TurnHeadStoppedAtTheModelCallException('the turn head ends at the first model call');
    }
}
