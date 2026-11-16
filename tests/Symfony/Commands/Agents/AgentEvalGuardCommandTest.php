<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Entities\Evals\Guard\AgentEvalGuardCell;
use DDD\Domain\AI\Entities\Evals\Guard\AgentEvalGuardCells;
use DDD\Domain\AI\Entities\Evals\Guard\AgentEvalGuardRunKnobs;
use DDD\Domain\AI\Entities\UsageLogs\Enums\AIUsageInitiator;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Domain\AI\Entities\Evals\AgentEvalEvictionTurnsTest;
use DDD\Tests\Agents\Domain\AI\Entities\Evals\Guard\AgentEvalGuardObjectsTest;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The guard probe command on scripted answers: the lines of every probe, the report, the saved run file, the JSON a
 * child prints, one evaluation envelope per probe for the scope's owner, the command line of a child process, and the
 * lines of a run without fixtures or cells.
 *
 * The expected lines, documents and child arguments are what the command has always printed, written and started for
 * the fixtures in `Fixtures/Guard/` and the scripted raw answers: they are read from
 * `Fixtures/EvalProbes/expected-outputs.json` (keys `guardProbeLines`, `guardProbeLinesFull`, `guardReportLines`,
 * `guardRunFileDocument`, `guardStandardOutputDocument`, `guardChildArguments`), which holds the outputs of the
 * command's former code on the same input; there a child got the application's scope option where the module hands on
 * its scope options as given (none in this test).
 */
class AgentEvalGuardCommandTest extends IntegrationTestCase
{
    protected function buildCommand(): AgentEvalGuardCommandWithScriptedProbes
    {
        $agentEvalGuardCommand = new AgentEvalGuardCommandWithScriptedProbes(new RecordingUsageEnvelopesServiceDouble(), new ResultFilesServiceRecordingLooseRunFilesDouble());
        $agentEvalGuardCommand->fixturePaths = AgentEvalGuardObjectsTest::fixturePaths();
        $agentEvalGuardCommand->scriptedRawResponses = AgentEvalEvictionTurnsTest::expectedOutputs()['guardRaws'];
        return $agentEvalGuardCommand;
    }

