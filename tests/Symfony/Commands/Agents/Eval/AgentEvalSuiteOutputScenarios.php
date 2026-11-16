<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\Eval;

use DDD\Domain\AI\Entities\Evals\AgentEvalBaselineComparison;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRow;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRows;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunSummary;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteDecisionLayerSettings;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuitePlanSummary;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteRecord;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteRecordRun;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteRecordRuns;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteRecordSettings;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteSchedule;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTask;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTasks;
use DDD\Symfony\Commands\Agents\Eval\AgentEvalSuiteRunningCase;
use DDD\Symfony\Commands\Agents\Eval\AgentEvalSuiteRunState;
use Symfony\Component\Process\Process;

/**
 * The inputs of the output tests of the suite command: a plan with many lanes and waves under a transient run, a plan
 * with a fixed reasoning effort and no lanes, the record of two runs (one compared with a baseline) built from
 * `Fixtures/SuiteReport/report-input.json`, and finished cases of every kind. The expected texts beside them were
 * printed by the application's former command from these inputs as documents.
 */
class AgentEvalSuiteOutputScenarios
{
    public const string FIXTURE_DIRECTORY = __DIR__ . '/Fixtures/SuiteReport';

    /** The plan of a transient run on two models: nine write lanes (eight printed), waves, both flavors. */
    public static function buildPlanWithLanesAndWaves(): AgentEvalSuitePlanSummary
    {
        $agentEvalSuitePlanSummary = AgentEvalSuitePlanSummary::newInstance();
        $agentEvalSuitePlanSummary->tasks = 31;
        $agentEvalSuitePlanSummary->tasksBySuite = ['tools' => 22, 'selection' => 6, 'recall' => 3];
        $agentEvalSuitePlanSummary->tasksByAccess = ['read' => 17, 'write' => 12, 'exclusive' => 2];
        $agentEvalSuitePlanSummary->tasksByRun = ['m-one|call' => 15, 'm-two|call' => 10, 'm-one|judge' => 6];
        $agentEvalSuitePlanSummary->writingTasksByLane = [
            '7:posts' => 4, '7:catalog' => 2, '8:posts' => 1, '8:catalog' => 1, '9:posts' => 1, '9:*' => 1, '10:posts' => 1, '11:posts' => 1, '12:posts' => 1,
        ];
        $agentEvalSuitePlanSummary->wavesByWaveGroup = ['group:1' => 2, 'group:2' => 1];
        $agentEvalSuitePlanSummary->sequentialWallSeconds = 4000;
        $agentEvalSuitePlanSummary->estimatedWallSeconds = 754;
        $agentEvalSuitePlanSummary->boundBy = 'lane 7:posts';
        return $agentEvalSuitePlanSummary;
    }

    public static function buildTransientRunSettings(): AgentEvalSuiteRecordSettings
    {
        $agentEvalSuiteDecisionLayerSettings = AgentEvalSuiteDecisionLayerSettings::newInstance();
        $agentEvalSuiteDecisionLayerSettings->mode = 'on';
        $agentEvalSuiteDecisionLayerSettings->siteModes = ['skillPreload' => 'on', 'turnClosing' => 'off', 'replyLanguage' => 'on'];
        $agentEvalSuiteDecisionLayerSettings->turnClosingMode = 'deterministicCounter';
        $agentEvalSuiteRecordSettings = AgentEvalSuiteRecordSettings::forDecisionLayer($agentEvalSuiteDecisionLayerSettings);
        $agentEvalSuiteRecordSettings->modelNames = ['m-one', 'm-two'];
        $agentEvalSuiteRecordSettings->flavors = ['call', 'judge'];
        $agentEvalSuiteRecordSettings->suites = ['tools', 'selection', 'recall'];
        $agentEvalSuiteRecordSettings->arms = ['decision-model-skill-preload' => 'on', 'agent-wire-api' => 'responses'];
        $agentEvalSuiteRecordSettings->persistedConversations = false;
        $agentEvalSuiteRecordSettings->maxProcesses = 24;
        $agentEvalSuiteRecordSettings->isolateWholeScope = false;
        $agentEvalSuiteRecordSettings->configuredReasoningEffortByModel = ['m-one' => 'medium', 'm-two' => null];
        return $agentEvalSuiteRecordSettings;
    }

