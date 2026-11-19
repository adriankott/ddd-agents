<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\AI\Entities\Evals\Guard\AgentEvalGuardCell;
use DDD\Domain\AI\Entities\Evals\Guard\AgentEvalGuardCellResult;
use DDD\Domain\AI\Entities\Evals\Guard\AgentEvalGuardCellResults;
use DDD\Domain\AI\Entities\Evals\Guard\AgentEvalGuardCells;
use DDD\Domain\AI\Entities\Evals\Guard\AgentEvalGuardReport;
use DDD\Domain\AI\Entities\Evals\Guard\AgentEvalGuardRunKnobs;
use DDD\Domain\AI\Repo\Argus\Guard\ArgusAgentSentinelCanaryGuard;
use DDD\Domain\AI\Services\Eval\AgentEvalResultFilesService;
use DDD\Domain\AI\Services\Eval\AgentEvalRunHooksService;
use DDD\Domain\AI\Services\Eval\AgentEvalRunnerService;
use DDD\Domain\AI\Services\Eval\AgentEvalSuitePlanService;
use DDD\Domain\Base\Entities\Entity;
use DDD\Infrastructure\Services\DDDService;
use DDD\Symfony\Commands\AI\ModelTierOptionTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The Sentinel-Canary guard probe: the fail-closed injection-detection evaluation, a standalone suite beside the agent
 * loop. For each guard fixture ({@see AgentEvalGuardCells}) it runs the matrix guard-prompt variants × inputs (benign
 * and attack vectors) × `--runs`. Each probe of a cell is one guard model call ({@see ArgusAgentSentinelCanaryGuard}):
 * the variant's system prompt and the inspected input give a raw text, and the cell passes the gate when the trimmed
 * text is exactly the fixture's pass-phrase (any deviation blocks). Two rates per model and variant
 * ({@see \DDD\Domain\AI\Entities\Evals\Guard\AgentEvalGuardVariantResult}): the false-block rate (FPR, benign inputs
 * blocked) and the bypass rate (ASR, attacks let through).
 *
 * Speed: `--parallel=N` splits the cells into N chunks, each run by a child process of this command (chunks, not one
 * process per cell, so the kernel boots N times), and merges what the children print. The cells are independent.
 *
 * Each probe is booked under its own evaluation envelope for the account that owns the run's agent space and billed to
 * the space's billing entity ({@see AgentEvalRunHooksService::findBillingEntityOfScope()}); the space is the one the
 * scope options name. The guard is built with {@see ArgusAgentSentinelCanaryGuard::newInstance()}, so an
 * application's class of the same path (its usage scope of the probe) is the one that runs. The fixtures are
 * `--fixture`, else every `*.json` of the configured fixture path `guard` (`AI.evalSuite.fixturePaths`); a run that
 * is saved writes `<date>_<time>-<model>-guard-sentinel.json` into the results folder.
 *
 * Live: real model calls. `--ignore-budget` lifts the budget cap, also for the children.
 */
#[AsCommand(
    name: 'app:ai-agent:eval-guard',
    description: 'Sentinel-Canary guard probe — fail-closed injection-detection eval (guard-prompt variants × attack vectors), per model, parallel.',
)]
class AgentEvalGuardCommand extends AgentEvalConsoleCommand
{
    use ModelTierOptionTrait;

    /** @var string The name of the configured fixture path of this command (`AI.evalSuite.fixturePaths`) */
    public const string FIXTURE_PATH_NAME = 'guard';

    /** @var string What a run file's name carries after the model */
    public const string RUN_FILE_NAME = 'guard-sentinel';

    /** @var int How the run file and a child's JSON are encoded (the slashes escaped, as the files have always been written) */
    public const int JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE;

    /** @var string The model name of a run on the configured default model */
    public const string DEFAULT_MODEL_NAME = 'default';

    /** @var float The seconds a child process may run */
    public const float CHILD_PROCESS_TIMEOUT_SECONDS = 1200.0;

    /** @var int The microseconds between two looks at the running children */
    public const int CHILD_PROCESS_POLL_MICROSECONDS = 100_000;

