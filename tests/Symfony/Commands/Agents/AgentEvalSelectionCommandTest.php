<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRow;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use DDD\Symfony\Commands\Agents\AgentEvalConsoleCommand;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The skill-selection evaluation: one line per case (hit, miss, not applicable, skipped without a scope, error), the
 * accuracy over the cases that count, the run summary as printed JSON and as the result file a suite reads, and the
 * stored run of a run without a result file.
 *
 * The expected lines are the evaluation's line formats applied to the test's cases (`  [<position>] HIT |MISS
 * expected=<skills> loaded=[<skills>] — <prompt>`, `  [<position>] N/A  <detail>`, `  [<position>] SKIP <missing
 * scope> — <prompt>`, `  [<position>] ERROR <message>`, `Selection accuracy: <hits>/<counted> (<percent>%)`); the
 * expected key lists are the array literals of the rows the evaluation writes (the hit row, the error row, the
 * not-applicable row) and of its stored run options, copied key by key.
 */
class AgentEvalSelectionCommandTest extends IntegrationTestCase
{
    protected string $workDirectory = '';

    protected string $fixturePath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDirectory = sys_get_temp_dir() . '/agent-eval-selection-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->workDirectory, 0775, true);
        $this->fixturePath = $this->workDirectory . '/catalog.json';
        file_put_contents($this->fixturePath, (string)json_encode(['cases' => [
            ['id' => 'selection.hit', 'agentSpaceId' => 7, 'prompt' => 'Show my items', 'expectedSkill' => 'Other.Skill|Catalog.Items'],
            ['id' => 'selection.not-applicable', 'agentSpaceId' => 7, 'prompt' => 'Show my invoices', 'expectedSkill' => 'Catalog.Items'],
            ['prompt' => 'What can you do?', 'expectedSkill' => 'Catalog.Items'],
            ['id' => 'selection.error', 'agentSpaceId' => 7, 'prompt' => 'Break', 'expectedSkill' => 'Catalog.Items'],
            'no case',
        ]]));
    }

    protected function onTearDown(): void
    {
        MCPToolExecutorService::$everyWriteToolBlocked = false;
        foreach ((array)glob($this->workDirectory . '/*') as $writtenFile) {
            @unlink((string)$writtenFile);
        }
        @rmdir($this->workDirectory);
    }

    public function testEveryCasePrintsItsLineAndTheAccuracyCountsTheCasesThatCount(): void
    {
        $probeCommand = $this->buildProbeCommand();

        $commandTester = $this->runProbeCommand($probeCommand, ['--fixture' => $this->fixturePath]);

        self::assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        self::assertSame(
            "  [0] HIT  expected=Other.Skill|Catalog.Items loaded=[Catalog.Items] — Show my items\n"
            . "  [1] N/A  the seed of the case failed\n"
            . "  [2] SKIP no agentSpaceId (case or --agentSpaceId fallback) — What can you do?\n"
            . "  [3] ERROR the model did not answer\n"
            . "\n"
            . "Selection accuracy: 1/2 (50%)\n",
            $commandTester->getDisplay()
        );
        self::assertSame(['Show my items', 'Break'], $probeCommand->runnerServiceDouble->ranPrompts, 'the not-applicable and the skipped case do not run');
        self::assertSame(['default|persist=1|writesBlocked=1|keepSeed=0', 'default|persist=1|writesBlocked=1|keepSeed=0'], $probeCommand->runnerServiceDouble->runSettingsOfRuns);
        self::assertTrue(MCPToolExecutorService::$everyWriteToolBlocked, 'nothing the agent would change reaches the space');
        self::assertSame(['{"agentSpaceId":7}|-', '{"agentSpaceId":7}|-'], $probeCommand->runnerServiceDouble->resolvedScopes, 'the run case keeps the scope and the prompt only');
    }

    public function testTheScopeOptionNamesTheSpaceOfACaseThatNamesNone(): void
    {
        $probeCommand = $this->buildProbeCommand();

        $commandTester = $this->runProbeCommand($probeCommand, ['--fixture' => $this->fixturePath, '--agentSpaceId' => '7', '--case' => 'catalog.2', '--transient' => true]);

        self::assertSame(
            "  [2] HIT  expected=Catalog.Items loaded=[Catalog.Items] — What can you do?\n\nSelection accuracy: 1/1 (100%)\n",
            $commandTester->getDisplay(),
            'a case without an id is named after the fixture and its position'
        );
        self::assertSame(['[]|7'], $probeCommand->runnerServiceDouble->resolvedScopes);
        self::assertSame(['default|persist=0|writesBlocked=1|keepSeed=0'], $probeCommand->runnerServiceDouble->runSettingsOfRuns);
    }

    public function testTheResultFileAndThePrintedJsonAreTheRunSummaryWithTheRowsKeysInTheirOrder(): void
    {
        $probeCommand = $this->buildProbeCommand();
        $resultFile = $this->workDirectory . '/result.json';

        $commandTester = $this->runProbeCommand($probeCommand, ['--fixture' => $this->fixturePath, '--json' => true, '--result-file' => $resultFile, '--model' => 'VENDOR.MODEL']);

        $printedJson = $commandTester->getDisplay();
        self::assertSame((string)file_get_contents($resultFile) . "\n", $printedJson, 'the result file holds exactly what is printed');
        self::assertSame(
            (string)json_encode(json_decode($printedJson, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            $printedJson
        );
        $resultDocument = json_decode($printedJson, true);
        self::assertSame(['total', 'passed', 'errors', 'notApplicable', 'model', 'flavor', 'arms', 'metrics', 'cases'], array_keys($resultDocument));
        self::assertSame([2, 1, 1, 1, 'VENDOR.MODEL', 'call', []], array_slice(array_values($resultDocument), 0, 7));
        [$hitRow, $notApplicableRow, $errorRow] = $resultDocument['cases'];
        self::assertSame(
            ['skill', 'case', 'passed', 'conversationId', 'failures', 'loadedSkills', 'calledTools', 'toolCallCount', 'workToolCallCount', 'totalTokens', 'costUsd', 'durationMs', 'performance'],
            array_keys($hitRow)
        );
        self::assertSame(['Other.Skill|Catalog.Items', 'selection.hit', true, null, [], ['Catalog.Items'], ['listItems'], 1], array_slice(array_values($hitRow), 0, 8));
        self::assertSame(
            ['skill' => 'Catalog.Items', 'case' => 'selection.not-applicable', 'flavor' => 'call', 'agentSpaceId' => 7, 'passed' => null, 'outcome' => AgentEvalCaseRow::OUTCOME_NOT_APPLICABLE, 'notApplicableReason' => 'preconditionNotEstablished', 'notApplicableDetail' => 'the seed of the case failed'],
            $notApplicableRow
        );
        self::assertSame(['skill' => 'Catalog.Items', 'case' => 'selection.error', 'passed' => false, 'error' => 'the model did not answer'], $errorRow);
        self::assertSame(['VENDOR.MODEL|persist=1|writesBlocked=1|keepSeed=0', 'VENDOR.MODEL|persist=1|writesBlocked=1|keepSeed=0'], $probeCommand->runnerServiceDouble->runSettingsOfRuns);
        self::assertNull($probeCommand->suiteRunsServiceDouble->insertedAgentEvalSuiteRun, 'a case of a suite leaves its record to the suite');
    }

    public function testARunWithoutAResultFileIsStoredWithItsOptions(): void
    {
        $probeCommand = $this->buildProbeCommand();

        $this->runProbeCommand($probeCommand, ['--fixture' => $this->fixturePath, '--case' => 'selection.hit']);

        $storedRun = $probeCommand->suiteRunsServiceDouble->insertedAgentEvalSuiteRun;
        self::assertNotNull($storedRun);
        self::assertSame('app:ai-agent:eval-selection', $storedRun->command);
        self::assertSame('catalog', $storedRun->suiteLabel, 'the label is the fixture name');
        self::assertSame(['model' => null, 'flavor' => 'call', 'arms' => [], 'case' => 'selection.hit'], $storedRun->getRunOptions()->toArray());
        self::assertSame(1, $storedRun->casesPassed);
    }

    public function testTheConfiguredFixtureIsTheDefaultAndAMissingFixtureFails(): void
    {
        $probeCommand = $this->buildProbeCommand();
        $probeCommand->planServiceDouble->fixturePathsByName = ['selection' => $this->fixturePath];

        $commandTester = $this->runProbeCommand($probeCommand, ['--case' => 'selection.hit', '--json' => true]);

        self::assertSame(1, json_decode($commandTester->getDisplay(), true)['passed']);

        $commandTester = $this->runProbeCommand($this->buildProbeCommand(), []);
        self::assertSame(Command::FAILURE, $commandTester->getStatusCode());
        self::assertSame("No eval cases found in fixture\n", $commandTester->getDisplay());

        $commandTester = $this->runProbeCommand($this->buildProbeCommand(), ['--fixture' => $this->fixturePath, '--case' => 'missing']);
        self::assertSame(Command::INVALID, $commandTester->getStatusCode());
        self::assertSame("No case matched.\n", $commandTester->getDisplay());
    }

    public function testTheCommandIsAnEvaluationCommandWithTheArmAndModelOptions(): void
    {
        $probeCommand = $this->buildProbeCommand();

        self::assertInstanceOf(AgentEvalConsoleCommand::class, $probeCommand);
        foreach (['fixture', 'case', 'json', 'result-file', 'transient', 'persist-conversations', 'model', 'tier', 'vendor', 'ignore-budget', 'agentSpaceId', AgentSpacesServiceConsoleScopeDouble::SCOPE_OPTION_NAME, 'decision-model-skill-preload'] as $optionName) {
            self::assertTrue($probeCommand->getDefinition()->hasOption($optionName), $optionName);
        }
    }

    /** @param array<string, mixed> $commandInput */
    protected function runProbeCommand(AgentEvalSelectionProbeCommand $probeCommand, array $commandInput): CommandTester
    {
        $probeCommand->runnerServiceDouble->notApplicableCaseIds = ['selection.not-applicable'];
        $probeCommand->runnerServiceDouble->throwingPrompts = ['Break'];
        $probeCommand->runnerServiceDouble->loadedSkillNames = ['Catalog.Items'];
        $commandTester = new CommandTester($probeCommand);
        $commandTester->execute($commandInput, ['capture_stderr_separately' => true]);
        return $commandTester;
    }

    protected function buildProbeCommand(): AgentEvalSelectionProbeCommand
    {
        return new AgentEvalSelectionProbeCommand();
    }
}
