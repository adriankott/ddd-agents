<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\RunReport;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;

/**
 * The runs of the printout fixture of `app:ai-agent:run-report`: their message rows as the metrics read returns them,
 * the plan snapshots their messages stamped, the decision model's cost a usage log books for them, the evaluation logs
 * that state their verdicts, and the option sets the printout was taken with.
 *
 * Run {@see self::FULL_RUN_ID} has tool calls (refused, repeated, parallel, a delegate, a call over a thousand seconds),
 * plans and a decision cost; {@see self::VARIANTS_RUN_ID} has variants and a passing verdict; {@see self::EMPTY_RUN_ID}
 * is stored without rows; {@see self::MISSING_RUN_ID} does not exist.
 *
 * The fixture file holds the stored printout of these runs; the test prints the same runs through the module's
 * command and compares.
 */
class RunReportPrintoutRuns
{
    public const int FULL_RUN_ID = 4711;
    public const int VARIANTS_RUN_ID = 4712;
    public const int EMPTY_RUN_ID = 4713;
    public const int MISSING_RUN_ID = 4714;

    /** @var string The fixture file: the printout per option set */
    public const string FIXTURE_FILE = __DIR__ . '/Fixtures/run-report-printouts.json';

    /** @var float The decision model's cost of the full run */
    public const float FULL_RUN_DECISION_COST_IN_USD = 0.01234;

    /** @var int The decision model's calls of the full run */
    public const int FULL_RUN_DECISION_CALLS = 3;

    /** @var int The id the usage log files the full run under */
    public const int FULL_RUN_USAGE_LOG_SCOPE_ID = 9001;

    /** @return int[] the runs every option set names, in order */
    public static function getConversationIds(): array
    {
        return [self::FULL_RUN_ID, self::VARIANTS_RUN_ID, self::EMPTY_RUN_ID, self::MISSING_RUN_ID];
    }

    /**
     * The options of every printout, by name; `--log-dir` is filled with the folder of {@see writeEvaluationLogs()}.
     *
     * @return array<string, array<string, bool>>
     */
    public static function getOptionSets(): array
    {
        return [
            'logs' => ['--log-dir' => true],
            'logsMarkdownFindingsVariants' => ['--log-dir' => true, '--markdown' => true, '--findings' => true, '--variants' => true],
            'noToolsVariants' => ['--no-tools' => true, '--variants' => true],
        ];
    }

    /**
     * The input of a command run of one option set.
     *
     * @param array<string, bool> $optionSet
     * @param string $logDirectory
     * @return array<string, mixed>
     */
    public static function buildCommandInput(array $optionSet, string $logDirectory): array
    {
        $commandInput = ['conversationIds' => array_map('strval', self::getConversationIds())];
        foreach ($optionSet as $optionName => $optionValue) {
            $commandInput[$optionName] = $optionName === '--log-dir' ? $logDirectory : $optionValue;
        }
        return $commandInput;
    }