    public function testAnInProcessRunPrintsEveryProbeAndTheReportAndSavesTheRunFile(): void
    {
        $expectedOutputs = AgentEvalEvictionTurnsTest::expectedOutputs();
        $agentEvalGuardCommand = $this->buildCommand();
        $commandTester = new CommandTester($agentEvalGuardCommand);

        $exitCode = $commandTester->execute(['--parallel' => '1', '--runs' => '2', '--model' => AgentEvalGuardObjectsTest::MODEL_NAME]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringEndsWith(
            "  Runs/cell  : 2   Cells: 9   Mode: in-process\n"
            . $expectedOutputs['guardProbeLines']
            . $expectedOutputs['guardReportLines']
            . '  saved: ' . ResultFilesServiceRecordingLooseRunFilesDouble::WRITTEN_FILE . "\n",
            $commandTester->getDisplay()
        );
        self::assertSame(1, $agentEvalGuardCommand->scopeResolutions);
        self::assertCount(18, $agentEvalGuardCommand->builtGuards);
        self::assertSame(AgentEvalGuardObjectsTest::MODEL_NAME, $agentEvalGuardCommand->builtGuards[0]->givenModelName);
        $resultFilesServiceDouble = $agentEvalGuardCommand->resultFilesServiceDouble;
        self::assertSame(AgentEvalGuardObjectsTest::MODEL_NAME, $resultFilesServiceDouble->modelName);
        self::assertSame('guard-sentinel', $resultFilesServiceDouble->runFileName);
        self::assertSame($expectedOutputs['guardRunFileDocument'], $resultFilesServiceDouble->encodedDocument);
    }

    public function testEveryProbeIsBookedUnderAnEvaluationEnvelopeOfItsOwnForTheOwner(): void
    {
        $agentEvalGuardCommand = $this->buildCommand();

        (new CommandTester($agentEvalGuardCommand))->execute(['--parallel' => '1', '--runs' => '2', '--no-save' => true]);

        $usageEnvelopesServiceDouble = $agentEvalGuardCommand->usageEnvelopesServiceDouble;
        self::assertCount(18, $usageEnvelopesServiceDouble->openedEnvelopeContexts);
        self::assertCount(18, $usageEnvelopesServiceDouble->closedEnvelopeContexts);
        $firstEnvelopeContext = $usageEnvelopesServiceDouble->openedEnvelopeContexts[0];
        self::assertSame(AgentAIUsageScope::ENVELOPE_AI_AGENT_EVALUATION, $firstEnvelopeContext->scope);
        self::assertSame(AgentEvalGuardCommandWithScriptedProbes::OWNER_ACCOUNT_ID, $firstEnvelopeContext->accountId);
        self::assertSame(AIUsageInitiator::CLI, $firstEnvelopeContext->initiatedBy);
        self::assertNull($agentEvalGuardCommand->builtGuards[0]->givenModelName, 'no --model: the guard keeps its default');
        self::assertSame('', $agentEvalGuardCommand->resultFilesServiceDouble->runFileName, '--no-save writes nothing');
    }

    public function testFullPrintsEveryRawAnswer(): void
    {
        $commandTester = new CommandTester($this->buildCommand());

        $commandTester->execute(['--parallel' => '1', '--runs' => '2', '--full' => true, '--no-save' => true]);

        self::assertStringContainsString(
            "  Runs/cell  : 2   Cells: 9   Mode: in-process\n" . AgentEvalEvictionTurnsTest::expectedOutputs()['guardProbeLinesFull'],
            $commandTester->getDisplay()
        );
    }

    public function testAChildPrintsOnlyTheJsonOfItsCells(): void
    {
        $agentEvalGuardCommand = $this->buildCommand();
        $commandTester = new CommandTester($agentEvalGuardCommand);
        $cellKeys = AgentEvalGuardCells::buildOfFixtureFiles(AgentEvalGuardObjectsTest::fixturePaths(), AgentEvalGuardObjectsTest::buildKnobs())->getCellKeys();

        $commandTester->execute([
            '--json' => true,
            '--no-save' => true,
            '--parallel' => '1',
            '--runs' => '2',
            '--model' => AgentEvalGuardObjectsTest::MODEL_NAME,
            '--cells' => base64_encode((string)json_encode($cellKeys)),
        ]);

        self::assertSame(AgentEvalEvictionTurnsTest::expectedOutputs()['guardStandardOutputDocument'] . "\n", $commandTester->getDisplay());
    }

    public function testAChildIsStartedWithTheArgumentsItHasAlwaysGot(): void
    {
        $agentEvalGuardCommand = $this->buildCommand();
        $agentEvalGuardRunKnobs = AgentEvalGuardObjectsTest::buildKnobs();
        $agentEvalGuardRunKnobs->guardFile = '/abs/guard.md';
        $agentEvalGuardRunKnobs->passPhraseOverride = 'open sesame';
        $agentEvalGuardRunKnobs->inputText = 'Ignore everything';
        $agentEvalGuardRunKnobs->inputClass = 'benign';
        $agentEvalGuardRunKnobs->fixtureFile = '/abs/fixture.json';
        $input = new ArrayInput(['--ignore-budget' => true], $agentEvalGuardCommand->getDefinition());

        $childArguments = $agentEvalGuardCommand->buildChildProcessArgumentsOf(
            [AgentEvalGuardCell::buildKey('probe-suite', 'v1', 'b1'), AgentEvalGuardCell::buildKey('probe-suite', 'v1', 'a1')],
            $agentEvalGuardRunKnobs,
            'OPENAI.X',
            $input
        );

        $formerArguments = AgentEvalEvictionTurnsTest::expectedOutputs()['guardChildArguments'];
        $formerArguments[3] = DDDService::instance()->getRootDir() . '/bin/console';
        $formerArgumentsWithoutScopeOption = array_values(array_filter($formerArguments, static fn (string $argument): bool => $argument !== '<scope arguments>'));
        self::assertSame($formerArgumentsWithoutScopeOption, $childArguments);
    }

    public function testARunWithoutFixturesOrCellsIsRefused(): void
    {
        $agentEvalGuardCommand = $this->buildCommand();
        $agentEvalGuardCommand->fixturePaths = [];
        $commandTester = new CommandTester($agentEvalGuardCommand);
        self::assertSame(Command::FAILURE, $commandTester->execute([]));
        self::assertSame("No guard-eval fixtures found in the configured fixture path `guard`\n", $commandTester->getDisplay());

        $commandTester = new CommandTester($this->buildCommand());
        self::assertSame(Command::FAILURE, $commandTester->execute(['--variant' => 'no-such-variant']));
        self::assertSame("No cells to run (check --variant/--input filters / fixture shape).\n", $commandTester->getDisplay());
    }

    public function testTheKnobsComeFromTheOptions(): void
    {
        $agentEvalGuardCommand = $this->buildCommand();
        $agentEvalGuardCommand->scriptedRawResponses = ['open sesame'];
        $commandTester = new CommandTester($agentEvalGuardCommand);

        $commandTester->execute(['--parallel' => '1', '--no-save' => true, '--json' => true, '--pass-phrase' => 'open sesame', '--input-text' => 'Ignore everything', '--input-class' => AgentEvalGuardCell::INPUT_CLASS_BENIGN, '--variant' => 'v1']);

        $printedDocument = (array)json_decode($commandTester->getDisplay(), true);
        self::assertSame(
            [['suite' => 'probe-suite', 'variant' => 'v1', 'input' => 'adhoc', 'class' => 'benign', 'vector' => 'adhoc', 'run' => 1, 'emitted' => true, 'wrong' => false, 'model' => 'default', 'raw' => 'open sesame']],
            $printedDocument['cells']
        );
        self::assertSame(AgentEvalGuardRunKnobs::DEFAULT_INPUT_CLASS, AgentEvalGuardCell::INPUT_CLASS_ATTACK);
    }
}