    /** The plan of one model without write lanes and without waves. */
    public static function buildPlanWithoutLanes(): AgentEvalSuitePlanSummary
    {
        $agentEvalSuitePlanSummary = AgentEvalSuitePlanSummary::newInstance();
        $agentEvalSuitePlanSummary->tasks = 3;
        $agentEvalSuitePlanSummary->tasksBySuite = ['tools' => 3];
        $agentEvalSuitePlanSummary->tasksByAccess = ['read' => 3];
        $agentEvalSuitePlanSummary->tasksByRun = ['m-one|call' => 3];
        $agentEvalSuitePlanSummary->sequentialWallSeconds = 105;
        $agentEvalSuitePlanSummary->estimatedWallSeconds = 35;
        $agentEvalSuitePlanSummary->boundBy = 'the read pool';
        return $agentEvalSuitePlanSummary;
    }

    /** A stored run with a fixed reasoning effort and no other arm. */
    public static function buildFixedEffortRunSettings(): AgentEvalSuiteRecordSettings
    {
        $agentEvalSuiteRecordSettings = static::buildTransientRunSettings();
        $agentEvalSuiteRecordSettings->modelNames = ['m-one'];
        $agentEvalSuiteRecordSettings->flavors = ['call'];
        $agentEvalSuiteRecordSettings->suites = ['tools'];
        $agentEvalSuiteRecordSettings->arms = ['reasoning-effort' => 'low'];
        $agentEvalSuiteRecordSettings->persistedConversations = true;
        $agentEvalSuiteRecordSettings->maxProcesses = 4;
        $agentEvalSuiteRecordSettings->configuredReasoningEffortByModel = ['m-one' => 'medium'];
        return $agentEvalSuiteRecordSettings;
    }

    /** The record of the two runs of the report input, the call run compared with its baseline rows (tolerance 15 %). */
    public static function buildRecordOfTwoRuns(): AgentEvalSuiteRecord
    {
        $reportInput = (array)json_decode((string)file_get_contents(static::FIXTURE_DIRECTORY . '/report-input.json'), true);
        $agentEvalSuiteRecordRuns = AgentEvalSuiteRecordRuns::newInstance();
        foreach ((array)$reportInput['runs'] as $runInput) {
            [$modelName, $flavor] = explode('|', (string)$runInput['runKey'], 2);
            $agentEvalCaseRows = AgentEvalCaseRows::fromRowArrays((array)$runInput['rows']);
            $agentEvalRunSummary = AgentEvalRunSummary::buildForSuite($agentEvalCaseRows, $modelName, $flavor, ['agent-wire-api' => 'responses']);
            $baselineRows = $reportInput['baselineRowsByRunKey'][$runInput['runKey']] ?? null;
            $agentEvalBaselineComparison = is_array($baselineRows)
                ? AgentEvalBaselineComparison::compareCaseRows($agentEvalCaseRows, AgentEvalCaseRows::fromRowArrays($baselineRows), 15.0)
                : null;
            $agentEvalSuiteRecordRun = AgentEvalSuiteRecordRun::forRun((string)$runInput['runKey'], $agentEvalRunSummary, $agentEvalBaselineComparison);
            $agentEvalSuiteRecordRuns->add($agentEvalSuiteRecordRun);
        }
        return AgentEvalSuiteRecord::forFinishedRun(
            1763251200,
            4321,
            false,
            static::buildFixedEffortRunSettings(),
            static::buildPlanWithLanesAndWaves(),
            null,
            null,
            $agentEvalSuiteRecordRuns
        );
    }