    /**
     * The message rows of the stored runs, by conversation, WITHOUT the plan snapshots.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public static function getMessageRowsByConversationId(): array
    {
        return [
            self::FULL_RUN_ID => [
                self::row(1, 'USER', 'TEXT', '2026-10-04 10:00:00'),
                self::row(2, 'ASSISTANT', 'TOOL_CALL', '2026-10-04 10:00:05', tool: 'readSummary', dur: 2.0, startedAt: '2026-10-04 10:00:03.000', args: '{"a":1}', snap: 1, egress: 2500),
                self::row(3, 'ASSISTANT', 'TOOL_CALL', '2026-10-04 10:00:05', tool: 'searchTheWeb', dur: 3.0, startedAt: '2026-10-04 10:00:03.500', args: '{"q":"x"}'),
                self::row(4, 'ASSISTANT', 'TOOL_CALL', '2026-10-04 10:00:09', tool: 'readSummary', dur: 0.0, startedAt: null, args: '{"a":1}', head: 'You already called `readSummary` with these'),
                self::row(5, 'ASSISTANT', 'TOOL_CALL', '2026-10-04 10:00:12', tool: 'updateNotes', dur: 2.0, startedAt: '2026-10-04 10:00:10.000', args: '{"n":"x"}', refusedFlag: 1, head: 'Execution failed for Tool'),
                self::row(6, 'ASSISTANT', 'TOOL_CALL', '2026-10-04 10:00:13', tool: 'delegate', dur: 0.5, startedAt: '2026-10-04 10:00:12.500', args: '{"t":"x"}'),
                self::row(7, 'ASSISTANT', 'TOOL_CALL', '2026-10-04 10:00:14', tool: 'set_task_status', dur: 0.0, startedAt: null, args: '{"u":1}', snap: 1, egress: 1500),
                self::row(8, 'ASSISTANT', 'TOOL_CALL', '2026-10-04 10:00:15', tool: 'load_skills', dur: 0.2, startedAt: '2026-10-04 10:00:14.800', args: '{"s":1}', snap: 1),
                self::row(9, 'ASSISTANT', 'TEXT', '2026-10-04 10:00:20'),
                self::row(10, 'USER', 'TEXT', '2026-10-04 10:00:35'),
                self::row(11, 'ASSISTANT', 'TOOL_CALL', '2026-10-04 10:26:00', tool: 'exportArchive', dur: 1530.25, startedAt: '2026-10-04 10:00:29.750', args: '{"all":1}', snap: 1, egress: 3000),
                self::row(12, 'ASSISTANT', 'TEXT', '2026-10-04 10:26:10', snap: 1, egress: 2000),
                // a scheduled posting an hour later is not the run's work
                self::row(13, 'ASSISTANT', 'TEXT', '2026-10-04 11:30:00'),
            ],
            self::VARIANTS_RUN_ID => [
                self::row(1, 'USER', 'TEXT', '2026-10-04 10:00:00'),
                self::row(2, 'ASSISTANT', 'TOOL_CALL', '2026-10-04 10:00:05', tool: 'importRecord', snap: 1, egress: 900,
                    variants: '{"visitClass":"unknown","identityPath":"import(owner@1)","locality":"local(purpose)","modality":"chat(purpose)"}',
                    skillSections: '["Flow.Identify[identityPath=import]","Flow.Profile[visitClass=unknown|hybrid]"]'),
                self::row(3, 'ASSISTANT', 'TOOL_CALL', '2026-10-04 10:00:09', tool: 'readSummary', dur: 1.5, startedAt: '2026-10-04 10:00:07.500', snap: 1, egress: 1100,
                    variants: '{"visitClass":"inPerson(proposed,record@2)","identityPath":"import(owner@2)","locality":"local(purpose)","modality":"chat(purpose)"}',
                    skillSections: '["Flow.Identify[identityPath=import]","Flow.Profile[visitClass=inPerson]"]'),
                self::row(4, 'ASSISTANT', 'TEXT', '2026-10-04 10:00:12'),
                self::row(5, 'USER', 'TEXT', '2026-10-04 10:00:20'),
                self::row(6, 'ASSISTANT', 'TOOL_CALL', '2026-10-04 10:00:25', tool: 'updateReach', dur: 0.75, startedAt: '2026-10-04 10:00:24.250', snap: 1,
                    variants: '{"visitClass":"inPerson(owner@5)","identityPath":"import(owner@5)","locality":"local(purpose)","modality":"chat(purpose)"}',
                    skillSections: '["Flow.Profile[visitClass=inPerson]","Flow.Reach[visitClass=inPerson]"]'),
                self::row(7, 'ASSISTANT', 'TEXT', '2026-10-04 10:00:30', snap: 1,
                    variants: '{"visitClass":"inPerson(owner@5)","identityPath":"import(owner@5)","locality":"regional(owner@7)","modality":"chat(purpose)"}',
                    skillSections: '["Flow.Profile[visitClass=inPerson]"]'),
            ],
            self::EMPTY_RUN_ID => [],
        ];
    }

    /**
     * The plan snapshots the stored messages stamped, by conversation and message index, as the JSON column decodes.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public static function getTaskPlanSnapshotsByConversationId(): array
    {
        return [
            self::FULL_RUN_ID => [
                2 => self::encodeAndDecode(self::smallPlan()),
                7 => self::encodeAndDecode(self::largePlan()),
            ],
        ];
    }

    /**
     * The rows of a run with each row's plan snapshot under `taskPlanSnapshot` as the JSON text of the column (null when
     * the row stamped none) — one of the two forms the snapshot read accepts.
     *
     * @param int $conversationId
     * @return array<int, array<string, mixed>>
     */
    public static function getMessageRowsWithSnapshotTexts(int $conversationId): array
    {
        $snapshotsByIndex = self::getTaskPlanSnapshotsByConversationId()[$conversationId] ?? [];
        $rows = self::getMessageRowsByConversationId()[$conversationId] ?? [];
        foreach ($rows as $rowPosition => $row) {
            $rows[$rowPosition]['taskPlanSnapshot'] = isset($snapshotsByIndex[$row['i']]) ? (string)json_encode($snapshotsByIndex[$row['i']]) : null;
        }
        return $rows;
    }

