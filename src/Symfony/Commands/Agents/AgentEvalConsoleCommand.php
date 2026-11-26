<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Exceptions\Evals\AgentEvalUnknownModelNameException;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusLanguageModel;
use DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Domain\AI\Services\Eval\AgentEvalJudgedRunService;
use DDD\Domain\AI\Services\Eval\AgentEvalResultFilesService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * The base of the evaluation commands, on top of {@see AgentConsoleCommand}. What every evaluation command shares:
 *
 * - the budget: `--ignore-budget` ({@see configureBudgetOption()}) grants the bypass for the process through
 *   {@see AgentConsoleCommand::grantBudgetBypassWhenRequested()}; whether the run asks for it is the override point
 *   {@see isBudgetBypassRequested()} (a command whose option works the other way round overrides it and
 *   {@see configureBudgetOption()}); a child process gets the bypass as the option ({@see buildBudgetArgumentsForChild()});
 * - the scope: `--agentSpaceId` and the options the agent-spaces service declares in the application's words
 *   ({@see configureEvalScopeOptions()}); a command that runs cases reads the space for the cases that name none
 *   ({@see resolveFallbackAgentSpaceId()}), a command that only starts children hands the options on as they were
 *   given ({@see buildScopeArgumentsForChild()});
 * - progress lines on the error output, so a `--json` document on the standard output stays pure
 *   ({@see buildProgressReporter()}, every line indented by two spaces);
 * - stop signals: a command that stops gracefully says so ({@see handlesStopSignals()}) and gets SIGINT and SIGTERM
 *   through {@see handleSignal()}: the first asks for a stop, the second for the end of everything still running
 *   ({@see isStopRequested()}, {@see isKillRequested()}, the override point {@see onStopSignal()}); a `STOP` file in the
 *   run folder or in the results folder and a passed `--max-duration` count as the first signal
 *   ({@see requestStopForStopFilesOrDeadline()}, {@see configureMaxDurationOption()});
 * - the command line of a child process ({@see buildChildConsoleCommandLine()}, the child's name from
 *   {@see getChildCommandName()});
 * - the judge and driver models: a `--judge-model` / `--driver-model` the model catalog does not know is refused
 *   before anything runs, with the exit code `Command::INVALID` ({@see findUnknownHarnessModelNameRefusal()}).
 */
abstract class AgentEvalConsoleCommand extends AgentConsoleCommand implements SignalableCommandInterface
{
    /** @var string The option that names the account of a run without a space, or in a space without an owner */
    public const string OPTION_EVAL_ACCOUNT_ID = 'accountId';

    /**
     * @var int|null The account the run names (`--accountId`, {@see resolveFallbackAccountId()}), kept for the run by a
     * command that reads it once ({@see rememberFallbackAccountIdOfRun()}); null = none
     */
    protected ?int $fallbackAccountIdOfRun = null;

    /** @var string The indentation of a progress line on the error output */
    public const string PROGRESS_LINE_PREFIX = '  ';

    /** @var string The memory limit of a child process of an evaluation command */
    public const string CHILD_PROCESS_MEMORY_LIMIT = '2G';

    /** @var string The PHP binary a child process is started with */
    public const string CHILD_PROCESS_PHP_BINARY = 'php';

    /** @var string[] The options that name a model of the evaluation harness (the judge, the simulated owner) */
    public const array HARNESS_MODEL_OPTION_NAMES = ['judge-model', 'driver-model'];

    /** @var int The stop signals this run received (0 = none) */
    protected int $stopSignalCount = 0;

