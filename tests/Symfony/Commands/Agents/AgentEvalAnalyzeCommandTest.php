<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRunReport;
use DDD\Domain\AI\Entities\Evals\AgentEvalAnalyzeCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRow;
use DDD\Symfony\Commands\Agents\AgentEvalConsoleCommand;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The failure analysis of a stored run: the run named by its name or the most recent one, the header with the counts,
 * one block per failed case with its class (the trace read from the case file beside a run folder's summary), the
 * reproduce line, the count per class; and the error lines of a run that is not found.
 *
 * The expected lines are the analysis's line formats applied to the test's run (`Fail analysis — <file>`,
 * `model=<model> · <n> cases · <p> pass · <f> fail`, `[<class>] <case>`, `  want: <tool>  |  got: [<tools>]`,
 * `  error: <error>`, `  ✗ <failure>`, `  → <tool>(<arguments>) [<status>]`, `  finalText: <text>|<empty>`,
 * `  repro: php bin/console app:agent:eval-tools --case=<case>[ --model=<model>] --full`, `── Summary ──`,
 * `  %-20s %d`, `  (no failures 🎉)`, `Run not found: <path>`, `No runs directory: <folder>`, `No runs found under
 * <folder>`); the run folder is laid out as a tools run writes it (`_summary.json` without traces, `<caseId>.json`
 * with the trace).
 */
class AgentEvalAnalyzeCommandTest extends IntegrationTestCase
{
    public const string RUN_NAME = '2026-11-01_120000-VENDOR.MODEL-all';

    protected string $resultsDirectory = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resultsDirectory = sys_get_temp_dir() . '/agent-eval-analyze-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->resultsDirectory . '/' . self::RUN_NAME, 0775, true);
        $this->writeRunFile('_summary.json', ['total' => 4, 'passed' => 1, 'model' => 'VENDOR.MODEL', 'cases' => [
            ['skill' => 'Catalog.Items', 'case' => 'analyze.pass', 'tool' => 'listItems', 'passed' => true],
            ['skill' => 'Catalog.Items', 'case' => 'analyze.bad-args', 'tool' => 'listItems', 'passed' => false, 'matchedTool' => 'listItems', 'calledTools' => ['listItems'], 'failures' => ['status FAILED, expected COMPLETED'], 'finalText' => "Sorry,\n the list failed."],
            ['skill' => 'Catalog.Items', 'case' => 'analyze.stall', 'tool' => 'listItems', 'passed' => false, 'matchedTool' => null, 'calledTools' => ['load_skills'], 'failures' => ['tool not called'], 'finalText' => 'Stopped. ' . AgentRunReport::STOP_REASON_STEP_BUDGET_USED_UP],
            ['skill' => 'Catalog.Items', 'case' => 'analyze.error', 'passed' => false, 'error' => 'the child crashed'],
        ]]);
        // a trace of a tool call written on the message itself
        $this->writeRunFile('analyze.bad-args.json', ['case' => 'analyze.bad-args', 'tool' => 'listItems', 'passed' => false, 'matchedTool' => 'listItems', 'calledTools' => ['listItems'], 'failures' => ['status FAILED, expected COMPLETED'], 'finalText' => "Sorry,\n the list failed.", 'messages' => ['elements' => [
            ['messageType' => 'TOOL_CALL', 'toolName' => 'listItems', 'toolArguments' => '{"top": 5}', 'status' => 'FAILED'],
        ]]]);
        // a trace of a tool call kept in its `toolCall` object
        $this->writeRunFile('analyze.stall.json', ['case' => 'analyze.stall', 'tool' => 'listItems', 'passed' => false, 'calledTools' => ['load_skills'], 'failures' => ['tool not called'], 'finalText' => 'Stopped. ' . AgentRunReport::STOP_REASON_STEP_BUDGET_USED_UP, 'messages' => ['elements' => [
            ['messageType' => 'TEXT', 'message' => 'List my items'],
            ['messageType' => 'TOOL_CALL', 'toolCall' => ['name' => 'load_skills', 'arguments' => ['skill_names' => ['Catalog.Items']], 'status' => 'COMPLETED']],
        ]]]);
    }

    protected function onTearDown(): void
    {
        foreach (['_summary.json', 'analyze.bad-args.json', 'analyze.stall.json'] as $runFileName) {
            @unlink($this->resultsDirectory . '/' . self::RUN_NAME . '/' . $runFileName);
        }
        @rmdir($this->resultsDirectory . '/' . self::RUN_NAME);
        @rmdir($this->resultsDirectory);
    }

    public function testEveryFailedCasePrintsItsBlockAndTheSummaryCountsTheClasses(): void
    {
        $commandTester = $this->runProbeCommand([]);

        self::assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $summaryFile = $this->resultsDirectory . '/' . self::RUN_NAME . '/_summary.json';
        $stallFinalText = 'Stopped. ' . AgentRunReport::STOP_REASON_STEP_BUDGET_USED_UP;
        self::assertSame(
            "\n"
            . "Fail analysis — {$summaryFile}\n"
            . "model=VENDOR.MODEL · 4 cases · 1 pass · 3 fail\n"
            . "\n"
            . "[BAD_ARGS] analyze.bad-args\n"
            . "  want: listItems  |  got: [listItems]\n"
            . "  ✗ status FAILED, expected COMPLETED\n"
            . "  → listItems({\"top\": 5}) [FAILED]\n"
            . "  finalText: Sorry, the list failed.\n"
            . "  repro: php bin/console app:agent:eval-tools --case=analyze.bad-args --model=VENDOR.MODEL --full\n"
            . "\n"
            . "[STALL] analyze.stall\n"
            . "  want: listItems  |  got: [load_skills]\n"
            . "  ✗ tool not called\n"
            . "  → load_skills({\"skill_names\":[\"Catalog.Items\"]}) [COMPLETED]\n"
            . '  finalText: ' . mb_substr($stallFinalText, 0, 100) . " …\n"
            . "  repro: php bin/console app:agent:eval-tools --case=analyze.stall --model=VENDOR.MODEL --full\n"
            . "\n"
            . "[ERROR] analyze.error\n"
            . "  want: ?  |  got: []\n"
            . "  error: the child crashed\n"
            . "  finalText: <empty>\n"
            . "  repro: php bin/console app:agent:eval-tools --case=analyze.error --model=VENDOR.MODEL --full\n"
            . "\n"
            . "── Summary ──\n"
            . sprintf('  %-20s %d', 'BAD_ARGS', 1) . "\n"
            . sprintf('  %-20s %d', 'STALL', 1) . "\n"
            . sprintf('  %-20s %d', 'ERROR', 1) . "\n",
            $commandTester->getDisplay()
        );
    }

    public function testARunNamedByItsNameWithThePassedCasesCounted(): void
    {
        $commandTester = $this->runProbeCommand(['path' => self::RUN_NAME, '--all' => true]);

        self::assertStringEndsWith(
            "── Summary ──\n" . sprintf('  %-20s %d', 'PASS', 1) . "\n" . sprintf('  %-20s %d', 'BAD_ARGS', 1) . "\n" . sprintf('  %-20s %d', 'STALL', 1) . "\n" . sprintf('  %-20s %d', 'ERROR', 1) . "\n",
            $commandTester->getDisplay()
        );
    }

    public function testARunWithoutAFailureSaysSo(): void
    {
        $this->writeRunFile('_summary.json', ['model' => null, 'cases' => [['case' => 'analyze.pass', 'passed' => true]]]);

        $commandTester = $this->runProbeCommand([]);

        self::assertStringEndsWith("model=(default) · 1 cases · 1 pass · 0 fail\n\n── Summary ──\n  (no failures 🎉)\n", $commandTester->getDisplay());
    }

    public function testTheErrorLinesOfARunThatIsNotThere(): void
    {
        $commandTester = $this->runProbeCommand(['path' => 'nope']);
        self::assertSame(Command::FAILURE, $commandTester->getStatusCode());
        self::assertSame("Run not found: nope\n", $commandTester->getDisplay());

        $commandTester = $this->runProbeCommand([], $this->resultsDirectory . '/missing');
        self::assertSame("No runs directory: {$this->resultsDirectory}/missing\n", $commandTester->getDisplay());

        $emptyDirectory = $this->resultsDirectory . '/' . self::RUN_NAME . '/empty';
        mkdir($emptyDirectory);
        $commandTester = $this->runProbeCommand([], $emptyDirectory);
        rmdir($emptyDirectory);
        self::assertSame(Command::FAILURE, $commandTester->getStatusCode());
        self::assertSame("No runs found under {$emptyDirectory}\n", $commandTester->getDisplay());

        $this->writeRunFile('_summary.json', ['total' => 0]);
        $commandTester = $this->runProbeCommand([]);
        self::assertSame("Not a valid run file (no 'cases'): {$this->resultsDirectory}/" . self::RUN_NAME . "/_summary.json\n", $commandTester->getDisplay());
    }

    public function testTheClassesOfTheAnalysis(): void
    {
        self::assertSame('WRONG_SKILL', $this->classify(['passed' => false, 'failures' => ["skill not loaded: expected 'A'"]]));
        self::assertSame('TOOL_DATA_BUG', $this->classify(['passed' => false, 'messages' => $this->buildDataCallTrace(['FAILED'])]));
        self::assertSame('FIXTURE_TOO_STRICT', $this->classify(['passed' => false, 'messages' => $this->buildDataCallTrace(['FAILED', 'COMPLETED'])]));
        self::assertSame('ANSWERED_WITHOUT_TOOL', $this->classify(['passed' => false, 'finalText' => 'Which items?']));
        self::assertSame('UNCLASSIFIED', $this->classify(['passed' => false]));
        self::assertSame('STALL', $this->classify(['passed' => false, 'finalText' => AgentRunReport::STOP_REASON_STEP_BUDGET_USED_UP, 'messages' => $this->buildDataCallTrace(['RUNNING'])]), 'a stall after a data call that did not finish');
        self::assertInstanceOf(AgentEvalConsoleCommand::class, new AgentEvalAnalyzeProbeCommand());
    }

    /** @param array<string, mixed> $rowFields */
    protected function classify(array $rowFields): string
    {
        return AgentEvalAnalyzeCase::fromCaseRow(AgentEvalCaseRow::fromArray($rowFields))->classify();
    }

    /**
     * A trace of calls of a data tool, one per status.
     *
     * @param string[] $statuses
     * @return array<string, mixed>
     */
    protected function buildDataCallTrace(array $statuses): array
    {
        $traceMessages = [];
        foreach ($statuses as $status) {
            $traceMessages[] = ['messageType' => 'TOOL_CALL', 'toolCall' => ['name' => 'listItems', 'arguments' => [], 'status' => $status]];
        }
        return ['elements' => $traceMessages];
    }

    /** @param array<string, mixed> $document */
    protected function writeRunFile(string $runFileName, array $document): void
    {
        file_put_contents($this->resultsDirectory . '/' . self::RUN_NAME . '/' . $runFileName, (string)json_encode($document));
    }

    /** @param array<string, mixed> $commandInput */
    protected function runProbeCommand(array $commandInput, ?string $resultsDirectory = null): CommandTester
    {
        $probeCommand = new AgentEvalAnalyzeProbeCommand();
        $probeCommand->resultFilesServiceDouble->resultsDirectoryOfTest = $resultsDirectory ?? $this->resultsDirectory;
        $commandTester = new CommandTester($probeCommand);
        $commandTester->execute($commandInput, ['capture_stderr_separately' => true]);
        return $commandTester;
    }
}
