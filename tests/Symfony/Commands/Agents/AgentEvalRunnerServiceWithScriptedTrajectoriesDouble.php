<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalEvictionTurns;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunOptions;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunOutcome;
use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\AI\Services\Eval\AgentEvalRunnerService;
use DDD\Domain\Common\Entities\Accounts\Account;
use ReflectionClass;
use RuntimeException;

/**
 * A runner that drives nothing: it resolves every case to a scope without a space, records what it was asked, and
 * answers each eviction trajectory with the scripted turns (a trajectory whose first prompt is
 * {@see FAILING_PROMPT} throws).
 */
class AgentEvalRunnerServiceWithScriptedTrajectoriesDouble extends AgentEvalRunnerService
{
    public const string FAILING_PROMPT = 'this trajectory fails';

    public const string FAILURE_MESSAGE = 'the agent space is gone';

    public const int OWNER_ACCOUNT_ID = 5;

    /** @var string[] The ids of the cases a scope was resolved for, in order */
    public array $resolvedCaseIds = [];

    /** @var int[] The fallback spaces the scope resolution got, in order (0 for none) */
    public array $fallbackAgentSpaceIds = [];

    /** @var ContextRetentionSettings[] The settings each trajectory ran on */
    public array $settingsOfTrajectories = [];

    /** @var string[] The model each trajectory ran on ('' for the default) */
    public array $modelNamesOfTrajectories = [];

    public function __construct(public AgentEvalEvictionTurns $scriptedTurns)
    {
    }

    public function resolveEvalScope(AgentEvalCase $agentEvalCase, ?int $fallbackAgentSpaceId = null): AgentEvalScope
    {
        $this->resolvedCaseIds[] = $agentEvalCase->getId();
        $this->fallbackAgentSpaceIds[] = $fallbackAgentSpaceId ?? 0;
        $ownerAccount = (new ReflectionClass(Account::class))->newInstanceWithoutConstructor();
        $ownerAccount->id = self::OWNER_ACCOUNT_ID;
        return AgentEvalScope::forAgentSpace(null, $ownerAccount);
    }

    public function runEvictionTrajectory(AgentEvalScope $agentEvalScope, array $prompts, ContextRetentionSettings $contextRetentionSettings, AgentEvalRunOptions $agentEvalRunOptions): AgentEvalRunOutcome
    {
        if (($prompts[0] ?? '') === self::FAILING_PROMPT) {
            throw new RuntimeException(self::FAILURE_MESSAGE);
        }
        $this->settingsOfTrajectories[] = $contextRetentionSettings;
        $this->modelNamesOfTrajectories[] = $agentEvalRunOptions->aiModelName ?? '';
        /** @var AIConversation $conversation */
        $conversation = (new ReflectionClass(AIConversation::class))->newInstanceWithoutConstructor();
        $agentEvalRunOutcome = AgentEvalRunOutcome::forConversation($conversation);
        $agentEvalRunOutcome->recordEvictionTurnSet($this->scriptedTurns);
        return $agentEvalRunOutcome;
    }
}