    /**
     * Refuses a judge or driver model the catalog does not know before anything of the run happens: the refusal is
     * printed and the command exits with `Command::INVALID`; no case, child process or plan runs. Otherwise the run of
     * {@see AgentConsoleCommand::execute()}.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $unknownHarnessModelNameRefusal = $this->findUnknownHarnessModelNameRefusal($input);
        if ($unknownHarnessModelNameRefusal !== null) {
            $output->writeln('<error>' . OutputFormatter::escape($unknownHarnessModelNameRefusal) . '</error>');
            return Command::INVALID;
        }
        return parent::execute($input, $output);
    }

    /**
     * The refusal of the first harness model option ({@see HARNESS_MODEL_OPTION_NAMES}) the command declares and the
     * caller passed whose model the catalog does not know (`--judge-model: The model catalog knows no model "…" …`);
     * null when every passed name is known. An option the command does not declare or that was passed empty is skipped.
     *
     * @param InputInterface $input
     * @return string|null
     */
    protected function findUnknownHarnessModelNameRefusal(InputInterface $input): ?string
    {
        foreach (static::HARNESS_MODEL_OPTION_NAMES as $harnessModelOptionName) {
            if (!$input->hasOption($harnessModelOptionName)) {
                continue;
            }
            $harnessModelName = trim((string)$input->getOption($harnessModelOptionName));
            if ($harnessModelName === '') {
                continue;
            }
            try {
                $this->getJudgedRunServiceForModelNameCheck()->refuseUnknownModelName($harnessModelName);
            } catch (AgentEvalUnknownModelNameException $unknownModelName) {
                return '--' . $harnessModelOptionName . ': ' . $unknownModelName->getMessage();
            }
        }
        return null;
    }

    /**
     * The judged-run service that checks the harness model names.
     *
     * @return AgentEvalJudgedRunService
     */
    /**
     * The agent model a run without `--model` / `--models` uses, resolved like the egress does: the model of its
     * `modelScope`, else its `defaultAIModelName` (`ArgusAIAgentConversation`, the application's class when it has
     * one); null when none is declared. A space or
     * prompt extension may still choose another model per conversation.
     */
    protected function findDefaultAgentModelName(): ?string
    {
        // the application's egress class may declare another model: its attribute, read without building the repository
        $egressClassName = DDDService::instance()->getContainerServiceClassNameForClass(ArgusAIAgentConversation::class) ?? ArgusAIAgentConversation::class;
        $languageModelAttributes = (new \ReflectionClass($egressClassName))->getAttributes(ArgusLanguageModel::class);
        if ($languageModelAttributes === []) {
            return null;
        }
        /** @var ArgusLanguageModel $argusLanguageModel */
        $argusLanguageModel = $languageModelAttributes[0]->newInstance();
        if (isset($argusLanguageModel->modelScope) && $argusLanguageModel->modelScope !== '') {
            /** @var AIModelsService $aIModelsService */
            $aIModelsService = DDDService::instance()->getService(AIModelsService::class);
            $scopeModelName = $aIModelsService->getModelForScope($argusLanguageModel->modelScope)?->name ?? null;
            if (is_string($scopeModelName) && $scopeModelName !== '') {
                return $scopeModelName;
            }
        }
        $defaultAIModelName = isset($argusLanguageModel->defaultAIModelName) ? (string)$argusLanguageModel->defaultAIModelName : '';
        return $defaultAIModelName !== '' ? $defaultAIModelName : null;
    }

    protected function getJudgedRunServiceForModelNameCheck(): AgentEvalJudgedRunService
    {
        /** @var AgentEvalJudgedRunService $agentEvalJudgedRunService */
        $agentEvalJudgedRunService = DDDService::instance()->getService(AgentEvalJudgedRunService::class);
        return $agentEvalJudgedRunService;
    }

    // ------------------------------------------------------------------ the budget

    /**
     * Declares the budget option of the command: `--ignore-budget`. Call it from `configure()`.
     *
     * @return void
     */
    protected function configureBudgetOption(): void
    {
        $this->configureIgnoreBudgetOption();
    }

    /**
     * The arguments that hand the budget bypass of this run to a child process: `--ignore-budget` when the run asks
     * for the bypass ({@see isBudgetBypassRequested()}), none otherwise. A child is a process of its own: the grant of
     * this process never reaches it.
     *
     * @param InputInterface $input
     * @return string[]
     */
    protected function buildBudgetArgumentsForChild(InputInterface $input): array
    {
        $childArguments = [];
        if ($this->isBudgetBypassRequested($input)) {
            $childArguments[] = '--ignore-budget';
        }
        return $childArguments;
    }

