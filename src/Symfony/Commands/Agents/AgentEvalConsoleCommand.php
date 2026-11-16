<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
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
 *   ({@see isStopRequested()}, {@see isKillRequested()}, the override point {@see onStopSignal()});
 * - the command line of a child process ({@see buildChildConsoleCommandLine()}, the child's name from
 *   {@see getChildCommandName()}).
 */
abstract class AgentEvalConsoleCommand extends AgentConsoleCommand implements SignalableCommandInterface
{
    /** @var string The indentation of a progress line on the error output */
    public const string PROGRESS_LINE_PREFIX = '  ';

    /** @var string The memory limit of a child process of an evaluation command */
    public const string CHILD_PROCESS_MEMORY_LIMIT = '2G';

    /** @var string The PHP binary a child process is started with */
    public const string CHILD_PROCESS_PHP_BINARY = 'php';

    /** @var int The stop signals this run received (0 = none) */
    protected int $stopSignalCount = 0;

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
     * Declares the scope options: `--agentSpaceId` and the options of the agent-spaces service (e.g. an application's
     * own id of the entity a space stands for). Call it from `configure()`.
     *
     * @return void
     */
    protected function configureEvalScopeOptions(): void
    {
        $this->configureAgentSpaceOptions();
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
     * The scope options as a child process gets them: `--agentSpaceId=<id>` and every option of the agent-spaces
     * service, each as it was given (a value the command declared as the option's default included), in that order.
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
