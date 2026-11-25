<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Entities\Voice\Briefing;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceSeedInputMessage;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceSeedInputMessages;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceSessionContext;

/**
 * An application's subclass of the briefing: one instruction block, one seed row and one more placeholder of the
 * opening block (which the package's prompt file does not use), through the context's three override points.
 */
class ApplicationAgentVoiceSessionContext extends AgentVoiceSessionContext
{
    public ?string $workspaceName = null;

    protected function buildApplicationInstructionBlocks(): array
    {
        return $this->workspaceName !== null ? ['The call is about the workspace ' . $this->workspaceName . '.', ''] : [];
    }

    protected function buildApplicationSeedInputMessages(): AgentVoiceSeedInputMessages
    {
        return $this->workspaceName !== null
            ? AgentVoiceSeedInputMessages::fromMessages(AgentVoiceSeedInputMessage::create('developer', 'Workspace notes of ' . $this->workspaceName))
            : new AgentVoiceSeedInputMessages();
    }

    protected function getOpeningPromptParameters(): array
    {
        return [...parent::getOpeningPromptParameters(), 'WORKSPACE_NAME' => (string)$this->workspaceName];
    }
}
