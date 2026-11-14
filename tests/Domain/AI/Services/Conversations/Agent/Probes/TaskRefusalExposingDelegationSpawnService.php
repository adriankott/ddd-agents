<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\Probes;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentSubAgentRequest;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRefusal;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationSpawnService;

/** The spawn with its refusal of a model-requested task askable directly; nothing is spawned. */
class TaskRefusalExposingDelegationSpawnService extends AIConversationDelegationSpawnService
{
    public function callFindRefusalOfModelRequestedTask(
        AgentRun $agentRun,
        AIConversation $parentConversation,
        AgentSubAgentRequest $agentSubAgentRequest,
        ToolCall $delegateToolCall,
        bool $async
    ): ?DelegationSubAgentRefusal {
        return $this->findRefusalOfModelRequestedTask($agentRun, $parentConversation, $agentSubAgentRequest, $delegateToolCall, $async);
    }
}
