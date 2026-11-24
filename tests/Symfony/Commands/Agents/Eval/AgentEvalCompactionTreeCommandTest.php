<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\Eval;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Evals\AgentEvalCompactionTreeRunResult;
use DDD\Domain\AI\Entities\Evals\AgentEvalJudgeVerdict;
use DDD\Symfony\Commands\Agents\AgentConsoleCommand;
use DDD\Symfony\Commands\Agents\AgentEvalCompactionTreeCommand;
use DDD\Symfony\Commands\Agents\AgentEvalConsoleCommand;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Tests\Agents\Symfony\Commands\Agents\AgentSpacesServiceConsoleScopeDouble;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The compaction-tree evaluation reads its case from `--case-file` (or the application's default case file) and
 * refuses to run without one; it fills the case's parameters template from the options, resolves the space from the
 * case file, else from the scope options, hands the judged case to the judged run and prints the trees under English headings
 * (an application keeps its own headings by overriding the two heading methods), or the `--json` document.
 */
class AgentEvalCompactionTreeCommandTest extends UnitTestCase
{
    protected JudgedRunServiceWithScriptedTreeRunDouble $judgedRunServiceDouble;

    /** @var string[] The files a test wrote; removed in tearDown() */
    protected array $writtenFilePaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->judgedRunServiceDouble = new JudgedRunServiceWithScriptedTreeRunDouble();
    }

    protected function tearDown(): void
    {
        foreach ($this->writtenFilePaths as $writtenFilePath) {
            if (is_file($writtenFilePath)) {
                unlink($writtenFilePath);
            }
        }
        parent::tearDown();
    }

    public function testTheCommandKeepsItsNameAndOptions(): void
    {
        $asCommandAttributes = (new ReflectionClass(AgentEvalCompactionTreeCommand::class))->getAttributes(AsCommand::class);
        self::assertSame('app:agent:eval-compaction-tree', explode('|', $asCommandAttributes[0]->newInstance()->name)[0], 'the old name stays an alias');

        $probeCommand = $this->buildProbeCommand();
        self::assertInstanceOf(AgentEvalConsoleCommand::class, $probeCommand);
        self::assertSame(
            [
                AgentConsoleCommand::OPTION_AGENT_SPACE_ID,
                AgentSpacesServiceConsoleScopeDouble::SCOPE_OPTION_NAME,
                AgentEvalConsoleCommand::OPTION_EVAL_ACCOUNT_ID,
                AgentEvalCompactionTreeCommand::OPTION_CASE_FILE,
                'agent-model', 'judge-model', 'driver-model', 'max-turns', 'segment-messages', 'keep-messages', 'fan-in', 'retention', 'trigger-tokens',
                AgentConsoleCommand::OPTION_IGNORE_BUDGET,
                'persist', 'json',
            ],
            array_keys($probeCommand->getDefinition()->getOptions())
        );
        $commandDefinition = $probeCommand->getDefinition();
        self::assertSame(['14', '3', '3', '2', '1', '200'], [
            $commandDefinition->getOption('max-turns')->getDefault(),
            $commandDefinition->getOption('segment-messages')->getDefault(),
            $commandDefinition->getOption('keep-messages')->getDefault(),
            $commandDefinition->getOption('fan-in')->getDefault(),
            $commandDefinition->getOption('retention')->getDefault(),
            $commandDefinition->getOption('trigger-tokens')->getDefault(),
        ]);
    }

    public function testTheCommandRefusesToRunWithoutACaseFile(): void
    {
        $commandTester = new CommandTester($this->buildProbeCommand());

        $exitCode = $commandTester->execute(['--agentSpaceId' => (string)AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID]);

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('--case-file is required (this command has no default case)', $commandTester->getDisplay());
        self::assertNull($this->judgedRunServiceDouble->receivedSimCase, 'nothing ran');
    }

    public function testACaseFileThatIsMissingOrLacksARequiredTextIsRefused(): void
    {
        $commandTester = new CommandTester($this->buildProbeCommand());
        self::assertSame(2, $commandTester->execute(['--case-file' => sys_get_temp_dir() . '/compaction-tree-case-that-does-not-exist.json']));
        self::assertStringContainsString('Case file not found', $commandTester->getDisplay());

        $commandTester = new CommandTester($this->buildProbeCommand());
        self::assertSame(2, $commandTester->execute(['--case-file' => $this->writeCaseFile(['goal' => 'Explore the space.', 'openingPrompt' => ' '])]));
        self::assertStringContainsString('lacks: openingPrompt', $commandTester->getDisplay());
        self::assertNull($this->judgedRunServiceDouble->receivedSimCase);
    }

    public function testTheDefaultCaseFileServesWhenTheOptionIsNotGivenAndTheSpaceIsRequired(): void
    {
        $probeCommand = $this->buildProbeCommand();
        $probeCommand->defaultCaseFile = $this->writeCaseFile(['goal' => 'Explore the space.', 'openingPrompt' => 'Remember: my key term is X.']);

        $commandTester = new CommandTester($probeCommand);
        $exitCode = $commandTester->execute([]);

        self::assertSame(2, $exitCode, 'the default case was read; it names no space and the options name none');
        self::assertStringContainsString('--agentSpaceId is required (or a case file that names its agentSpaceId)', $commandTester->getDisplay());
        self::assertNull($this->judgedRunServiceDouble->receivedSimCase);
    }

    public function testTheCaseFileBecomesTheJudgedCaseAndTheTreesArePrintedUnderTheirHeadings(): void
    {
        $this->judgedRunServiceDouble->scriptedRunResult = $this->buildRunResult('PASS');
        $caseFilePath = $this->writeCaseFile([
            'id' => 'tree-case',
            'agentSpaceId' => AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID,
            'goal' => 'Explore the space over many topics.',
            'openingPrompt' => 'Remember: my key term is X.',
            'userFacts' => 'Ask for the key term late.',
            'successRubric' => 'PASS only when the tree grows.',
            'compactionParamsTemplate' => 'leaf={{segmentMessages}} keep={{keepRecentMessages}} K={{tierFanIn}} retain={{levelRetention}} trigger={{triggerTokens}} turns={{maxTurns}} depth=log_{{tierFanIn}}',
        ]);

        $commandTester = new CommandTester($this->buildProbeCommand());
        $exitCode = $commandTester->execute(['--case-file' => $caseFilePath, '--max-turns' => '5', '--fan-in' => '4', '--persist' => false]);
        $display = $commandTester->getDisplay();

        self::assertSame(0, $exitCode);
        self::assertSame([[AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID, null]], $this->judgedRunServiceDouble->resolvedScopeSpaceIds);
        self::assertSame(
            [
                'goal' => 'Explore the space over many topics.',
                'openingPrompt' => 'Remember: my key term is X.',
                'userFacts' => 'Ask for the key term late.',
                'successRubric' => 'PASS only when the tree grows.',
                'compactionParamsText' => 'leaf=3 keep=3 K=4 retain=1 trigger=200 turns=5 depth=log_4',
                'maxTurns' => 5,
                'compactionSettings' => ['segmentMessages' => 3, 'keepRecentMessages' => 3, 'tierFanIn' => 4, 'levelRetention' => 1, 'triggerTokens' => 200],
            ],
            $this->judgedRunServiceDouble->receivedSimCase?->toArray()
        );
        self::assertFalse($this->judgedRunServiceDouble->receivedRunOptions?->persistConversations);
        self::assertNull($this->judgedRunServiceDouble->receivedRunOptions?->aiModelName, 'the agent runs on the conversation default');
        self::assertNotNull($this->judgedRunServiceDouble->receivedRunOptions?->progressReporter);
        self::assertStringContainsString('Compaction-tree judge eval — leaf=3 keep=3 K=4 retain=1 trigger=200 turns=5 depth=log_4', $display);
        self::assertStringContainsString("\n── after turn 1 ──\ntree after one\n\n── after turn 2 ──\ntree after two\n\nFINAL TREE\nthe final tree\n\nVERDICT (2 turns, stop=DONE)\n{\"verdict\":\"PASS\",\"reasons\":\"grew\"}\n", $display);
    }

    public function testTheJsonDocumentCarriesTheVerdictAndTheTreesAndAFailedVerdictFails(): void
    {
        $this->judgedRunServiceDouble->scriptedRunResult = $this->buildRunResult('FAIL');
        $commandTester = new CommandTester($this->buildProbeCommand());

        $exitCode = $commandTester->execute([
            '--case-file' => $this->writeCaseFile(['goal' => 'Explore.', 'openingPrompt' => 'Hello.']),
            '--agentSpaceId' => (string)AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID,
            '--json' => true,
        ]);
        $display = $commandTester->getDisplay();

        self::assertSame(1, $exitCode);
        self::assertSame([[null, AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID]], $this->judgedRunServiceDouble->resolvedScopeSpaceIds, 'the space of the options');
        $jsonDocument = json_decode(substr($display, (int)strpos($display, "{\n")), true);
        self::assertSame(['verdict', 'turns', 'stopReason', 'finalTree', 'treeSnapshots'], array_keys($jsonDocument));
        self::assertSame(['verdict' => 'FAIL', 'reasons' => 'grew'], $jsonDocument['verdict']);
        self::assertSame(['1' => 'tree after one', '2' => 'tree after two'], $jsonDocument['treeSnapshots']);
        self::assertStringContainsString('segmentMessages=3 (one level-0 leaf per 3 messages)', $this->judgedRunServiceDouble->receivedSimCase?->getCompactionParamsText(), 'the default template');
    }

    public function testTheCaseFilesSpaceWinsOverTheSpaceOfTheScopeOptions(): void
    {
        $this->judgedRunServiceDouble->scriptedRunResult = $this->buildRunResult('PASS');
        $commandTester = new CommandTester($this->buildProbeCommand());

        $exitCode = $commandTester->execute([
            '--case-file' => $this->writeCaseFile(['goal' => 'Explore.', 'openingPrompt' => 'Hello.', 'agentSpaceId' => 3]),
            '--agentSpaceId' => (string)AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID,
        ]);

        self::assertSame(0, $exitCode);
        self::assertSame([[3, AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID]], $this->judgedRunServiceDouble->resolvedScopeSpaceIds, 'the case file\'s space first, the options\' space as the fallback');
        self::assertSame(3, $this->judgedRunServiceDouble->receivedScope?->getAgentSpaceId(), 'the run works in the case file\'s space');
    }

    public function testAFailingRunEndsWithItsReason(): void
    {
        $commandTester = new CommandTester($this->buildProbeCommand());

        $exitCode = $commandTester->execute([
            '--case-file' => $this->writeCaseFile(['goal' => 'Explore.', 'openingPrompt' => 'Hello.', 'agentSpaceId' => 3]),
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('eval failed: ' . JudgedRunServiceWithScriptedTreeRunDouble::RUN_FAILURE_MESSAGE, $commandTester->getDisplay());
    }

    protected function buildProbeCommand(): AgentEvalCompactionTreeProbeCommand
    {
        return new AgentEvalCompactionTreeProbeCommand($this->judgedRunServiceDouble, new AgentSpacesServiceConsoleScopeDouble());
    }

    protected function buildRunResult(string $verdict): AgentEvalCompactionTreeRunResult
    {
        return AgentEvalCompactionTreeRunResult::forTreeRun(
            AgentEvalJudgeVerdict::fromArray(['verdict' => $verdict, 'reasons' => 'grew']),
            'DONE',
            2,
            [1 => 'tree after one', 2 => 'tree after two'],
            'the final tree',
            AIConversation::newInstance()
        );
    }

    /** @param array<string, mixed> $caseFields */
    protected function writeCaseFile(array $caseFields): string
    {
        $caseFilePath = (string)tempnam(sys_get_temp_dir(), 'compaction-tree-case');
        $this->writtenFilePaths[] = $caseFilePath;
        file_put_contents($caseFilePath, (string)json_encode($caseFields));
        return $caseFilePath;
    }
}
