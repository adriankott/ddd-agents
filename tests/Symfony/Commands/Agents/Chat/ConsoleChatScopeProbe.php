<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\Chat;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Symfony\Commands\Agents\Chat\AgentConsoleChatScope;
use DDD\Symfony\Commands\Agents\Chat\AgentConsoleChatScopeTrait;

/** The console chat's conversation creation, callable without a command; the default model speaks the Responses API */
class ConsoleChatScopeProbe
{
    use AgentConsoleChatScopeTrait;

    /** @var string[] model name => wire API; a model not listed (the default, null) speaks the Responses API */
    public array $agentWireApiByModelName = [];

    public function buildNewConversationForTest(AgentConsoleChatScope $chatScope, ?string $modelName, ?string $requestedSkillLoadingStrategy): AIConversation
    {
        $this->modelNameOfNewConversation = $modelName;
        return $this->buildNewConversation($chatScope, $requestedSkillLoadingStrategy);
    }

    protected function resolveAgentWireApiOfNewConversation(): ?string
    {
        return $this->agentWireApiByModelName[$this->modelNameOfNewConversation ?? ''] ?? AIModel::AGENT_WIRE_API_RESPONSES;
    }
}
