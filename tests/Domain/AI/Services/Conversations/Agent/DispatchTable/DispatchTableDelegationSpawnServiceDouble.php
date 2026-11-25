<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\DispatchTable;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentTransientMessageIds;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Services\Conversations\Agent\AgentChildTurnRunnerInterface;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationSpawnService;
use DDD\Presentation\Mcp\Agents\AgentConsoleStream;
use Throwable;

/**
 * The sub-agent spawn of a model's `delegate` call, recorded instead of run: no child conversation, no worker. It
 * answers a fixed text and leaves the call's status where the real spawn leaves it (COMPLETED when started or queued,
 * FAILED when refused), or throws.
 */
class DispatchTableDelegationSpawnServiceDouble extends AIConversationDelegationSpawnService
{
    public const string SPAWN_ANSWER = 'fixed answer of the spawn';

    /** @var array<int, array{arguments: array<string, mixed>, async: bool, modelNameOverride: string|null}> */
    public array $calls = [];

    /** The status the spawn leaves on the call; null = untouched */
    public ?string $statusLeftOnTheCall = AIConversationMessage::STATUS_COMPLETED;

    public ?Throwable $throwOnSpawn = null;

    public function __construct()
    {
    }

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
        $this->calls[] = ['arguments' => $delegateToolCall->arguments, 'async' => $async, 'modelNameOverride' => $modelNameOverride];
        if ($this->throwOnSpawn !== null) {
            throw $this->throwOnSpawn;
        }
        if ($this->statusLeftOnTheCall !== null) {
            $delegateToolCall->status = $this->statusLeftOnTheCall;
        }
        return self::SPAWN_ANSWER;
    }
}
