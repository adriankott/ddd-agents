<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunOptions;
use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\AI\Services\Eval\AgentEvalRunnerService;

/**
 * The runner with only the agent's turn replaced: {@see driveAgent()} records the owner texts the conversation holds
 * when a turn would be driven, without a model call. Everything else — assembling and building the conversation, the
 * owner messages, the run of a case — is the runner's own.
 */
class AgentEvalRunnerServiceWithoutModelCallDouble extends AgentEvalRunnerService
{
    /** @var array<int, string[]> The owner texts of the conversation at each driven turn */
    public array $ownerTextsPerDrive = [];

    protected function driveAgent(AIConversation $conversation, AgentEvalScope $agentEvalScope, AgentEvalRunOptions $agentEvalRunOptions, bool $curateMemoryInline = false): void
    {
        $ownerTexts = [];
        foreach ($conversation->messages->getElements() as $message) {
            $ownerTexts[] = (string)$message->message;
        }
        $this->ownerTextsPerDrive[] = $ownerTexts;
    }
}
