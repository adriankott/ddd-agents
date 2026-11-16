<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Evals\AgentEvalBaselineChoice;
use DDD\Domain\AI\Entities\Evals\AgentEvalBaselineComparison;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseIdsByRunFolder;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunSummary;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteDecisionLayerSettings;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuitePlanSummary;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteRecord;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteRecordRun;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteRecordRuns;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteRecordSettings;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The record of a suite run: its runs with the cases to look at and the figures per suite, the two verdicts, the
 * counts over every run and the time against the case time — keys and order of the stored record.
 */
class AgentEvalSuiteRecordTest extends UnitTestCase
{
    use AgentEvalCaseRowsFixtureTrait;

    public function testARecordOfTwoRunsCarriesTheCountsTheVerdictsAndTheRunsInTheirStoredForm(): void
    {
        $callRows = $this->rows(
            ['suite' => 'tools', 'case' => 'a', 'processSeconds' => 30.0] + $this->measuredRow('a', true, 3, 1, [10.0], 30000, 0.005, 0, 28000, 20000),
            ['suite' => 'selection', 'case' => 's', 'passed' => null, 'outcome' => 'notApplicable', 'notApplicableDetail' => 'withheld', 'processSeconds' => 2.0],
            ['suite' => 'tools', 'case' => 'b', 'passed' => false, 'error' => 'timed out', 'restoreFailed' => true, 'processSeconds' => 60.0]
        );
        $judgeRows = $this->rows(['suite' => 'tools', 'case' => 'a', 'retriedAfter' => 'cURL error 28', 'processSeconds' => 8.0] + $this->measuredRow('a', true, 9, 1, [31.0], 95000, 0.016, 0, 90000, 60000));
        $baselineRows = $this->rows($this->measuredRow('a', true, 3, 1, [10.0], 30000, 0.005, 0, 28000, 20000));
        $runs = AgentEvalSuiteRecordRuns::newInstance();
        $callRun = AgentEvalSuiteRecordRun::forRun('VENDOR.MODEL|call', AgentEvalRunSummary::buildForSuite($callRows, 'VENDOR.MODEL', 'call'), null);
        $judgeRun = AgentEvalSuiteRecordRun::forRun(
            'VENDOR.MODEL|judge',
            AgentEvalRunSummary::buildForSuite($judgeRows, 'VENDOR.MODEL', 'judge'),
            AgentEvalBaselineComparison::compareCaseRows($judgeRows, $baselineRows)
        );
        $runs->add($callRun, $judgeRun);
        $baselineChoice = AgentEvalBaselineChoice::forPlannedCases(AgentEvalCaseIdsByRunFolder::newInstance());
        $baselineChoice->takeNamedRun('/runs/2000-01-01_100000-suite', AgentEvalCaseIdsByRunFolder::newInstance());

        $agentEvalSuiteRecord = AgentEvalSuiteRecord::forFinishedRun(1_700_000_000, 50, false, $this->buildSettings(['VENDOR.MODEL']), AgentEvalSuitePlanSummary::newInstance(), $baselineChoice, 'baseline: named', $runs);
        $record = $agentEvalSuiteRecord->toArray();

        self::assertSame(['startedAt', 'wallSeconds', 'processSeconds', 'speedUp', 'stopped', 'settings', 'plan', 'baseline', 'baselineChoice', 'verdict', 'total', 'passed', 'notApplicable', 'runs'], array_keys($record));
        self::assertSame(date('Y-m-d H:i:s', 1_700_000_000), $record['startedAt']);
        self::assertSame(100, $record['processSeconds']);
        self::assertSame(2.0, $record['speedUp']);
        self::assertSame('2000-01-01_100000-suite', $record['baseline']);
        self::assertSame(['correctness' => 'FAILED', 'performance' => 'REGRESSED'], $record['verdict']);
        self::assertSame(3, $record['total']);
        self::assertSame(2, $record['passed']);
        self::assertSame(1, $record['notApplicable']);
        self::assertFalse($agentEvalSuiteRecord->isSuccessful());
        self::assertSame(['VENDOR.MODEL|call', 'VENDOR.MODEL|judge'], array_keys($record['runs']));

        $callRecord = $record['runs']['VENDOR.MODEL|call'];
        self::assertSame(['model', 'flavor', 'total', 'passed', 'errors', 'notApplicable', 'notApplicableCases', 'restoreFailedCases', 'retriedCases', 'failedCases', 'bySuite', 'performance', 'costPerPassUsd', 'baselineComparison'], array_keys($callRecord));
        self::assertSame(['VENDOR.MODEL', 'call', 2, 1, 1, 1], [$callRecord['model'], $callRecord['flavor'], $callRecord['total'], $callRecord['passed'], $callRecord['errors'], $callRecord['notApplicable']]);
        self::assertSame(['selection:s — withheld'], $callRecord['notApplicableCases']);
        self::assertSame(['tools:b'], $callRecord['restoreFailedCases']);
        self::assertSame(['tools:b'], $callRecord['failedCases']);
        self::assertSame(['tools', 'selection'], array_keys($callRecord['bySuite']));
        self::assertSame(['total' => 2, 'notApplicable' => 0, 'passed' => 1], array_slice($callRecord['bySuite']['tools'], 0, 3));
        self::assertSame(1, $callRecord['bySuite']['tools']['performance']['measuredCases']);
        self::assertSame(['total' => 0, 'notApplicable' => 1, 'passed' => 0], array_slice($callRecord['bySuite']['selection'], 0, 3));
        self::assertNull($callRecord['baselineComparison']);
        self::assertSame(['tools:a'], $record['runs']['VENDOR.MODEL|judge']['retriedCases']);
        self::assertTrue($record['runs']['VENDOR.MODEL|judge']['baselineComparison']['comparable']);
    }

