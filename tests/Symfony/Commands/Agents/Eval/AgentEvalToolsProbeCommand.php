<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\Eval;

use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRow;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTask;
use DDD\Domain\AI\Services\Eval\AgentEvalRunnerService;
use DDD\Domain\AI\Services\Eval\AgentEvalSuiteRunsService;
use DDD\Symfony\Commands\Agents\AgentEvalToolsCommand;
use DDD\Symfony\Commands\Agents\Eval\AgentEvalToolsCaseRun;
use DDD\Symfony\Commands\Agents\Eval\AgentEvalToolsRunSettings;
use DDD\Tests\Agents\Domain\AI\Services\Eval\AgentEvalSuiteRunsServiceWithoutDatabaseDouble;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * The tools evaluation with its model calls and its database left out: a child process prints what the test scripted
 * for its case (a result document from a file, nothing, or it outlives its ceiling of one second); a case whose id
 * starts with {@see OWN_SHAPE_CASE_PREFIX} is of the probe's own shape and answers the row the test gave; the record of
 * the run is kept by a record service without a database; the applicability check is the test's runner.
 */
class AgentEvalToolsProbeCommand extends AgentEvalToolsCommand
{
    /** @var string The name of the command (the attribute of the module's class is not inherited) */
    public const string COMMAND_NAME = 'app:ai-agent:eval-tools';

    /** @var string The prefix of the ids of the cases the probe runs as its own shape */
    public const string OWN_SHAPE_CASE_PREFIX = 'own.';

    /** @var string The child of a case prints the result document of a file */
    public const string CHILD_PRINTS_DOCUMENT = 'document';

    /** @var string The child of a case prints nothing */
    public const string CHILD_PRINTS_NOTHING = 'nothing';

    /** @var string The child of a case outlives its ceiling */
    public const string CHILD_OUTLIVES_ITS_CEILING = 'ceiling';

    /** @var string[] Case id → what its child does (one of the `CHILD_*` constants) */
    public array $childBehaviourByCaseId = [];

    /** @var string The file whose content a child that prints a document prints, after a line of noise */
    public string $childDocumentFile = '';

    /** @var array<string, mixed> The fields the row of an own-shape case carries after its header */
    public array $ownShapeRowFields = [];

    public AgentEvalSuiteRunsServiceWithoutDatabaseDouble $suiteRunsServiceDouble;

    public ?AgentEvalRunnerService $runnerServiceDouble = null;

    public function __construct()
    {
        parent::__construct(self::COMMAND_NAME);
        $this->suiteRunsServiceDouble = new AgentEvalSuiteRunsServiceWithoutDatabaseDouble();
    }

    /** @var bool Whether a run without `--model` takes the scripted default model below instead of resolving the agent's */
    public bool $usesScriptedDefaultAgentModel = true;

    /** @var string|null The scripted default model: none, so a run without `--model` sees no images */
    public ?string $scriptedDefaultAgentModelName = null;

    protected function findDefaultAgentModelName(): ?string
    {
        return $this->usesScriptedDefaultAgentModel ? $this->scriptedDefaultAgentModelName : parent::findDefaultAgentModelName();
    }

    /** The agent's default model, as a run without `--model` uses it */
    public function findDefaultAgentModelNameOfARunWithoutModel(): ?string
    {
        return $this->findDefaultAgentModelName();
    }

    /** Whether a run without `--model` counts as one whose model can see images */
    public function canTheModelOfARunWithoutModelSeeImages(): bool
    {
        return $this->modelCanSeeImages(null);
    }

    /**
     * The child's command line as the command builds it.
     *
     * @return string[]
     */
    public function buildChildProcessArgumentsOfTask(AgentEvalSuiteTask $agentEvalSuiteTask, InputInterface $input, AgentEvalToolsRunSettings $runSettings): array
    {
        return $this->buildChildProcessArguments($agentEvalSuiteTask, $input, $runSettings);
    }

    protected function buildChildProcessOfTask(AgentEvalSuiteTask $agentEvalSuiteTask, InputInterface $input, AgentEvalToolsRunSettings $runSettings): Process
    {
        $childBehaviour = $this->childBehaviourByCaseId[$agentEvalSuiteTask->caseId] ?? self::CHILD_PRINTS_DOCUMENT;
        $childCode = match ($childBehaviour) {
            self::CHILD_PRINTS_NOTHING => 'echo "";',
            self::CHILD_OUTLIVES_ITS_CEILING => 'sleep(30);',
            default => 'echo "a line before the document\n" . file_get_contents(' . var_export($this->childDocumentFile, true) . ');',
        };
        $childProcess = new Process([PHP_BINARY, '-r', $childCode]);
        $childProcess->setTimeout($childBehaviour === self::CHILD_OUTLIVES_ITS_CEILING ? 1.0 : 60.0);
        return $childProcess;
    }

    protected function runCaseOfOwnShape(AgentEvalToolsRunSettings $runSettings, AgentEvalToolsCaseRun $agentEvalToolsCaseRun, OutputInterface $output): ?AgentEvalCaseRow
    {
        if (!str_starts_with($agentEvalToolsCaseRun->getCaseId(), self::OWN_SHAPE_CASE_PREFIX)) {
            return null;
        }
        return AgentEvalCaseRow::fromArray($agentEvalToolsCaseRun->buildRowHeader()->toArray() + $this->ownShapeRowFields);
    }

    protected function getSuiteRunsService(): AgentEvalSuiteRunsService
    {
        return $this->suiteRunsServiceDouble;
    }

    protected function getRunnerService(): AgentEvalRunnerService
    {
        return $this->runnerServiceDouble ?? parent::getRunnerService();
    }
}
