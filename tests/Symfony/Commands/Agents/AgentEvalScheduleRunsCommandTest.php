<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SendOwnerReportTool;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Reports\AIConversationOwnerReport;
use DDD\Domain\AI\Entities\Evals\AgentEvalScheduleRunStructure;
use DDD\Domain\AI\Entities\Evals\AgentEvalScheduleRunStructures;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerReportDeliveryService;
use DDD\Tests\Agents\Domain\AI\Entities\Evals\AgentEvalEvictionTurnsTest;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;
use ReflectionMethod;

/**
 * The schedule-runs command up to the firing: the refusal of a run without a scope or a task, the refusal of an owner
 * that is no evaluation account, the application's step instead of firing, and the firing with the task; and the
 * verdict over the report structures of the runs.
 *
 * The expected verdict lines are the ones the command has always printed for these structures: they are read from
 * `Fixtures/EvalProbes/expected-outputs.json` (key `scheduleVerdicts`), which holds the outputs of the command's former
 * code on the same input. The refusal lines keep that code's format with the module's words for the scope and the
 * source of the evaluation accounts.
 */
class AgentEvalScheduleRunsCommandTest extends IntegrationTestCase
{
    public function testTheVerdictIsTheVerdictTheRunsHaveAlwaysGot(): void
    {
        $structuresByCase = [
            'oneRun' => [1 => ['heading: A']],
            'twoOfThreeKept' => [1 => ['heading: A', 'widget: chart'], 2 => ['heading: A', 'widget: chart'], 3 => ['heading: A']],
            'emptyStructuresKept' => [1 => [], 2 => [], 3 => ['heading: X']],
            'noneKept' => [1 => ['heading: A'], 2 => ['heading: B']],
            'fourRuns' => [1 => ['a'], 2 => ['a'], 3 => ['a'], 4 => ['a']],
        ];
        $expectedVerdicts = AgentEvalEvictionTurnsTest::expectedOutputs()['scheduleVerdicts'];
        foreach ($structuresByCase as $caseName => $structuresByRunNumber) {
            $agentEvalScheduleRunStructures = AgentEvalScheduleRunStructures::newInstance();
            foreach ($structuresByRunNumber as $runNumber => $structureLines) {
                $agentEvalScheduleRunStructure = AgentEvalScheduleRunStructure::forRun($runNumber, $structureLines);
                $agentEvalScheduleRunStructures->add($agentEvalScheduleRunStructure);
            }
            self::assertSame($expectedVerdicts[$caseName], $agentEvalScheduleRunStructures->buildVerdictLine() . "\n", $caseName);
        }
        self::assertSame('Verdict: one run only — no structure to compare.', AgentEvalScheduleRunStructures::newInstance()->buildVerdictLine(), 'a run that never fired');
    }