    public function testTheVerdictsOfAStoppedRunAndOfARunWithoutBaseline(): void
    {
        $runs = AgentEvalSuiteRecordRuns::newInstance();
        $passingRun = AgentEvalSuiteRecordRun::forRun('default|call', AgentEvalRunSummary::buildForSuite($this->rows(['case' => 'a', 'passed' => true]), 'default', 'call'), null);
        $runs->add($passingRun);

        $finished = AgentEvalSuiteRecord::forFinishedRun(1_700_000_000, 0, false, $this->buildSettings([]), AgentEvalSuitePlanSummary::newInstance(), null, null, $runs);
        self::assertSame(['correctness' => 'PASSED', 'performance' => 'NOT_COMPARED'], $finished->toArray()['verdict']);
        self::assertNull($finished->toArray()['speedUp']);
        self::assertNull($finished->toArray()['baseline']);
        self::assertTrue($finished->isSuccessful());

        $stopped = AgentEvalSuiteRecord::forFinishedRun(1_700_000_000, 10, true, $this->buildSettings([]), AgentEvalSuitePlanSummary::newInstance(), null, null, $runs);
        self::assertSame('FAILED', $stopped->getCorrectnessVerdict());
    }

    public function testTheSettingsKeepTheStoredKeysInTheirOrderAndAnApplicationNamesTheScopeKey(): void
    {
        $agentEvalSuiteRecordSettings = $this->buildSettings(['VENDOR.MODEL']);
        $agentEvalSuiteRecordSettings->flavors = ['call', 'judge'];
        $agentEvalSuiteRecordSettings->suites = ['tools'];
        $agentEvalSuiteRecordSettings->arms = ['effort' => 'low'];
        $agentEvalSuiteRecordSettings->maxProcesses = 8;
        $agentEvalSuiteRecordSettings->isolateWholeScope = true;
        $agentEvalSuiteRecordSettings->configuredReasoningEffortByModel = ['VENDOR.MODEL' => null];

        self::assertSame([
            'models' => ['VENDOR.MODEL'],
            'flavors' => ['call', 'judge'],
            'suites' => ['tools'],
            'arms' => ['effort' => 'low'],
            'persistedConversations' => true,
            'maxProcesses' => 8,
            'isolateWholeScope' => true,
            'configuredReasoningEffortByModel' => ['VENDOR.MODEL' => null],
            'platformDecisionLayer' => ['mode' => 'off', 'sites' => ['turnClose' => 'shadow'], 'turnClosingMode' => 'counter'],
        ], $agentEvalSuiteRecordSettings->toArray());

        $applicationSettings = AgentEvalSuiteRecordSettingsOfAnApplication::forDecisionLayer($agentEvalSuiteRecordSettings->decisionLayer);
        $applicationSettings->isolateWholeScope = true;
        self::assertSame(
            ['models', 'flavors', 'suites', 'arms', 'persistedConversations', 'maxProcesses', AgentEvalSuiteRecordSettingsOfAnApplication::ISOLATE_WHOLE_SCOPE_KEY, 'configuredReasoningEffortByModel', 'platformDecisionLayer'],
            array_keys($applicationSettings->toArray()),
            "the application's key stands where the package's stands"
        );
    }

    /**
     * The settings of a run of the models `$modelNames` under a fixed decision layer.
     *
     * @param string[] $modelNames
     */
    protected function buildSettings(array $modelNames): AgentEvalSuiteRecordSettings
    {
        $agentEvalSuiteDecisionLayerSettings = AgentEvalSuiteDecisionLayerSettings::newInstance();
        $agentEvalSuiteDecisionLayerSettings->mode = 'off';
        $agentEvalSuiteDecisionLayerSettings->siteModes = ['turnClose' => 'shadow'];
        $agentEvalSuiteDecisionLayerSettings->turnClosingMode = 'counter';
        $agentEvalSuiteRecordSettings = AgentEvalSuiteRecordSettings::forDecisionLayer($agentEvalSuiteDecisionLayerSettings);
        $agentEvalSuiteRecordSettings->modelNames = $modelNames;
        return $agentEvalSuiteRecordSettings;
    }
}
