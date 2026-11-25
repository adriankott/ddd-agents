<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\LoopExits;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentTransientMessageIds;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Services\Conversations\Agent\AgentChildTurnRunnerInterface;
use DDD\Presentation\Mcp\Agents\AgentConsoleStream;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\RunRelease\RunReleaseDelegationSpawnServiceDouble;

/**
 * The `delegate` spawn of the loop-exit stage: the model's call starts its sub-agent (recorded with whether it runs
 * async, never run) and the call's status is final; the queue promotion is recorded as in the release stage.
 */
class LoopExitDelegationSpawnServiceDouble extends RunReleaseDelegationSpawnServiceDouble
{
    public const string SPAWN_ANSWER = 'Sub-agent #1 «research A» started (running). Its result arrives as a notification when it ends.';

    public function spawnSubAgentOfModelCall(
        AgentRun $agentRun,
        AgentChildTurnRunnerInterface $agentChildTurnRunner,
        AIConversation $parentConversation,
        ToolCall $delegateToolCall,
        AIConversationMessage $delegateCallMessage,
        AgentTransientMessageIds $transientMessageIds,
        ?string $modelNameOverride,
        ?AgentConsoleStream $consoleStream,
        bool $async
    ): string {
        $this->eventLog->record('delegate async=' . ($async ? 'yes' : 'no'));
        $delegateToolCall->markCompleted();
        return self::SPAWN_ANSWER;
    }
}
