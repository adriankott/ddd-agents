<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunOptions;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunOutcome;
use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\AI\Services\Eval\AgentEvalRunnerService;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use ReflectionClass;
use RuntimeException;

/**
 * The runner with the agent's run replaced: {@see run()} records the tool block the process holds at that moment and
 * answers a conversation without messages (or throws, when the test asks for it), and {@see driveAgent()} records the
 * seeded ids the options hold when a turn would be driven, without a model call; the harness calls, the restore
 * orchestration and the hooks are the runner's own. The skill catalog is the test's list of tool packages.
 */
class AgentEvalRunnerServiceWithoutAgentRunDouble extends AgentEvalRunnerService
{
    /** @var string|null Thrown by {@see run()} when set */
    public ?string $agentRunErrorMessage = null;

    /** @var array<int, string[]> The blocked tools of the process at each agent run */
    public array $blockedToolNamesPerAgentRun = [];

    /** @var string[] What happened in order: `agent run` and every executed tool, as the executor double saw them */
    public array $agentRuns = [];

    /** @var array<int, int[]> The seeded conversation ids the options held at each driven turn */
    public array $seededConversationIdsPerDrive = [];

    public function run(AgentEvalScope $agentEvalScope, AgentEvalCase $agentEvalCase, AgentEvalRunOptions $agentEvalRunOptions): AgentEvalRunOutcome
    {
        $this->applyToolBlockOfCase($agentEvalCase, $agentEvalRunOptions);
        $this->blockedToolNamesPerAgentRun[] = MCPToolExecutorService::$blockedToolNames;
        $this->agentRuns[] = $agentEvalCase->getPrompt();
        if ($this->agentRunErrorMessage !== null) {
            throw new RuntimeException($this->agentRunErrorMessage);
        }
        /** @var AIConversation $conversation */
        $conversation = (new ReflectionClass(AIConversation::class))->newInstanceWithoutConstructor();
        return AgentEvalRunOutcome::forConversation($conversation);
    }

    protected function driveAgent(AIConversation $conversation, AgentEvalScope $agentEvalScope, AgentEvalRunOptions $agentEvalRunOptions, bool $curateMemoryInline = false): void
    {
        $this->seededConversationIdsPerDrive[] = $agentEvalRunOptions->getSeededConversationIds();
    }

    protected function getPackageClassesOfEverySkill(): array
    {
        return [MCPToolExecutorService::class];
    }
}