    /** @var int The longest raw answer a report line shows */
    public const int RAW_LINE_MAX_LENGTH = 240;

    protected function configure(): void
    {
        $this->configureEvalScopeOptions();
        $this->addOption('model', null, InputOption::VALUE_OPTIONAL, 'Pin the guard model by catalog name, e.g. MINIMAX.M3 or ALIBABA.QWEN3_235B_INSTRUCT.');
        $this->addModelTierOptions();
        $this->addOption('fixture', null, InputOption::VALUE_OPTIONAL, 'A single fixture JSON (default: every *.json of the configured fixture path `' . self::FIXTURE_PATH_NAME . '`).');
        $this->addOption('variant', null, InputOption::VALUE_OPTIONAL, 'Only this guard-prompt variant id.');
        $this->addOption('input', null, InputOption::VALUE_OPTIONAL, 'Only this input id.');
        $this->addOption('runs', null, InputOption::VALUE_OPTIONAL, 'Runs per cell (non-determinism sampling).', '1');
        $this->addOption('parallel', null, InputOption::VALUE_OPTIONAL, 'Run the cell matrix across N concurrent child processes (chunked).', '8');
        $this->addOption('full', null, InputOption::VALUE_NONE, 'Print every raw model output (not just the failures).');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable JSON instead of the report.');
        $this->addOption('no-save', null, InputOption::VALUE_NONE, 'Do not persist a run file (used internally by parallel children).');
        $this->addOption('cells', null, InputOption::VALUE_OPTIONAL, 'Internal: base64(json) list of cell keys a child must restrict to.');
        $this->addOption('guard-file', null, InputOption::VALUE_OPTIONAL, 'Override the fixture variants: test THIS guard system prompt (file path; may contain {passPhrase}). Variant id becomes "candidate".');
        $this->addOption('pass-phrase', null, InputOption::VALUE_OPTIONAL, 'Override the fixture pass-phrase (ad-hoc / candidate testing).');
        $this->addOption('input-text', null, InputOption::VALUE_OPTIONAL, 'Override the fixture inputs: inspect THIS single input string (ad-hoc). Input id becomes "adhoc".');
        $this->addOption('input-class', null, InputOption::VALUE_OPTIONAL, 'Class for --input-text: benign|attack.', AgentEvalGuardRunKnobs::DEFAULT_INPUT_CLASS);
        $this->configureBudgetOption();
    }

