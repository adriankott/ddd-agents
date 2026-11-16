<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Evals\AgentEvalEvictionTurns;
use DDD\Domain\AI\Services\Eval\AgentEvalRunnerService;
use DDD\Symfony\Commands\Agents\AgentEvalEvictionCommand;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The eviction command on a scripted runner, with its settings line and its trajectory printout callable from a test.
 */
class AgentEvalEvictionCommandWithScriptedRunner extends AgentEvalEvictionCommand
{
    public function __construct(public AgentEvalRunnerServiceWithScriptedTrajectoriesDouble $agentEvalRunnerServiceDouble)
    {
        parent::__construct();
    }

    public function printSettingsOf(ContextRetentionSettings $contextRetentionSettings, OutputInterface $output): void
    {
        $this->printSettings($contextRetentionSettings, $output);
    }

    public function renderTrajectoryOf(AgentEvalEvictionTurns $agentEvalEvictionTurns, ContextRetentionSettings $contextRetentionSettings, OutputInterface $output, bool $printsSnapshots): bool
    {
        return $this->renderTrajectory($agentEvalEvictionTurns, $contextRetentionSettings, $output, $printsSnapshots);
    }

    protected function getAgentEvalRunnerService(): AgentEvalRunnerService
    {
        return $this->agentEvalRunnerServiceDouble;
    }
}