    /**
     * The rows of finished cases a suite prints a line for: a pass with its figures, a failure with four failures (three
     * printed, one longer than the line takes), an error without figures, a case that does not apply, a case id longer
     * than its column.
     *
     * @return AgentEvalCaseRow[]
     */
    public static function buildFinishedRows(): array
    {
        return [
            AgentEvalCaseRow::fromArray(['case' => 'catalog.pass', 'passed' => true, 'performance' => ['modelCalls' => 3, 'ownerTurns' => 1], 'processSeconds' => 12.4]),
            AgentEvalCaseRow::fromArray(['case' => 'catalog.fail', 'passed' => false, 'performance' => ['modelCalls' => 11, 'ownerTurns' => 4], 'processSeconds' => 99.5, 'failures' => [
                'tool not called: expected one of [getItems]',
                str_repeat('a long failure text — ', 12),
                'status: expected COMPLETED, got FAILED',
                'a fourth failure the line leaves out',
            ]]),
            AgentEvalCaseRow::fromArray(['case' => 'catalog.error', 'passed' => false, 'error' => 'the child process left no result (exit code 3): ' . str_repeat('x', 300), 'processSeconds' => 0.4]),
            AgentEvalCaseRow::fromArray(['case' => 'catalog.not-applicable', 'passed' => null, 'outcome' => 'notApplicable', 'notApplicableDetail' => 'the preparation step answered an error', 'performance' => ['modelCalls' => 0, 'ownerTurns' => 0], 'processSeconds' => 2.0]),
            AgentEvalCaseRow::fromArray(['case' => 'catalog.a-case-id-that-is-much-longer-than-its-column-of-forty-four', 'passed' => true, 'processSeconds' => 1234.6]),
        ];
    }

    /** The task of a finished row: suite `tools`, flavor `judge` for the second and third row, `call` otherwise. */
    public static function buildTaskOfRow(AgentEvalCaseRow $agentEvalCaseRow, int $rowIndex): AgentEvalSuiteTask
    {
        $agentEvalSuiteTask = AgentEvalSuiteTask::newInstance();
        $agentEvalSuiteTask->caseId = $agentEvalCaseRow->getCaseId();
        $agentEvalSuiteTask->suite = $rowIndex === 4 ? 'taskplandelivery' : 'tools';
        $agentEvalSuiteTask->flavor = in_array($rowIndex, [1, 2], true) ? 'judge' : 'call';
        $agentEvalSuiteTask->modelName = 'm-one';
        $agentEvalSuiteTask->taskId = 'm-one|' . $agentEvalSuiteTask->flavor . '|tools|' . $agentEvalSuiteTask->caseId;
        return $agentEvalSuiteTask;
    }

    /** A run of 40 tasks with 7 queued and 2 running, `$finishedCount` finished. */
    public static function buildRunStateWithFinishedCount(int $finishedCount): AgentEvalSuiteRunState
    {
        $queue = AgentEvalSuiteTasks::newInstance();
        for ($queuedIndex = 0; $queuedIndex < 7; $queuedIndex++) {
            $queuedTask = AgentEvalSuiteTask::newInstance();
            $queuedTask->taskId = 'queued-' . $queuedIndex;
            $queue->add($queuedTask);
        }
        $agentEvalSuiteRunState = AgentEvalSuiteRunState::forQueue($queue, AgentEvalSuiteSchedule::forProcesses(24), '/runs/suite', [], 45);
        $agentEvalSuiteRunState->totalTasks = 40;
        $agentEvalSuiteRunState->finishedCount = $finishedCount;
        for ($runningIndex = 0; $runningIndex < 2; $runningIndex++) {
            $runningTask = AgentEvalSuiteTask::newInstance();
            $runningTask->taskId = 'running-' . $runningIndex;
            $agentEvalSuiteRunningCase = AgentEvalSuiteRunningCase::forStartedProcess($runningTask, new Process(['php', '-v']), 1.0, '/runs/suite/.result');
            $agentEvalSuiteRunState->runningCases->add($agentEvalSuiteRunningCase);
        }
        return $agentEvalSuiteRunState;
    }
}