    /**
     * Writes the evaluation logs: one for each reported run, one naming a run that is not asked for, one without a
     * verdict line.
     *
     * @param string $logDirectory an existing, empty folder
     * @return void
     */
    public static function writeEvaluationLogs(string $logDirectory): void
    {
        file_put_contents(
            $logDirectory . '/v7-full.log',
            "  · purposed conversation started (id " . self::FULL_RUN_ID . ") — agent greeted\n  · driver loop ended — judging\n"
            . "  ✘ FAIL 63% notes.reply.short — 6t/DONE — replies=5\n      VIOLATED (3) REACH — the owner said in person | twice\n      PARTIAL (6) FORM — two questions\n"
        );
        file_put_contents($logDirectory . '/v7-variants.log', 'conversation started (id ' . self::VARIANTS_RUN_ID . ")\n  ✔ PASS 92% notes.multi — 7t/DONE\n");
        file_put_contents($logDirectory . '/v7-other.log', "conversation started (id 4799)\n  ✔ PASS 100% notes.other — 2t/DONE\n");
        file_put_contents($logDirectory . '/v7-unjudged.log', 'conversation started (id ' . self::EMPTY_RUN_ID . ")\n  · driver loop ended\n");
    }

    /** Seven nodes over two levels. */
    public static function smallPlan(): TaskPlan
    {
        return self::plan([
            ['text' => 'Collect the open questions', 'status' => 'open'],
            ['text' => 'Draft the answers', 'status' => 'open', 'subtasks' => [['text' => 'The short ones', 'status' => 'open'], ['text' => 'The long ones', 'status' => 'open']]],
            ['text' => 'Send the answers', 'status' => 'open', 'subtasks' => [['text' => 'By mail', 'status' => 'open'], ['text' => 'By message', 'status' => 'open']]],
        ]);
    }

    /** Fifteen nodes over three levels. */
    public static function largePlan(): TaskPlan
    {
        $leaves = [];
        for ($leafNumber = 1; $leafNumber <= 12; $leafNumber++) {
            $leaves[] = ['text' => 'Answer question ' . $leafNumber, 'status' => 'open'];
        }
        return self::plan([
            ['text' => 'Answer every open question', 'status' => 'open', 'subtasks' => [['text' => 'The urgent ones first', 'status' => 'open', 'subtasks' => $leaves]]],
            ['text' => 'Report to the owner', 'status' => 'open'],
        ]);
    }

    /** @param array<int, array<string, mixed>> $items */
    public static function plan(array $items): TaskPlan
    {
        $taskPlan = new TaskPlan();
        $taskPlan->mapFromRepository(['items' => $items]);
        $taskPlan->markUpdatedInTurn(0);
        return $taskPlan;
    }

    /**
     * A plan as its JSON column stores and decodes it.
     *
     * @return array<string, mixed>
     */
    public static function encodeAndDecode(TaskPlan $taskPlan): array
    {
        return (array)json_decode((string)json_encode($taskPlan->mapToRepository()), true);
    }

    /**
     * One message row as the metrics read returns it.
     *
     * @return array<string, mixed>
     */
    public static function row(
        int $index,
        string $role,
        string $type,
        string $created,
        ?string $tool = null,
        float $dur = 0.0,
        ?string $startedAt = null,
        string $args = '',
        int $snap = 0,
        int $egress = 0,
        int $refusedFlag = 0,
        string $head = '',
        ?string $variants = null,
        ?string $skillSections = null
    ): array {
        return [
            'i' => $index, 'role' => $role, 't' => $type, 'author' => $role === 'USER' ? 'USER' : 'AGENT', 'c' => $created, 'u' => $created,
            'snap' => $snap, 'dur' => $dur, 'startedAt' => $startedAt, 'egress' => $egress, 'cost' => $snap ? 0.0137 : 0.0, 'tool' => $tool,
            'args' => $args, 'refusedFlag' => $refusedFlag, 'resultHead' => $head, 'variants' => $variants, 'skillSections' => $skillSections,
        ];
    }
}
