<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\AI\Services\Eval\AgentEvalRunnerService;
use DDD\Symfony\Commands\Agents\AgentEvalScheduleRunsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The schedule-runs command without a database: the scope option names a scripted space whose owner is or is not an
 * evaluation account, the firing is recorded instead of done, and the application's step instead of firing can be
 * switched on.
 */
class AgentEvalScheduleRunsCommandWithScriptedScope extends AgentEvalScheduleRunsCommand
{
    public const string COMMAND_NAME = 'app:agent:eval-schedule-runs';

    public const int AGENT_SPACE_ID = 9;

    public const int OWNER_ACCOUNT_ID = 5;

    public bool $ownerIsAnEvalAccount = true;

    public ?int $exitCodeInsteadOfFiring = null;

    /** @var string[] The tasks the command fired a schedule for */
    public array $firedTasks = [];

    public function __construct()
    {
        parent::__construct(self::COMMAND_NAME);
    }

    protected function resolveFallbackAgentSpaceId(InputInterface $input): ?int
    {
        return $this->hasScopeOption($input) ? self::AGENT_SPACE_ID : null;
    }

    protected function getAgentEvalRunnerService(): AgentEvalRunnerService
    {
        return new AgentEvalRunnerServiceWithScopeOfSpaceDouble(self::AGENT_SPACE_ID, self::OWNER_ACCOUNT_ID);
    }

    protected function isOwnerAnEvalAccount(AgentEvalScope $agentEvalScope): bool
    {
        return $this->ownerIsAnEvalAccount;
    }

    protected function runInsteadOfFiring(InputInterface $input, SymfonyStyle $symfonyStyle, AgentEvalScope $agentEvalScope): ?int
    {
        return $this->exitCodeInsteadOfFiring;
    }

    protected function fireAndPrintRuns(InputInterface $input, SymfonyStyle $symfonyStyle, AgentEvalScope $agentEvalScope, string $task): void
    {
        $this->firedTasks[] = $task;
    }
}
