<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\UsageLogs\AIBudgetBypassGrant;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Infrastructure\Services\DDDService;
use DDD\Symfony\Commands\Agents\AgentConsoleCommand;
use DDD\Symfony\Commands\Agents\AgentEvalConsoleCommand;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The base of the evaluation commands: the budget bypass through `--ignore-budget` and its hand-over to a child, the
 * scope options of the agent-spaces service (the fallback space read with the rights lifted, the options handed to a
 * child as given), the progress lines on the error output beside a pure standard output, the stop signals and the
 * command line of a child process.
 */
class AgentEvalConsoleCommandTest extends IntegrationTestCase
{
    protected AgentSpacesServiceConsoleScopeDouble $agentSpacesServiceDouble;

    protected RecordingUsageEnvelopesServiceDouble $usageEnvelopesServiceDouble;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agentSpacesServiceDouble = new AgentSpacesServiceConsoleScopeDouble();
        $this->usageEnvelopesServiceDouble = new RecordingUsageEnvelopesServiceDouble();
    }

    public function testTheBudgetOptionGrantsTheBypassAndHandsItToAChild(): void
    {
        $probeCommand = $this->buildProbeCommand();
        $this->runProbeCommand($probeCommand, []);
        self::assertNull($this->usageEnvelopesServiceDouble->getBudgetBypassGrantForTheProcess());
        self::assertNotContains('--ignore-budget', $probeCommand->childArguments);

        $probeCommand = $this->buildProbeCommand();
        $this->runProbeCommand($probeCommand, ['--' . AgentConsoleCommand::OPTION_IGNORE_BUDGET => true]);
        self::assertSame(AIBudgetBypassGrant::REASON_CONSOLE_OPTION, $this->usageEnvelopesServiceDouble->getBudgetBypassGrantForTheProcess()?->reason);
        self::assertSame('--ignore-budget', $probeCommand->childArguments[array_key_last($probeCommand->childArguments)]);
    }

    public function testTheFallbackSpaceIsReadWithTheRightsLiftedAndTheOptionsReachAChildAsGiven(): void
    {
        $probeCommand = $this->buildProbeCommand();
        $this->runProbeCommand($probeCommand, ['--' . AgentSpacesServiceConsoleScopeDouble::SCOPE_OPTION_NAME => AgentSpacesServiceConsoleScopeDouble::KNOWN_SCOPE_OPTION_VALUE]);

        self::assertSame(AgentSpacesServiceConsoleScopeDouble::AGENT_SPACE_ID_OF_KNOWN_SCOPE_OPTION_VALUE, $probeCommand->fallbackAgentSpaceId);
        self::assertFalse($probeCommand->rightsAppliedDuringScopeRead, 'the harness reads the space as an operator');
        self::assertTrue(DBEntity::getApplyRightsRestrictions(), 'the rights are back after the read');
        self::assertSame(['--' . AgentSpacesServiceConsoleScopeDouble::SCOPE_OPTION_NAME . '=' . AgentSpacesServiceConsoleScopeDouble::KNOWN_SCOPE_OPTION_VALUE], array_slice($probeCommand->childArguments, 5));

        $probeCommand = $this->buildProbeCommand();
        $this->runProbeCommand($probeCommand, ['--' . AgentConsoleCommand::OPTION_AGENT_SPACE_ID => (string)AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID]);
        self::assertSame(AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID, $probeCommand->fallbackAgentSpaceId);
        self::assertSame(['--agentSpaceId=' . AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID], array_slice($probeCommand->childArguments, 5));

        $probeCommand = $this->buildProbeCommand();
        $this->runProbeCommand($probeCommand, []);
        self::assertNull($probeCommand->fallbackAgentSpaceId, 'no scope option: the cases name their own');
        self::assertSame([], array_slice($probeCommand->childArguments, 5));
    }

    public function testTheAccountOptionNamesTheAccountOfARunWithoutASpaceAndReachesAChild(): void
    {
        $probeCommand = $this->buildProbeCommand();
        $this->runProbeCommand($probeCommand, ['--' . AgentEvalConsoleCommand::OPTION_EVAL_ACCOUNT_ID => '40']);

        self::assertSame(40, $probeCommand->fallbackAccountId);
        self::assertNull($probeCommand->fallbackAgentSpaceId);
        self::assertSame(['--accountId=40'], array_slice($probeCommand->childArguments, 5));

        $probeCommand = $this->buildProbeCommand();
        $this->runProbeCommand($probeCommand, []);
        self::assertNull($probeCommand->fallbackAccountId);
    }

    public function testProgressGoesToTheErrorOutputAndTheStandardOutputStaysPure(): void
    {
        $consoleOutput = new ConsoleOutput(OutputInterface::VERBOSITY_NORMAL, false);
        $standardOutput = new BufferedOutput();
        $errorOutput = new BufferedOutput();
        $consoleOutput->setErrorOutput($errorOutput);
        $probeCommand = new AgentEvalConsoleProbeCommandWithBufferedStandardOutput($this->agentSpacesServiceDouble, $this->usageEnvelopesServiceDouble, $standardOutput);

        $probeCommand->run(new ArrayInput([]), $consoleOutput);

        self::assertSame(AgentEvalConsoleProbeCommand::PRINTED_LINE . "\n", $standardOutput->fetch());
        self::assertSame('  ' . AgentEvalConsoleProbeCommand::PROGRESS_LINE . "\n", $errorOutput->fetch(), 'two spaces in front, as the child processes have always written them');
    }

    public function testStopSignalsAreSubscribedOnlyByACommandThatStopsGracefully(): void
    {
        $probeCommand = $this->buildProbeCommand();
        self::assertInstanceOf(SignalableCommandInterface::class, $probeCommand);
        self::assertSame([], $probeCommand->getSubscribedSignals());

        $probeCommand->stopsGracefully = true;
        if (defined('SIGINT') && defined('SIGTERM')) {
            self::assertSame([constant('SIGINT'), constant('SIGTERM')], $probeCommand->getSubscribedSignals());
        }
        self::assertFalse($probeCommand->isStopRequested());

        self::assertFalse($probeCommand->handleSignal(2), 'the run goes on');
        self::assertTrue($probeCommand->isStopRequested());
        self::assertFalse($probeCommand->isKillRequested());
        $probeCommand->handleSignal(15);
        self::assertTrue($probeCommand->isKillRequested(), 'a second signal ends what still runs');
        self::assertSame([2, 15], $probeCommand->receivedStopSignals);
    }

    public function testAStopAskedForWithoutASignalCountsAsTheFirstSignal(): void
    {
        $probeCommand = $this->buildProbeCommand();

        $probeCommand->requestStop();
        $probeCommand->requestStop();

        self::assertTrue($probeCommand->isStopRequested());
        self::assertFalse($probeCommand->isKillRequested(), 'asking twice is no second signal');
        $probeCommand->handleSignal(2);
        self::assertTrue($probeCommand->isKillRequested(), 'a signal after the asked stop ends what still runs');
    }

    public function testAChildRunsTheCommandsOwnNameOnTheConsoleOfTheProject(): void
    {
        $probeCommand = $this->buildProbeCommand();
        $this->runProbeCommand($probeCommand, []);

        self::assertSame(
            ['php', '-d', 'memory_limit=2G', DDDService::instance()->getRootDir() . '/bin/console', AgentEvalConsoleProbeCommand::PROBE_COMMAND_NAME],
            array_slice($probeCommand->childArguments, 0, 5)
        );
        self::assertInstanceOf(AgentEvalConsoleCommand::class, $probeCommand);
    }

    /**
     * Runs the probe with its error output captured apart (the progress lines go there).
     *
     * @param array<string, mixed> $commandInput
     */
    protected function runProbeCommand(AgentEvalConsoleProbeCommand $probeCommand, array $commandInput): CommandTester
    {
        $commandTester = new CommandTester($probeCommand);
        $commandTester->execute($commandInput, ['capture_stderr_separately' => true]);
        self::assertSame('  ' . AgentEvalConsoleProbeCommand::PROGRESS_LINE . "\n", $commandTester->getErrorOutput());
        self::assertSame(AgentEvalConsoleProbeCommand::PRINTED_LINE . "\n", $commandTester->getDisplay());
        return $commandTester;
    }

    protected function buildProbeCommand(): AgentEvalConsoleProbeCommand
    {
        return new AgentEvalConsoleProbeCommand($this->agentSpacesServiceDouble, $this->usageEnvelopesServiceDouble);
    }
}