    // ------------------------------------------------------------------ the scope

    /**
     * Declares the scope options: `--agentSpaceId`, the options of the agent-spaces service (e.g. an application's
     * own id of the entity a space stands for) and `--accountId` (a run at account level, or in a space without an
     * owner). Call it from `configure()`.
     *
     * @return void
     */
    protected function configureEvalScopeOptions(): void
    {
        $this->configureAgentSpaceOptions();
        $this->addOption(
            static::OPTION_EVAL_ACCOUNT_ID,
            null,
            InputOption::VALUE_REQUIRED,
            'The account of a run without a space (cases that name none run at account level for it) and of a run in a space without an owner'
        );
    }

    /**
     * The account the run names (`--accountId`): cases that name no space (and no `--agentSpaceId`) run at account level
     * for it, and a space without an owner runs for it; null when the option is not given.
     *
     * @param InputInterface $input
     * @return int|null
     * @throws InvalidOptionException when the value is no positive whole number
     */
    protected function rememberFallbackAccountIdOfRun(InputInterface $input): ?int
    {
        $this->fallbackAccountIdOfRun = $this->resolveFallbackAccountId($input);
        return $this->fallbackAccountIdOfRun;
    }

    /**
     * The account the run names (`--accountId`); see {@see configureEvalScopeOptions()}.
     *
     * @throws InvalidOptionException when the value is no positive whole number
     */
    protected function resolveFallbackAccountId(InputInterface $input): ?int
    {
        $accountIdValue = $input->hasOption(static::OPTION_EVAL_ACCOUNT_ID) ? trim((string)$input->getOption(static::OPTION_EVAL_ACCOUNT_ID)) : '';
        if ($accountIdValue === '') {
            return null;
        }
        if (!ctype_digit($accountIdValue) || (int)$accountIdValue <= 0) {
            throw new InvalidOptionException('--' . static::OPTION_EVAL_ACCOUNT_ID . " must be a positive whole number, got '{$accountIdValue}'");
        }
        return (int)$accountIdValue;
    }

    /**
     * The agent space of the cases that name none: the space the scope options name
     * ({@see AgentConsoleCommand::resolveAgentSpaceFromInput()}), read with the rights restrictions lifted (the
     * harness is an operator); null when no scope option was given.
     *
     * @param InputInterface $input
     * @return int|null
     * @throws InvalidOptionException when the options name no space, name it twice or name no positive id
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function resolveFallbackAgentSpaceId(InputInterface $input): ?int
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $agentSpace = $this->resolveAgentSpaceFromInput($input);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        return $agentSpace instanceof AgentSpace && isset($agentSpace->id) ? (int)$agentSpace->id : null;
    }

    /**
     * The scope options as a child process gets them: `--agentSpaceId=<id>`, every option of the agent-spaces
     * service and `--accountId=<id>`, each as it was given (a value the command declared as the option's default included), in that order.
     * Read nothing: a parent that only starts children never resolves the space itself.
     *
     * @param InputInterface $input
     * @return string[]
     */
    protected function buildScopeArgumentsForChild(InputInterface $input): array
    {
        $childArguments = [];
        $agentSpaceIdValue = $input->hasOption(self::OPTION_AGENT_SPACE_ID) ? trim((string)$input->getOption(self::OPTION_AGENT_SPACE_ID)) : '';
        if ($agentSpaceIdValue !== '') {
            $childArguments[] = '--' . self::OPTION_AGENT_SPACE_ID . '=' . $agentSpaceIdValue;
        }
        foreach ($this->findGivenAgentSpaceScopeOptionValues($input) as $scopeOptionName => $scopeOptionValue) {
            $childArguments[] = '--' . $scopeOptionName . '=' . $scopeOptionValue;
        }
        $accountIdValue = $input->hasOption(static::OPTION_EVAL_ACCOUNT_ID) ? trim((string)$input->getOption(static::OPTION_EVAL_ACCOUNT_ID)) : '';
        if ($accountIdValue !== '') {
            $childArguments[] = '--' . static::OPTION_EVAL_ACCOUNT_ID . '=' . $accountIdValue;
        }
        return $childArguments;
    }