    /**
     * Builds the cells, runs them in this process or in child processes, prints the report (or the JSON) and saves the
     * run file.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int `FAILURE` when there is no fixture or no cell
     * @throws Throwable when the model options or the scope are refused
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        set_time_limit(0);
        ini_set('memory_limit', '1024M');
        $modelName = $this->resolveEffectiveModelName($input);
        $agentEvalGuardRunKnobs = $this->buildRunKnobs($input);
        $parallel = max(1, (int)$input->getOption('parallel'));
        $printsJson = (bool)$input->getOption('json');

        $fixturePaths = $this->findFixturePaths($agentEvalGuardRunKnobs);
        if ($fixturePaths === []) {
            $output->writeln('<error>No guard-eval fixtures found in ' . $this->describeDefaultFixtureSource() . '</error>');
            return Command::FAILURE;
        }
        $agentEvalGuardCells = AgentEvalGuardCells::buildOfFixtureFiles($fixturePaths, $agentEvalGuardRunKnobs);
        if ($agentEvalGuardCells->count() === 0) {
            $output->writeln('<error>No cells to run (check --variant/--input filters / fixture shape).</error>');
            return Command::FAILURE;
        }

        if ($parallel > 1 && !$agentEvalGuardRunKnobs->isChildRun()) {
            $agentEvalGuardCellResults = $this->runInChildProcesses($agentEvalGuardCells, $agentEvalGuardRunKnobs, $parallel, $modelName, $input, $output);
        } else {
            $agentEvalGuardCellResults = $this->runInProcess($agentEvalGuardCells, $agentEvalGuardRunKnobs, $modelName, $input, $output);
        }
        $agentEvalGuardReport = $agentEvalGuardCellResults->buildReport($modelName ?? static::DEFAULT_MODEL_NAME);

        if ($printsJson) {
            $output->writeln((string)json_encode(['cells' => $agentEvalGuardCellResults->toArray(), 'report' => $agentEvalGuardReport->toArray()], static::JSON_FLAGS));
        } else {
            $this->writeReport($agentEvalGuardReport, $output);
        }
        if (!(bool)$input->getOption('no-save')) {
            $runFile = $this->saveRunFile($agentEvalGuardCellResults, $agentEvalGuardReport, $modelName);
            if (!$printsJson) {
                $output->writeln('  saved: ' . $runFile);
            }
        }
        return Command::SUCCESS;
    }

    /**
     * The knobs of the run from the options; a guard file and a fixture file are made absolute when they exist (a
     * child process runs in another working folder).
     *
     * @param InputInterface $input
     * @return AgentEvalGuardRunKnobs
     */
    protected function buildRunKnobs(InputInterface $input): AgentEvalGuardRunKnobs
    {
        $agentEvalGuardRunKnobs = AgentEvalGuardRunKnobs::newInstance();
        $agentEvalGuardRunKnobs->runsPerCell = max(1, (int)$input->getOption('runs'));
        $agentEvalGuardRunKnobs->guardFile = $this->findAbsolutePath($this->readNonEmptyStringOption($input, 'guard-file'));
        $guardFile = $agentEvalGuardRunKnobs->guardFile;
        $agentEvalGuardRunKnobs->guardPromptText = $guardFile !== null && is_file($guardFile) ? (string)file_get_contents($guardFile) : null;
        $agentEvalGuardRunKnobs->passPhraseOverride = $this->readNonEmptyStringOption($input, 'pass-phrase');
        $agentEvalGuardRunKnobs->inputText = $this->readNonEmptyStringOption($input, 'input-text');
        $agentEvalGuardRunKnobs->inputClass = (string)($input->getOption('input-class') ?: AgentEvalGuardRunKnobs::DEFAULT_INPUT_CLASS);
        $agentEvalGuardRunKnobs->fixtureFile = $this->findAbsolutePath($this->readNonEmptyStringOption($input, 'fixture'));
        $agentEvalGuardRunKnobs->variantFilter = $this->readNonEmptyStringOption($input, 'variant');
        $agentEvalGuardRunKnobs->inputFilter = $this->readNonEmptyStringOption($input, 'input');
        $cellsOption = $this->readNonEmptyStringOption($input, 'cells');
        if ($cellsOption !== null) {
            $decodedCellKeys = json_decode((string)base64_decode($cellsOption, true), true);
            $agentEvalGuardRunKnobs->restrictToCellKeys(is_array($decodedCellKeys) ? $decodedCellKeys : []);
        }
        return $agentEvalGuardRunKnobs;
    }

    /**
     * The fixture files of the run: the one fixture file when it exists (none when it does not), else every `*.json`
     * of the configured fixture path {@see FIXTURE_PATH_NAME}.
     *
     * @param AgentEvalGuardRunKnobs $agentEvalGuardRunKnobs
     * @return string[]
     */
    protected function findFixturePaths(AgentEvalGuardRunKnobs $agentEvalGuardRunKnobs): array
    {
        if ($agentEvalGuardRunKnobs->fixtureFile !== null) {
            return is_file($agentEvalGuardRunKnobs->fixtureFile) ? [$agentEvalGuardRunKnobs->fixtureFile] : [];
        }
        $agentEvalSuitePlanService = $this->getAgentEvalSuitePlanService();
        $configuredFixturePath = $agentEvalSuitePlanService->findFixturePath(self::FIXTURE_PATH_NAME);
        return $configuredFixturePath !== null ? $agentEvalSuitePlanService->findFixtureFilesAt($configuredFixturePath) : [];
    }

