<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleStatus;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleTransition;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Infrastructure\Services\DDDService;
use DDD\Symfony\CompilerPasses\FrameworkCommandsYieldPass;
use DDD\Symfony\Commands\Agents\AgentCloseSchedulesForInactiveAgentSpacesCommand;
use DDD\Symfony\Commands\Agents\AgentConsoleCommand;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\DependencyInjection\AddConsoleCommandPass;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\SemaphoreStore;

/**
 * The state sweep counts and prints every decision, applies nothing in a dry run, prints a schedule's scope through
 * the override point, and is replaced by an application command that declares the module's name beside its own:
 * the framework removes the module's service, and both names run the application's class. The sweep runs with the
 * entity rights lifted; a run that finds the previous one still holding the lock ends at once.
 */
class AgentCloseSchedulesForInactiveAgentSpacesCommandTest extends UnitTestCase
{
    public const string MODULE_COMMAND_NAME = 'app:agent:close-schedules-for-inactive-agent-spaces';

    protected StateSweepSchedulesServiceDouble $schedulesServiceDouble;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schedulesServiceDouble = new StateSweepSchedulesServiceDouble();
        // the rights apply before the run, so a sweep that runs with them lifted is told apart
        DDDService::instance()->createEntityRightsRestrictionsStateSnapshot();
        DBEntity::setApplyRightsRestrictions(true);
    }

    protected function tearDown(): void
    {
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        parent::tearDown();
    }

    public function testTheCommandHasTheNeutralNameItsOptionsAndDefaults(): void
    {
        $asCommandAttributes = (new ReflectionClass(AgentCloseSchedulesForInactiveAgentSpacesCommand::class))->getAttributes(AsCommand::class);
        self::assertSame(self::MODULE_COMMAND_NAME, explode('|', $asCommandAttributes[0]->newInstance()->name)[0], 'the old name stays an alias');

        $closeSchedulesCommand = new AgentCloseSchedulesForInactiveAgentSpacesCommand();
        self::assertInstanceOf(AgentConsoleCommand::class, $closeSchedulesCommand);
        self::assertSame(['batch', 'dry-run'], array_keys($closeSchedulesCommand->getDefinition()->getOptions()));
        self::assertSame('200', $closeSchedulesCommand->getDefinition()->getOption('batch')->getDefault());
    }

    public function testEveryDecisionIsPrintedAndCounted(): void
    {
        $this->scriptCandidates();

        $commandTester = new CommandTester(new AgentCloseSchedulesForInactiveAgentSpacesProbeCommand($this->schedulesServiceDouble));
        $exitCode = $commandTester->execute(['--batch' => '20']);
        $display = $commandTester->getDisplay();

        self::assertSame(0, $exitCode);
        self::assertSame([20], $this->schedulesServiceDouble->askedLimits);
        self::assertSame(['1:real', '2:real', '3:real', '4:real', '5:real'], $this->schedulesServiceDouble->sweptSchedules);
        self::assertStringContainsString('Transitioned: schedule 1 (account 7, agent space 70) → CANCELLED (SCOPE_ENDED)', $display);
        self::assertStringContainsString('Transitioned: schedule 2 (account 7, agent space -) → PAUSED (SCOPE_SUSPENDED)', $display);
        self::assertStringContainsString('Transitioned: schedule 3 (account 7, agent space 70) → ACTIVE (re-armed, reason cleared)', $display);
        self::assertStringContainsString('Failed: schedule 5: ' . StateSweepSchedulesServiceDouble::FAILURE_MESSAGE, $display);
        self::assertStringEndsWith("Schedule state sweep: 1 closed (CANCELLED), 1 paused, 1 re-armed, 1 untouched, 1 failed.\n", $display);
    }

    public function testEverySweepRunsWithTheEntityRightsLiftedAndTheRightsApplyAgainAfterTheRun(): void
    {
        $this->scriptCandidates();

        (new CommandTester(new AgentCloseSchedulesForInactiveAgentSpacesProbeCommand($this->schedulesServiceDouble)))->execute([]);

        self::assertSame([false, false, false, false, false], $this->schedulesServiceDouble->rightsAppliedDuringSweeps);
        self::assertTrue(DBEntity::getApplyRightsRestrictions(), 'the rights apply again after the run');
    }

    public function testARunThatFindsThePreviousRunStillActiveSkipsTheTick(): void
    {
        $this->scriptCandidates();
        $previousRunLock = (new LockFactory(SemaphoreStore::isSupported() ? new SemaphoreStore() : new FlockStore()))
            ->createLock(AgentCloseSchedulesForInactiveAgentSpacesProbeCommand::PROBE_COMMAND_NAME);
        self::assertTrue($previousRunLock->acquire());

        try {
            $commandTester = new CommandTester(new AgentCloseSchedulesForInactiveAgentSpacesProbeCommand($this->schedulesServiceDouble));
            $exitCode = $commandTester->execute([]);
        } finally {
            $previousRunLock->release();
        }

        self::assertSame(0, $exitCode);
        self::assertSame("Schedule state sweep: a previous run is still active — skipping this tick.\n", $commandTester->getDisplay());
        self::assertSame([], $this->schedulesServiceDouble->askedLimits, 'no candidate was read');
        self::assertSame([], $this->schedulesServiceDouble->sweptSchedules);
    }

    public function testTheDryRunSaysWhatItWouldDo(): void
    {
        $this->scriptCandidates();

        $commandTester = new CommandTester(new AgentCloseSchedulesForInactiveAgentSpacesProbeCommand($this->schedulesServiceDouble));
        $commandTester->execute(['--dry-run' => true]);

        self::assertSame(['1:dry', '2:dry', '3:dry', '4:dry', '5:dry'], $this->schedulesServiceDouble->sweptSchedules);
        self::assertSame([200], $this->schedulesServiceDouble->askedLimits);
        self::assertStringContainsString('Would transition (dry-run): schedule 1 (account 7, agent space 70) → CANCELLED (SCOPE_ENDED)', $commandTester->getDisplay());
    }

    public function testTheApplicationPrintsTheScopeInItsOwnWords(): void
    {
        $this->scriptCandidates();
        $applicationCommand = new ApplicationCloseSchedulesUnderBothNamesCommandDouble();
        $applicationCommand->schedulesServiceDouble = $this->schedulesServiceDouble;

        $commandTester = new CommandTester($applicationCommand);
        $commandTester->execute([]);

        self::assertStringContainsString('Transitioned: schedule 1 (account 7, shop 70) → CANCELLED (SCOPE_ENDED)', $commandTester->getDisplay());
    }

    public function testAnApplicationCommandUnderBothNamesReplacesTheModulesCommandForBoth(): void
    {
        $container = $this->buildContainerWithCommands([AgentCloseSchedulesForInactiveAgentSpacesCommand::class, ApplicationCloseSchedulesUnderBothNamesCommandDouble::class]);

        (new FrameworkCommandsYieldPass())->process($container);

        self::assertFalse($container->hasDefinition(AgentCloseSchedulesForInactiveAgentSpacesCommand::class), 'the module service of the shared name is removed');
        self::assertTrue($container->hasDefinition(ApplicationCloseSchedulesUnderBothNamesCommandDouble::class));
        (new AddConsoleCommandPass())->process($container);
        $commandMap = $container->getDefinition('console.command_loader')->getArgument(1);
        self::assertSame(ApplicationCloseSchedulesUnderBothNamesCommandDouble::class, $commandMap[self::MODULE_COMMAND_NAME]);
        self::assertSame(ApplicationCloseSchedulesUnderBothNamesCommandDouble::class, $commandMap[ApplicationCloseSchedulesUnderBothNamesCommandDouble::OLD_COMMAND_NAME]);
    }

    public function testAnApplicationSubclassUnderItsOldNameAloneReplacesTheModulesCommandAndKeepsItsName(): void
    {
        $container = $this->buildContainerWithCommands([AgentCloseSchedulesForInactiveAgentSpacesCommand::class, ApplicationCloseSchedulesUnderOldNameOnlyCommandDouble::class]);

        (new FrameworkCommandsYieldPass())->process($container);

        // a subclass replaces the module's command by class (Core 3.44.3) and is registered under its names as well
        self::assertFalse($container->hasDefinition(AgentCloseSchedulesForInactiveAgentSpacesCommand::class));
        self::assertTrue($container->hasDefinition(ApplicationCloseSchedulesUnderOldNameOnlyCommandDouble::class));
        (new AddConsoleCommandPass())->process($container);
        $commandMap = $container->getDefinition('console.command_loader')->getArgument(1);
        self::assertSame(ApplicationCloseSchedulesUnderOldNameOnlyCommandDouble::class, $commandMap[self::MODULE_COMMAND_NAME]);
        self::assertSame(ApplicationCloseSchedulesUnderOldNameOnlyCommandDouble::class, $commandMap[ApplicationCloseSchedulesUnderBothNamesCommandDouble::OLD_COMMAND_NAME]);
    }

    public function testTheOldNameRunsTheApplicationsCommandInAConsole(): void
    {
        $this->scriptCandidates();
        $applicationCommand = new ApplicationCloseSchedulesUnderBothNamesCommandDouble();
        $applicationCommand->schedulesServiceDouble = $this->schedulesServiceDouble;
        $application = new Application();
        $application->addCommand($applicationCommand);

        self::assertSame($applicationCommand, $application->find(ApplicationCloseSchedulesUnderBothNamesCommandDouble::OLD_COMMAND_NAME));
        self::assertSame($applicationCommand, $application->find(self::MODULE_COMMAND_NAME));
    }

    /**
     * Candidates of account 7: 1 → CANCELLED (space 70), 2 → PAUSED (no space), 3 → re-armed (space 70), 4 untouched,
     * 5 fails.
     */
    protected function scriptCandidates(): void
    {
        foreach ([1 => 70, 2 => null, 3 => 70, 4 => 70, 5 => 70] as $scheduleId => $agentSpaceId) {
            $schedule = new AIConversationSchedule();
            $schedule->id = $scheduleId;
            $schedule->accountId = 7;
            $schedule->agentSpaceId = $agentSpaceId;
            $this->schedulesServiceDouble->schedulesById[$scheduleId] = $schedule;
        }
        $this->schedulesServiceDouble->transitionsByScheduleId = [
            1 => $this->buildTransition(AIConversationScheduleStatus::CANCELLED, 'SCOPE_ENDED'),
            2 => $this->buildTransition(AIConversationScheduleStatus::PAUSED, 'SCOPE_SUSPENDED'),
            3 => $this->buildTransition(AIConversationScheduleStatus::ACTIVE, null),
        ];
        $this->schedulesServiceDouble->failingScheduleIds = [5];
    }

    protected function buildTransition(string $targetStatus, ?string $stateReason): AIConversationScheduleTransition
    {
        $transition = new AIConversationScheduleTransition();
        $transition->operation = AIConversationScheduleTransition::OPERATION_STATE_SWEEP;
        $transition->isAllowed = true;
        $transition->targetStatus = $targetStatus;
        $transition->stateReason = $stateReason;
        return $transition;
    }

    /**
     * A container with the commands registered as the autoconfiguration of `#[AsCommand]` tags them.
     *
     * @param class-string[] $commandClassNames
     * @return ContainerBuilder
     */
    protected function buildContainerWithCommands(array $commandClassNames): ContainerBuilder
    {
        $container = new ContainerBuilder();
        foreach ($commandClassNames as $commandClassName) {
            $asCommand = (new ReflectionClass($commandClassName))->getAttributes(AsCommand::class)[0]->newInstance();
            $definition = new Definition($commandClassName);
            $definition->setPublic(true);
            $definition->addTag(FrameworkCommandsYieldPass::CONSOLE_COMMAND_TAG, ['command' => $asCommand->name, 'description' => $asCommand->description]);
            $container->setDefinition($commandClassName, $definition);
        }
        return $container;
    }
}