    // ------------------------------------------------------------------ progress

    /**
     * The progress reporter of an evaluation run: every line on the error output, indented by
     * {@see PROGRESS_LINE_PREFIX}, so the standard output carries only what the command prints itself (a `--json`
     * document stays pure). A child's progress lines are what its parent reads from its error output.
     *
     * @param OutputInterface $output
     * @return AgentConsoleProgressReporter
     */
    protected function buildProgressReporter(OutputInterface $output): AgentConsoleProgressReporter
    {
        return AgentConsoleProgressReporter::forOutput($this->getErrorOutput($output), static::PROGRESS_LINE_PREFIX);
    }

    /**
     * The error output of `$output`: the console's error stream, else (an output without one) a stream output on
     * `php://stderr`.
     *
     * @param OutputInterface $output
     * @return OutputInterface
     */
    protected function getErrorOutput(OutputInterface $output): OutputInterface
    {
        if ($output instanceof ConsoleOutputInterface) {
            return $output->getErrorOutput();
        }
        return new StreamOutput(fopen('php://stderr', 'w'));
    }

    // ------------------------------------------------------------------ stop signals

    /**
     * Whether the command stops gracefully on SIGINT / SIGTERM ({@see handleSignal()}). The default: no (the process
     * ends as Symfony ends it).
     *
     * @return bool
     */
    protected function handlesStopSignals(): bool
    {
        return false;
    }

    /**
     * SIGINT and SIGTERM when the command stops gracefully and the process can receive signals; none otherwise.
     *
     * @return int[]
     */
    public function getSubscribedSignals(): array
    {
        if (!$this->handlesStopSignals() || !defined('SIGINT') || !defined('SIGTERM')) {
            return [];
        }
        return [constant('SIGINT'), constant('SIGTERM')];
    }

