<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\Eval;

use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRow;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuitePlanSummary;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteRecord;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteRecordSettings;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTask;
use DDD\Symfony\Commands\Agents\AgentEvalSuiteCommand;
use DDD\Symfony\Commands\Agents\Eval\AgentEvalSuiteRunState;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The suite command of the module as a test drives it: with a child script set, every case runs that script in
 * place of the console (`php <script> <command name> <arguments>`); the protected steps the tests pin are reachable
 * from outside.
 */
class AgentEvalSuiteProbeCommand extends AgentEvalSuiteCommand
{
    /** @var string|null The script a child runs in place of the console; null = the console */
    public ?string $childScriptPath = null;

    protected function buildChildConsoleCommandLine(string $commandName): array
    {
        if ($this->childScriptPath === null) {
            return parent::buildChildConsoleCommandLine($commandName);
        }
        return [PHP_BINARY, $this->childScriptPath, $commandName];
    }

    /**
     * @param string[] $evalArms
     * @return string[]
     */
    public function exposeBuildProcessArguments(AgentEvalSuiteTask $agentEvalSuiteTask, InputInterface $input, string $resultFile, array $evalArms): array
    {
        return $this->buildProcessArguments($agentEvalSuiteTask, $input, $resultFile, $evalArms);
    }

    public function exposeWritePlan(OutputInterface $output, AgentEvalSuitePlanSummary $agentEvalSuitePlanSummary, AgentEvalSuiteRecordSettings $agentEvalSuiteRecordSettings): void
    {
        $this->writePlan($output, $agentEvalSuitePlanSummary, $agentEvalSuiteRecordSettings);
    }

    public function exposeWriteReport(OutputInterface $output, AgentEvalSuiteRecord $agentEvalSuiteRecord, string $suiteDirectory): void
    {
        $this->writeReport($output, $agentEvalSuiteRecord, $suiteDirectory);
    }

    public function exposeWriteFinishedCaseLines(OutputInterface $output, AgentEvalSuiteRunState $agentEvalSuiteRunState, AgentEvalSuiteTask $agentEvalSuiteTask, AgentEvalCaseRow $agentEvalCaseRow): void
    {
        $this->writeFinishedCaseLines($output, $agentEvalSuiteRunState, $agentEvalSuiteTask, $agentEvalCaseRow);
    }

    /** @return string[] */
    public function exposeRetriedInfrastructureErrors(): array
    {
        return $this->getRetriedInfrastructureErrors();
    }

    /** The state of a run whose cases started (a stop signal is counted from now on). */
    public function markCasesStarted(): void
    {
        $this->casesStarted = true;
    }

    /** A stop asked for before the run's first loop (as a signal while the cases start would). */
    public function requestStopBeforeTheRun(): void
    {
        $this->stopSignalCount = 1;
    }

    public function exposeDescribeStopRequest(int $droppedCaseCount): string
    {
        return $this->describeStopRequest($droppedCaseCount);
    }
}