    /**
     * The lines of one run are those the command has always printed (the statement of its former code, copied as the
     * expected format): `Run <n> · conversation <id> · <state>[ (TIMEOUT)] · read earlier reports: yes|no · outcome
     * <outcome of the report tool> · tools: <every tool called>`, then `  subject: <subject>` (or the note that the
     * model wrote no report) and one indented line per element of the report's structure.
     */
    public function testEveryRunPrintsItsStateToolsSubjectAndStructure(): void
    {
        $messagesServiceDouble = new ScheduleRunMessagesServiceDouble();
        $messagesServiceDouble->newestMessages = new AIConversationMessages();
        foreach ([['getPreviousTaskRunReports', []], ['listItems', []], [SendOwnerReportTool::NAME, ['outcome' => 'DONE']]] as [$toolName, $toolArguments]) {
            $toolCall = new ToolCall();
            $toolCall->name = $toolName;
            $toolCall->arguments = $toolArguments;
            $toolCallMessage = new AIConversationMessage();
            $toolCallMessage->toolCall = $toolCall;
            $messagesServiceDouble->newestMessages->add($toolCallMessage);
        }
        $textMessage = new AIConversationMessage();
        $textMessage->toolCall = null;
        $messagesServiceDouble->newestMessages->add($textMessage);
        $ownerReportDeliveryServiceDouble = new ScheduleRunOwnerReportDeliveryServiceDouble();
        $ownerReport = new AIConversationOwnerReport();
        $ownerReport->subject = 'Your weekly report';
        $ownerReport->bodyMarkdown = "# Summary\ntext\n## Next steps\n";
        $ownerReportDeliveryServiceDouble->ownerReport = $ownerReport;
        $this->replaceService(AIConversationMessagesService::class, $messagesServiceDouble);
        $this->replaceService(AIConversationOwnerReportDeliveryService::class, $ownerReportDeliveryServiceDouble);
        $runConversation = AIConversation::newInstance();
        $runConversation->id = 4711;
        $runConversation->runState = 'IDLE';
        $printRun = new ReflectionMethod(AgentEvalScheduleRunsCommandWithScriptedScope::class, 'printRun');
        $bufferedOutput = new BufferedOutput();
        $symfonyStyle = new SymfonyStyle(new ArrayInput([]), $bufferedOutput);

        $structureLines = $printRun->invoke(new AgentEvalScheduleRunsCommandWithScriptedScope(), $symfonyStyle, 2, $runConversation, true);
        $ownerReportDeliveryServiceDouble->ownerReport = null;
        $printRun->invoke(new AgentEvalScheduleRunsCommandWithScriptedScope(), $symfonyStyle, 3, $runConversation, false);

        self::assertSame(
            "Run 2 · conversation 4711 · IDLE · read earlier reports: yes · outcome DONE · tools: getPreviousTaskRunReports, listItems, send_owner_report\n"
            . "  subject: Your weekly report\n"
            . "  heading: Summary\n"
            . "  heading: Next steps\n"
            . "Run 3 · conversation 4711 · IDLE (TIMEOUT) · read earlier reports: yes · outcome DONE · tools: getPreviousTaskRunReports, listItems, send_owner_report\n"
            . "  subject: (no model-written report)\n",
            $bufferedOutput->fetch()
        );
        self::assertSame(['heading: Summary', 'heading: Next steps'], $structureLines);
        self::assertSame([300, 300], $messagesServiceDouble->readLimits, 'the newest 300 messages of the run are read');
    }

    public function testARunWithoutScopeOrTaskIsRefused(): void
    {
        $agentEvalScheduleRunsCommand = new AgentEvalScheduleRunsCommandWithScriptedScope();
        $commandTester = new CommandTester($agentEvalScheduleRunsCommand);

        self::assertSame(Command::FAILURE, $commandTester->execute(['--task' => 'Weekly report']));
        self::assertStringContainsString('[ERROR] A scope option (--agentSpaceId) and --task are required.', $commandTester->getDisplay());
        self::assertSame(Command::FAILURE, $commandTester->execute(['--agentSpaceId' => '9']));
        self::assertSame([], $agentEvalScheduleRunsCommand->firedTasks);
    }

    public function testAnOwnerThatIsNoEvalAccountIsRefused(): void
    {
        $agentEvalScheduleRunsCommand = new AgentEvalScheduleRunsCommandWithScriptedScope();
        $agentEvalScheduleRunsCommand->ownerIsAnEvalAccount = false;
        $commandTester = new CommandTester($agentEvalScheduleRunsCommand);

        self::assertSame(Command::FAILURE, $commandTester->execute(['--agentSpaceId' => '9', '--task' => 'Weekly report']));
        self::assertStringContainsString(
            '[ERROR] Agent space 9 belongs to account 5, which is no configured eval account (the evaluation accounts service).',
            (string)preg_replace('/\s+/', ' ', $commandTester->getDisplay()),
            'the error block wraps the line'
        );
        self::assertSame([], $agentEvalScheduleRunsCommand->firedTasks);
    }

    public function testTheApplicationsStepInsteadOfFiringEndsTheRunAndOtherwiseTheTaskIsFired(): void
    {
        $agentEvalScheduleRunsCommand = new AgentEvalScheduleRunsCommandWithScriptedScope();
        $agentEvalScheduleRunsCommand->exitCodeInsteadOfFiring = Command::INVALID;
        self::assertSame(Command::INVALID, (new CommandTester($agentEvalScheduleRunsCommand))->execute(['--agentSpaceId' => '9', '--task' => 'Weekly report']));
        self::assertSame([], $agentEvalScheduleRunsCommand->firedTasks);

        $agentEvalScheduleRunsCommand = new AgentEvalScheduleRunsCommandWithScriptedScope();
        self::assertSame(Command::SUCCESS, (new CommandTester($agentEvalScheduleRunsCommand))->execute(['--agentSpaceId' => '9', '--task' => '  Weekly report  ']));
        self::assertSame(['Weekly report'], $agentEvalScheduleRunsCommand->firedTasks);
    }
}