    /**
     * Counts the stop signal and hands it to {@see onStopSignal()}; the command goes on (it decides itself when to
     * end).
     *
     * @param int $signal
     * @param int|false $previousExitCode
     * @return int|false false: the run continues
     */
    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->stopSignalCount++;
        $this->onStopSignal($signal);
        return false;
    }

    /**
     * Override point: what the command does when a stop signal arrives (it is already counted). The default: nothing
     * more — the command reads {@see isStopRequested()} and {@see isKillRequested()} where it decides.
     *
     * @param int $signal
     * @return void
     */
    protected function onStopSignal(int $signal): void
    {
    }

    /**
     * Asks for a stop as a first stop signal does (e.g. an operator's stop file), without a signal: afterwards
     * {@see isStopRequested()} is true; a stop already asked for stays as it is (a second signal still ends
     * everything).
     *
     * @return void
     */
    public function requestStop(): void
    {
        if ($this->stopSignalCount === 0) {
            $this->stopSignalCount = 1;
        }
    }

    /** @var string The option that ends a run gracefully after so many seconds */
    public const string OPTION_MAX_DURATION = 'max-duration';

    /** @var float|null The moment (microtime) the run stops as on a stop signal; null = no limit */
    protected ?float $maxDurationDeadline = null;

    /** @var bool Whether the stop of the run came from its `--max-duration` */
    protected bool $stoppedAtMaxDuration = false;

    /** `--max-duration=<seconds>`: past it the run stops as on a first stop signal. Default: no limit. */
    protected function configureMaxDurationOption(): void
    {
        $this->addOption(
            static::OPTION_MAX_DURATION,
            null,
            InputOption::VALUE_REQUIRED,
            'Stop gracefully after this many seconds, as on a first stop signal: the queue is dropped, cases that change no live state are ended, writes finish and restore. Default: no limit.'
        );
    }

    /** Starts the clock of `--max-duration` (call it when the cases start); a missing or non-positive value sets no limit. */
    protected function startMaxDurationClock(InputInterface $input): void
    {
        $maxDurationOption = $input->hasOption(static::OPTION_MAX_DURATION) ? $input->getOption(static::OPTION_MAX_DURATION) : null;
        $maxDurationSeconds = is_numeric($maxDurationOption) ? (float)$maxDurationOption : 0.0;
        $this->maxDurationDeadline = $maxDurationSeconds > 0 ? microtime(true) + $maxDurationSeconds : null;
        $this->stoppedAtMaxDuration = false;
    }

    /**
     * Asks for a stop (as a first stop signal) when the operator created a `STOP` file in `$runDirectory` or in the
     * results folder, or the run passed its `--max-duration`. Call it in every pass of the run loop.
     */
    protected function requestStopForStopFilesOrDeadline(?string $runDirectory): void
    {
        if ($this->isStopRequested()) {
            return;
        }
        $agentEvalResultFilesService = $this->getResultFilesService();
        if (($runDirectory !== null && $agentEvalResultFilesService->isStopRequestedForSuiteRun($runDirectory))
            || $agentEvalResultFilesService->isStopRequestedForEveryRun()
        ) {
            $this->requestStop();
            return;
        }
        if ($this->maxDurationDeadline !== null && microtime(true) >= $this->maxDurationDeadline) {
            $this->stoppedAtMaxDuration = true;
            $this->requestStop();
        }
    }

    /** Whether the stop of the run came from its `--max-duration`. */
    public function isStoppedAtMaxDuration(): bool
    {
        return $this->stoppedAtMaxDuration;
    }

    /**
     * The line of a stop that drops `$droppedCaseCount` queued cases (override point).
     *
     * @param int $droppedCaseCount
     * @return string
     */
    protected function describeStopRequest(int $droppedCaseCount): string
    {
        if ($this->stoppedAtMaxDuration) {
            return sprintf('Stopped at max duration — %d case(s) not run; every case that changes no live state is ended, running writes finish and restore.', $droppedCaseCount);
        }
        return sprintf('Stop requested — %d queued case(s) dropped; every case that changes no live state is ended, running writes finish and restore (a second signal ends them too).', $droppedCaseCount);
    }

    /** The warning a run prints at its start while the host-wide `STOP` file exists (it would stop at once); null otherwise. */
    protected function buildHostWideStopFileWarning(): ?string
    {
        $agentEvalResultFilesService = $this->getResultFilesService();
        return $agentEvalResultFilesService->isStopRequestedForEveryRun()
            ? 'A STOP file in the results folder stops every eval run of this host (' . $agentEvalResultFilesService->getHostWideStopFile() . '); delete it to run.'
            : null;
    }

    /** The result files service (the application's class, when it replaces it). */
    protected function getResultFilesService(): AgentEvalResultFilesService
    {
        /** @var AgentEvalResultFilesService $agentEvalResultFilesService */
        $agentEvalResultFilesService = DDDService::instance()->getService(AgentEvalResultFilesService::class);
        return $agentEvalResultFilesService;
    }

    /** Whether a stop was asked for: a stop signal arrived. */
    public function isStopRequested(): bool
    {
        return $this->stopSignalCount >= 1;
    }

    /** Whether the end of everything still running was asked for: a second stop signal arrived. */
    public function isKillRequested(): bool
    {
        return $this->stopSignalCount >= 2;
    }

    // ------------------------------------------------------------------ child processes

    /**
     * The name of the command a child process of this command runs. The default: this command's own name (a parent
     * that runs its cases in children of itself). An application's subclass keeps its name, so its children run its
     * class.
     *
     * @return string
     */
    protected function getChildCommandName(): string
    {
        return (string)$this->getName();
    }

    /**
     * The start of a child process's command line: the PHP binary with the evaluation memory limit, the console of
     * the kernel project directory and the command `$commandName`; the caller appends the arguments.
     *
     * @param string $commandName
     * @return string[]
     */
    protected function buildChildConsoleCommandLine(string $commandName): array
    {
        return [
            static::CHILD_PROCESS_PHP_BINARY,
            '-d',
            'memory_limit=' . static::CHILD_PROCESS_MEMORY_LIMIT,
            DDDService::instance()->getRootDir() . '/bin/console',
            $commandName,
        ];
    }
}