    /**
     * Where the fixtures are looked for when no `--fixture` is given, as the line of a run without fixtures names it.
     * The default: every `*.json` of the configured fixture path, or the name of that path when none is configured.
     *
     * @return string
     */
    protected function describeDefaultFixtureSource(): string
    {
        $configuredFixturePath = $this->getAgentEvalSuitePlanService()->findFixturePath(self::FIXTURE_PATH_NAME);
        return $configuredFixturePath !== null ? $configuredFixturePath . '/*.json' : 'the configured fixture path `' . self::FIXTURE_PATH_NAME . '`';
    }

    /**
     * Probes every cell in this process, one after the other, as the owner of the run's agent space; prints a line
     * per probe unless JSON is printed.
     *
     * @param AgentEvalGuardCells $agentEvalGuardCells
     * @param AgentEvalGuardRunKnobs $agentEvalGuardRunKnobs
     * @param string|null $modelName
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return AgentEvalGuardCellResults
     * @throws Throwable when the scope cannot be resolved
     */
    protected function runInProcess(
        AgentEvalGuardCells $agentEvalGuardCells,
        AgentEvalGuardRunKnobs $agentEvalGuardRunKnobs,
        ?string $modelName,
        InputInterface $input,
        OutputInterface $output
    ): AgentEvalGuardCellResults {
        $printsJson = (bool)$input->getOption('json');
        $printsEveryRawAnswer = (bool)$input->getOption('full');
        $agentEvalScope = $this->resolveScopeOfRun($input);
        $billingEntity = $this->getAgentEvalRunHooksService()->findBillingEntityOfScope($agentEvalScope);
        if (!$printsJson && !$agentEvalGuardRunKnobs->isChildRun()) {
            $this->writeModelSelection($input, $output, $modelName);
            $output->writeln('  Runs/cell  : ' . $agentEvalGuardRunKnobs->runsPerCell . '   Cells: ' . $agentEvalGuardCells->count() . '   Mode: in-process');
        }
        $agentEvalGuardCellResults = AgentEvalGuardCellResults::newInstance();
        foreach ($agentEvalGuardCells->getElements() as $agentEvalGuardCell) {
            for ($run = 1; $run <= max(1, $agentEvalGuardCell->runs); $run++) {
                $rawResponse = $this->probeCell($agentEvalGuardCell, $agentEvalScope, $billingEntity, $modelName);
                $agentEvalGuardCellResult = AgentEvalGuardCellResult::forProbe($agentEvalGuardCell, $run, $rawResponse, $modelName ?? static::DEFAULT_MODEL_NAME);
                $agentEvalGuardCellResults->add($agentEvalGuardCellResult);
                if (!$printsJson) {
                    $this->writeProbeLines($agentEvalGuardCellResult, $printsEveryRawAnswer, $output);
                }
            }
        }
        return $agentEvalGuardCellResults;
    }

    /**
     * The scope of the run: the agent space the scope options name, its owner authenticated for the probes.
     *
     * @param InputInterface $input
     * @return AgentEvalScope
     * @throws Throwable when the options name no space or the scope cannot be resolved
     */
    protected function resolveScopeOfRun(InputInterface $input): AgentEvalScope
    {
        $agentEvalRunnerService = $this->getAgentEvalRunnerService();
        $agentEvalScope = $agentEvalRunnerService->resolveEvalScopeWithFallbackAccount(AgentEvalCase::fromArray([]), $this->resolveFallbackAgentSpaceId($input), $this->resolveFallbackAccountId($input));
        $agentEvalRunnerService->authenticateOwnerOfScope($agentEvalScope);
        return $agentEvalScope;
    }

