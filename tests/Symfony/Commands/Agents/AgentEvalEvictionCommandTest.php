<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Evals\AgentEvalEvictionFixture;
use DDD\Tests\Agents\Domain\AI\Entities\Evals\AgentEvalEvictionTurnsTest;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The live eviction evaluation on a scripted runner: the settings line, the load-curve table with its sums, peak
 * line and snapshot JSON, the lines of a skipped and of a failed trajectory, the verdict line, the context-retention
 * options over the fixture's settings, and the refusal of a bad option value.
 *
 * The expected printouts are the ones the command has always printed for these turns and settings: they are read
 * from `Fixtures/EvalProbes/expected-outputs.json` (keys `evictionFixtureRun` and `evictionLazyRun`), which holds the
 * outputs of the command's former code on the same input. The skip, error and verdict lines keep that code's format
 * with the scope word of the module (`agentSpaceId`).
 */
class AgentEvalEvictionCommandTest extends IntegrationTestCase
{
    protected ?string $fixtureFile = null;

    protected function tearDown(): void
    {
        if ($this->fixtureFile !== null && is_file($this->fixtureFile)) {
            unlink($this->fixtureFile);
        }
        parent::tearDown();
    }

    protected function writeFixture(): string
    {
        $this->fixtureFile = sys_get_temp_dir() . '/eviction-fixture-' . uniqid() . '.json';
        file_put_contents($this->fixtureFile, (string)json_encode([
            'settings' => ['eager' => true, 'soft' => 4000, 'hard' => 7000, 'ref' => 500, 'unloadToolAfterUnusedTokens' => 4000, 'unloadToolAfterUnusedTurns' => 3, 'unloadSkillAfterUnusedTokens' => 3000, 'unloadSkillAfterUnusedTurns' => 2, 'minResidencyTurns' => 1],
            'trajectories' => [
                ['name' => 'with-space', 'agentSpaceId' => 12, 'prompts' => ['first', 'second', 'third']],
                ['name' => 'without-space', 'prompts' => ['first']],
                ['name' => 'failing', 'agentSpaceId' => 12, 'prompts' => [AgentEvalRunnerServiceWithScriptedTrajectoriesDouble::FAILING_PROMPT]],
                ['name' => 'without-prompts', 'agentSpaceId' => 12, 'prompts' => []],
            ],
        ]));
        return $this->fixtureFile;
    }

    protected function buildCommand(): AgentEvalEvictionCommandWithScriptedRunner
    {
        return new AgentEvalEvictionCommandWithScriptedRunner(new AgentEvalRunnerServiceWithScriptedTrajectoriesDouble(AgentEvalEvictionTurnsTest::buildThreeTurns()));
    }

    public function testAFixtureRunPrintsTheSettingsTheLoadCurvesTheSkippedAndFailedTrajectoriesAndTheVerdict(): void
    {
        $agentEvalEvictionCommand = $this->buildCommand();
        $commandTester = new CommandTester($agentEvalEvictionCommand);

        $exitCode = $commandTester->execute(['--fixture' => $this->writeFixture(), '--json' => true, '--unload-granularity' => 'tool', '--model' => 'TEST.MODEL']);

        $formerPrintout = AgentEvalEvictionTurnsTest::expectedOutputs()['evictionFixtureRun']['output'];
        [$settingsLine, $trajectoryPrintout] = explode("\n", $formerPrintout, 2);
        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(
            $settingsLine . "\n"
            . "\n══ trajectory: with-space ══\n"
            . $trajectoryPrintout
            . "SKIP without-space — no agentSpaceId / no prompts\n"
            . "\n══ trajectory: failing ══\n"
            . '  ERROR ' . AgentEvalRunnerServiceWithScriptedTrajectoriesDouble::FAILURE_MESSAGE . "\n"
            . "SKIP without-prompts — no agentSpaceId / no prompts\n"
            . "\nEviction observed in 1/1 trajectories.\n",
            $commandTester->getDisplay()
        );
        $runnerDouble = $agentEvalEvictionCommand->agentEvalRunnerServiceDouble;
        self::assertSame(['with-space', 'failing'], $runnerDouble->resolvedCaseIds, 'the trajectory reaches the scope resolution as a case of its own name');
        self::assertSame([0, 0], $runnerDouble->fallbackAgentSpaceIds, 'no scope option: no fallback space');
        self::assertSame(['TEST.MODEL'], $runnerDouble->modelNamesOfTrajectories);
    }

