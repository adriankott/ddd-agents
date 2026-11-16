<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\RunReport;

use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\Metrics\AIConversationMetricsService;
use DDD\Domain\AI\Services\Conversations\RunReports\AIConversationRunReportService;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Symfony\Commands\Agents\AgentConsoleCommand;
use DDD\Symfony\Commands\Agents\AgentRunReportCommand;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The printout of `app:ai-agent:run-report` over the runs of {@see RunReportPrintoutRuns} keeps its stored format,
 * character for character (the fixture file): the run table with the verdicts and the totals, the slowest tools, the
 * variants timelines and the findings, in console and Markdown form. An application's command that redeclares the
 * run table's headers prints its own headers. The services are the container's, replaced by doubles that read nothing; the decision model's cost comes
 * through the report service's two usage-log override points.
 */
class AgentRunReportCommandPrintoutTest extends IntegrationTestCase
{
    protected RunReportServiceWithScriptedReadsDouble $runReportServiceDouble;

    protected RunReportConversationsServiceDouble $conversationsServiceDouble;

    protected string $logDirectory = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->runReportServiceDouble = new RunReportServiceWithScriptedReadsDouble();
        $this->conversationsServiceDouble = new RunReportConversationsServiceDouble();
        $this->replaceService(AIConversationRunReportService::class, $this->runReportServiceDouble);
        $this->replaceService(AIConversationsService::class, $this->conversationsServiceDouble);
        $this->replaceService(AIConversationMetricsService::class, new RunReportMetricsServiceDouble());
        StaticRegistry::$entityServices = [];
        $this->logDirectory = sys_get_temp_dir() . '/run-report-logs-' . uniqid();
        mkdir($this->logDirectory);
        RunReportPrintoutRuns::writeEvaluationLogs($this->logDirectory);
    }

    protected function onTearDown(): void
    {
        foreach (glob($this->logDirectory . '/*.log') ?: [] as $logPath) {
            unlink($logPath);
        }
        if (is_dir($this->logDirectory)) {
            rmdir($this->logDirectory);
        }
        StaticRegistry::$entityServices = [];
    }

    public function testTheCommandKeepsItsNameAndOptionsAndRunsOnTheBase(): void
    {
        $asCommandAttributes = (new ReflectionClass(AgentRunReportCommand::class))->getAttributes(AsCommand::class);
        self::assertSame('app:ai-agent:run-report', $asCommandAttributes[0]->newInstance()->name);

        $agentRunReportCommand = new AgentRunReportCommand();
        self::assertInstanceOf(AgentConsoleCommand::class, $agentRunReportCommand);
        self::assertTrue($agentRunReportCommand->getDefinition()->hasArgument('conversationIds'));
        foreach (['log-dir', 'markdown', 'findings', 'no-tools', 'variants'] as $optionName) {
            self::assertTrue($agentRunReportCommand->getDefinition()->hasOption($optionName), $optionName);
        }
    }

    public function testEveryPrintoutKeepsTheStoredFormat(): void
    {
        $fixturePrintouts = json_decode((string)file_get_contents(RunReportPrintoutRuns::FIXTURE_FILE), true);
        self::assertSame(array_keys(RunReportPrintoutRuns::getOptionSets()), array_keys($fixturePrintouts));

        foreach (RunReportPrintoutRuns::getOptionSets() as $optionSetName => $optionSet) {
            $commandTester = new CommandTester(new AgentRunReportProbeCommand());

            $exitCode = $commandTester->execute(RunReportPrintoutRuns::buildCommandInput($optionSet, $this->logDirectory));

            self::assertSame(0, $exitCode, $optionSetName);
            self::assertSame($fixturePrintouts[$optionSetName], $commandTester->getDisplay(), $optionSetName);
        }
    }

    public function testTheDecisionCostColumnIsNamedNeutrallyAndAnApplicationsCommandPrintsItsOwnHeaders(): void
    {
        self::assertSame('decision $ (calls)', AgentRunReportCommand::RUN_TABLE_HEADERS[19]);
        $commandTester = new CommandTester(new AgentRunReportProbeCommandWithOwnHeaders());

        $commandTester->execute(RunReportPrintoutRuns::buildCommandInput(['--no-tools' => true, '--markdown' => true], $this->logDirectory));

        $display = $commandTester->getDisplay();
        self::assertStringContainsString('| model $ | ' . AgentRunReportProbeCommandWithOwnHeaders::DECISION_COST_HEADER . ' | variants (final) |', $display);
        self::assertStringNotContainsString('decision $ (calls)', $display);
    }

    public function testAFailedSnapshotReadIsNamedBelowTheRunTableOnlyForTheRunItConcerns(): void
    {
        $this->runReportServiceDouble->snapshotReadFails = true;
        $commandTester = new CommandTester(new AgentRunReportProbeCommand());

        $commandTester->execute(RunReportPrintoutRuns::buildCommandInput(['--no-tools' => true], $this->logDirectory));

        $displayLines = explode("\n", $commandTester->getDisplay());
        $noteLines = array_values(array_filter($displayLines, static fn (string $displayLine): bool => str_contains($displayLine, 'plan size not measured')));
        self::assertSame([
            RunReportPrintoutRuns::FULL_RUN_ID . ': plan size not measured: ' . RuntimeException::class,
            RunReportPrintoutRuns::VARIANTS_RUN_ID . ': plan size not measured: ' . RuntimeException::class,
        ], $noteLines, 'one line per reported run; the runs without a report have none');
        $firstNoteLine = (int)array_search($noteLines[0], $displayLines, true);
        self::assertSame('', $displayLines[$firstNoteLine - 1]);
        self::assertStringStartsWith(' -', $displayLines[$firstNoteLine - 2], 'the notes follow the run table');
    }

    public function testTheDecisionCostIsReadOncePerReportedRunThatTheUsageLogFiles(): void
    {
        $commandTester = new CommandTester(new AgentRunReportProbeCommand());

        $commandTester->execute(RunReportPrintoutRuns::buildCommandInput(['--no-tools' => true], $this->logDirectory));

        self::assertSame(
            [[
                'usageScope' => AgentAIUsageScope::AI_AGENT_DECIDE_TURN,
                'usageLogScopeId' => RunReportPrintoutRuns::FULL_RUN_USAGE_LOG_SCOPE_ID,
                'from' => '2026-10-04 10:00:00',
                'to' => date('Y-m-d H:i:s', (int)strtotime('2026-10-04 11:30:00') + 120),
            ]],
            $this->runReportServiceDouble->usageLogReads,
            'the variants run has no usage-log id; the empty and the missing run have no report'
        );
        self::assertSame(RunReportPrintoutRuns::getConversationIds(), $this->conversationsServiceDouble->lookedUpConversationIds);
    }

    public function testTheApplicationsPrefixOfSkillSectionIdsIsDroppedInTheTimeline(): void
    {
        $this->runReportServiceDouble->skillSectionIdPrefixToDrop = 'Flow.';
        $commandTester = new CommandTester(new AgentRunReportProbeCommand());

        $commandTester->execute(['conversationIds' => [(string)RunReportPrintoutRuns::VARIANTS_RUN_ID], '--variants' => true, '--no-tools' => true, '--markdown' => true]);

        $display = $commandTester->getDisplay();
        self::assertStringContainsString('| 4712 | 3 | visitClass | unknown | inPerson(proposed,record@2) | Identify[identityPath=import], Profile[visitClass=inPerson] | updateReach |', $display);
        self::assertStringNotContainsString('Flow.', $display);
    }
}