    /**
     * One guard model call for `$agentEvalGuardCell`, booked under an evaluation envelope of its own; the raw answer.
     *
     * @param AgentEvalGuardCell $agentEvalGuardCell
     * @param AgentEvalScope $agentEvalScope
     * @param Entity|null $billingEntity the entity the call is billed to; null = the owner account
     * @param string|null $modelName null = the guard's default model
     * @return string
     * @throws Throwable when the call fails
     */
    protected function probeCell(AgentEvalGuardCell $agentEvalGuardCell, AgentEvalScope $agentEvalScope, ?Entity $billingEntity, ?string $modelName): string
    {
        $usageEnvelopeContext = $this->openUsageEnvelope($agentEvalScope->getOwnerAccountId(), AgentAIUsageScope::ENVELOPE_AI_AGENT_EVALUATION);
        try {
            $sentinelCanaryGuard = $this->createSentinelCanaryGuard();
            $sentinelCanaryGuard->setGuardSystemPrompt($agentEvalGuardCell->systemPrompt);
            $sentinelCanaryGuard->setInspectedInput($agentEvalGuardCell->text);
            if ($modelName !== null) {
                $sentinelCanaryGuard->setModelByName($modelName);
            }
            if ($billingEntity !== null) {
                $sentinelCanaryGuard->setBillingEntity($billingEntity);
            }
            $sentinelCanaryGuard->argusLoad();
            return (string)$sentinelCanaryGuard->getRawResponse();
        } finally {
            $this->closeUsageEnvelope($usageEnvelopeContext);
        }
    }

    /**
     * The guard of one probe: the module's class, or an application's class of the same path.
     *
     * @return ArgusAgentSentinelCanaryGuard
     */
    protected function createSentinelCanaryGuard(): ArgusAgentSentinelCanaryGuard
    {
        return ArgusAgentSentinelCanaryGuard::newInstance();
    }

    /**
     * The line of one probe and, when the answer was wrong or every raw answer is printed, the raw answer.
     *
     * @param AgentEvalGuardCellResult $agentEvalGuardCellResult
     * @param bool $printsEveryRawAnswer
     * @param OutputInterface $output
     * @return void
     */
    protected function writeProbeLines(AgentEvalGuardCellResult $agentEvalGuardCellResult, bool $printsEveryRawAnswer, OutputInterface $output): void
    {
        $mark = $agentEvalGuardCellResult->wrong ? '<error>✗</error>' : '<info>✓</info>';
        $inputTag = $agentEvalGuardCellResult->isBenign() ? AgentEvalGuardCell::INPUT_CLASS_BENIGN : AgentEvalGuardCell::INPUT_CLASS_ATTACK . '/' . $agentEvalGuardCellResult->vector;
        $output->writeln("  {$mark} [{$agentEvalGuardCellResult->variantId}] {$agentEvalGuardCellResult->inputId} ({$inputTag}) → emitted=" . ($agentEvalGuardCellResult->emitted ? 'YES' : 'no'));
        if ($agentEvalGuardCellResult->wrong || $printsEveryRawAnswer) {
            $output->writeln('      raw: ' . $this->buildOneLine($agentEvalGuardCellResult->rawResponse));
        }
    }