    public function testTheOptionsOverrideTheFixtureAndOnlyTheNamedTrajectoryRuns(): void
    {
        $agentEvalEvictionCommand = $this->buildCommand();
        $commandTester = new CommandTester($agentEvalEvictionCommand);

        $commandTester->execute(['--fixture' => $this->writeFixture(), '--trajectory' => 'with-space', '--evict-soft' => '900', '--eager' => true, '--unload-granularity' => 'skill']);

        $display = $commandTester->getDisplay();
        self::assertStringStartsWith("Eviction eval — unloading=skill · loading=EAGER · soft=900 · hard=7000 · ref=500 · tool=4000/3t · skill=3000/2t · minRes=1\n", $display);
        self::assertStringNotContainsString('SKIP', $display);
        self::assertStringNotContainsString('"turn": 1', $display, 'snapshots only with --json');
        self::assertStringEndsWith("  peak carried = 7500 tok · soft = 900 · hard = 7000 · eviction fired ✓\n\nEviction observed in 1/1 trajectories.\n", $display);
        self::assertSame(900, $agentEvalEvictionCommand->agentEvalRunnerServiceDouble->settingsOfTrajectories[0]->pressureBudgetTokens);
    }

    public function testTheDefaultsReplaceTheFixturesStressSettings(): void
    {
        $commandTester = new CommandTester($this->buildCommand());

        $commandTester->execute(['--fixture' => $this->writeFixture(), '--trajectory' => 'with-space', '--defaults' => true, '--unload-granularity' => 'skill']);

        self::assertStringStartsWith('Eviction eval — unloading=skill · loading=EAGER · soft=', $commandTester->getDisplay());
        self::assertStringNotContainsString('soft=4000 · hard=7000', $commandTester->getDisplay());
    }

    public function testALazyTrajectoryWithoutEvictionPrintsWhatItHasAlwaysPrinted(): void
    {
        $agentEvalEvictionCommand = $this->buildCommand();
        $contextRetentionSettings = AgentEvalEvictionFixture::fromDecodedFields(['settings' => ['eager' => false, 'soft' => '900']])->buildRetentionSettings();
        $contextRetentionSettings->unloadGranularity = 'skill';
        $firstTurnOnly = AgentEvalEvictionTurnsTest::buildThreeTurns()->getElementsSlice(0, 1);
        $output = new BufferedOutput();

        $agentEvalEvictionCommand->printSettingsOf($contextRetentionSettings, $output);
        $anyEviction = $agentEvalEvictionCommand->renderTrajectoryOf($firstTurnOnly, $contextRetentionSettings, $output, false);

        self::assertFalse($anyEviction);
        self::assertSame(AgentEvalEvictionTurnsTest::expectedOutputs()['evictionLazyRun']['output'], $output->fetch());
    }

    public function testAFixtureWithoutTrajectoriesAndABadOptionValueAreRefused(): void
    {
        $commandTester = new CommandTester($this->buildCommand());
        self::assertSame(Command::FAILURE, $commandTester->execute(['--fixture' => '/no/such/fixture.json']));
        self::assertSame("No trajectories found in fixture\n", $commandTester->getDisplay());

        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('--evict-soft');
        (new CommandTester($this->buildCommand()))->execute(['--fixture' => $this->writeFixture(), '--evict-soft' => 'many']);
    }
}