    /**
     * Splits the cells into `$parallel` chunks, runs each chunk in a child process of this command and merges what
     * the children print, in the order they finish. A child still running past its ceiling
     * ({@see CHILD_PROCESS_TIMEOUT_SECONDS}) is ended and named as timed out; its cells have no result.
     *
     * @param AgentEvalGuardCells $agentEvalGuardCells
     * @param AgentEvalGuardRunKnobs $agentEvalGuardRunKnobs
     * @param int $parallel
     * @param string|null $modelName
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return AgentEvalGuardCellResults
     */
    protected function runInChildProcesses(
        AgentEvalGuardCells $agentEvalGuardCells,
        AgentEvalGuardRunKnobs $agentEvalGuardRunKnobs,
        int $parallel,
        ?string $modelName,
        InputInterface $input,
        OutputInterface $output
    ): AgentEvalGuardCellResults {
        $printsJson = (bool)$input->getOption('json');
        $cellKeys = $agentEvalGuardCells->getCellKeys();
        $cellKeyChunks = array_chunk($cellKeys, (int)ceil(count($cellKeys) / $parallel));
        if (!$printsJson) {
            $output->writeln('<info>Parallel:</info> ' . count($cellKeys) . ' cells across ' . count($cellKeyChunks) . ' worker(s) (model: ' . ($modelName ?? static::DEFAULT_MODEL_NAME) . ')');
        }
        $runningProcesses = [];
        foreach ($cellKeyChunks as $chunkIndex => $chunkCellKeys) {
            $childProcess = new Process($this->buildChildProcessArguments($chunkCellKeys, $agentEvalGuardRunKnobs, $modelName, $input), DDDService::instance()->getRootDir());
            $childProcess->setTimeout(static::CHILD_PROCESS_TIMEOUT_SECONDS);
            $childProcess->start();
            $runningProcesses[$chunkIndex] = $childProcess;
        }
        $agentEvalGuardCellResults = AgentEvalGuardCellResults::newInstance();
        while ($runningProcesses !== []) {
            foreach ($runningProcesses as $chunkIndex => $childProcess) {
                try {
                    if ($childProcess->isRunning()) {
                        $childProcess->checkTimeout();
                        continue;
                    }
                } catch (ProcessTimedOutException) {
                    // the child ran into its ceiling and was ended: its cells have no result
                    if (!$printsJson) {
                        $output->writeln('  <fg=red>timed out</> worker ' . ($chunkIndex + 1) . '/' . count($cellKeyChunks) . ' (ended after ' . static::CHILD_PROCESS_TIMEOUT_SECONDS . ' s; its cells have no result)');
                    }
                    unset($runningProcesses[$chunkIndex]);
                    continue;
                }
                $childCellResults = AgentEvalGuardCellResults::fromChildOutput($childProcess->getOutput());
                $agentEvalGuardCellResults->addResultsOf($childCellResults);
                if (!$printsJson) {
                    $output->writeln('  <fg=gray>done</> worker ' . ($chunkIndex + 1) . '/' . count($cellKeyChunks) . ' (' . $childCellResults->count() . ' cells)');
                }
                unset($runningProcesses[$chunkIndex]);
            }
            usleep(static::CHILD_PROCESS_POLL_MICROSECONDS);
        }
        return $agentEvalGuardCellResults;
    }

    /**
     * The command line of the child process of one chunk: this command with JSON output, without a run file, in
     * process, the runs per cell, the scope options as given, the chunk's cell keys, the model, the budget bypass and
     * the knobs that decide which cells exist.
     *
     * @param string[] $chunkCellKeys
     * @param AgentEvalGuardRunKnobs $agentEvalGuardRunKnobs
     * @param string|null $modelName
     * @param InputInterface $input
     * @return string[]
     */
    protected function buildChildProcessArguments(array $chunkCellKeys, AgentEvalGuardRunKnobs $agentEvalGuardRunKnobs, ?string $modelName, InputInterface $input): array
    {
        $childArguments = [
            ...$this->buildChildConsoleCommandLine($this->getChildCommandName()),
            '--json',
            '--no-save',
            '--parallel=1',
            '--runs=' . $agentEvalGuardRunKnobs->runsPerCell,
            ...$this->buildScopeArgumentsForChild($input),
            '--cells=' . base64_encode((string)json_encode(array_values($chunkCellKeys))),
        ];
        if ($modelName !== null && $modelName !== '') {
            $childArguments[] = '--model=' . $modelName;
        }
        return [...$childArguments, ...$this->buildBudgetArgumentsForChild($input), ...$agentEvalGuardRunKnobs->buildChildOptionArguments()];
    }

    /**
     * Prints the report: the rates of every variant and each vector an attack got through.
     *
     * @param AgentEvalGuardReport $agentEvalGuardReport
     * @param OutputInterface $output
     * @return void
     */
    protected function writeReport(AgentEvalGuardReport $agentEvalGuardReport, OutputInterface $output): void
    {
        $output->writeln("\n<info>══ Sentinel-Canary results · model: " . $agentEvalGuardReport->modelName . " ══</info>");
        foreach ($agentEvalGuardReport->variantResults->getElements() as $agentEvalGuardVariantResult) {
            $falseBlockRate = $agentEvalGuardVariantResult->getFalseBlockRate();
            $bypassRate = $agentEvalGuardVariantResult->getBypassRate();
            $output->writeln(sprintf(
                '  <comment>%s</comment>  FPR=%s (%d/%d benign blocked)  ASR=%s (%d/%d attacks bypassed)',
                $agentEvalGuardVariantResult->variantId,
                $falseBlockRate !== null ? (100 * (float)$falseBlockRate) . '%' : 'n/a',
                $agentEvalGuardVariantResult->benignBlocked,
                $agentEvalGuardVariantResult->benignTotal,
                $bypassRate !== null ? (100 * (float)$bypassRate) . '%' : 'n/a',
                $agentEvalGuardVariantResult->attackBypassed,
                $agentEvalGuardVariantResult->attackTotal,
            ));
            foreach ($agentEvalGuardVariantResult->vectorResults->getElements() as $agentEvalGuardVectorResult) {
                if ($agentEvalGuardVectorResult->bypassed > 0) {
                    $output->writeln("      <error>bypassed</error> {$agentEvalGuardVectorResult->vector}: {$agentEvalGuardVectorResult->bypassed}/{$agentEvalGuardVectorResult->total}");
                }
            }
        }
        $output->writeln('');
    }

    /**
     * Writes the run file (`{report, cells}`) into the results folder; returns its path.
     *
     * @param AgentEvalGuardCellResults $agentEvalGuardCellResults
     * @param AgentEvalGuardReport $agentEvalGuardReport
     * @param string|null $modelName
     * @return string
     */
    protected function saveRunFile(AgentEvalGuardCellResults $agentEvalGuardCellResults, AgentEvalGuardReport $agentEvalGuardReport, ?string $modelName): string
    {
        $agentEvalResultFilesService = $this->getAgentEvalResultFilesService();
        return $agentEvalResultFilesService->writeLooseRunFile(
            $modelName,
            static::RUN_FILE_NAME,
            ['report' => $agentEvalGuardReport->toArray(), 'cells' => $agentEvalGuardCellResults->toArray()],
            static::JSON_FLAGS
        );
    }

    /**
     * The absolute path of `$path` when it exists, else `$path` as given; null for null.
     *
     * @param string|null $path
     * @return string|null
     */
    protected function findAbsolutePath(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }
        $absolutePath = realpath($path);
        return $absolutePath !== false ? $absolutePath : $path;
    }

    /**
     * `$text` on one line (every run of white space one blank), cut after {@see RAW_LINE_MAX_LENGTH} characters.
     *
     * @param string $text
     * @return string
     */
    protected function buildOneLine(string $text): string
    {
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        return mb_strlen($text) > static::RAW_LINE_MAX_LENGTH ? mb_substr($text, 0, static::RAW_LINE_MAX_LENGTH) . '…' : $text;
    }

    /** The result files service (the results folder). */
    protected function getAgentEvalResultFilesService(): AgentEvalResultFilesService
    {
        /** @var AgentEvalResultFilesService $agentEvalResultFilesService */
        $agentEvalResultFilesService = DDDService::instance()->getService(AgentEvalResultFilesService::class);
        return $agentEvalResultFilesService;
    }

    /** The suite plan service (configured fixture paths). */
    protected function getAgentEvalSuitePlanService(): AgentEvalSuitePlanService
    {
        /** @var AgentEvalSuitePlanService $agentEvalSuitePlanService */
        $agentEvalSuitePlanService = DDDService::instance()->getService(AgentEvalSuitePlanService::class);
        return $agentEvalSuitePlanService;
    }

    /** The runner service (the scope of the run and its owner). */
    protected function getAgentEvalRunnerService(): AgentEvalRunnerService
    {
        /** @var AgentEvalRunnerService $agentEvalRunnerService */
        $agentEvalRunnerService = DDDService::instance()->getService(AgentEvalRunnerService::class);
        return $agentEvalRunnerService;
    }

    /** The run hooks service (the billing entity of the scope). */
    protected function getAgentEvalRunHooksService(): AgentEvalRunHooksService
    {
        /** @var AgentEvalRunHooksService $agentEvalRunHooksService */
        $agentEvalRunHooksService = DDDService::instance()->getService(AgentEvalRunHooksService::class);
        return $agentEvalRunHooksService;
    }
}
