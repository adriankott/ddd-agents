<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRows;
use DDD\Domain\AI\Entities\Evals\AgentEvalStoredRunOptions;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteDecisionLayerSettings;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuitePlanSummary;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteRecord;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteRecordRuns;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteRecordSettings;
use DDD\Domain\AI\Entities\Evals\SuiteRuns\AgentEvalSuiteRun;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The options a stored run keeps are the JSON each evaluation command has always stored in `runOptionsJson`: the
 * expected documents are the array literals the tools, selection and suite evaluations and the setup run of an
 * application hand to the stored run, encoded with the column's flags — key by key, null values included.
 */
class AgentEvalStoredRunOptionsTest extends UnitTestCase
{
    public const int COLUMN_JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function testTheToolsRunStoresItsSixKeysInOrder(): void
    {
        $runOptions = AgentEvalStoredRunOptions::forSingleModelRun('VENDOR.MODEL_A', 'judge', ['decision-model-skill-preload' => 'on'])
            ->addRepeat(3)
            ->addSkillFilter(null)
            ->addCaseFilter('catalog.list');

        self::assertSame(
            (string)json_encode(['model' => 'VENDOR.MODEL_A', 'flavor' => 'judge', 'arms' => ['decision-model-skill-preload' => 'on'], 'repeat' => 3, 'skill' => null, 'case' => 'catalog.list'], self::COLUMN_JSON_FLAGS),
            (string)json_encode($runOptions->toArray(), self::COLUMN_JSON_FLAGS)
        );
        self::assertSame('VENDOR.MODEL_A', $runOptions->getModelName());
        self::assertSame('judge', $runOptions->getFlavor());
        self::assertTrue($runOptions->hasKey('skill'), 'a null value is stored');
    }

    public function testTheSelectionAndTheFixtureRunsStoreTheirKeysInOrder(): void
    {
        self::assertSame(
            ['model' => null, 'flavor' => 'call', 'arms' => [], 'case' => null],
            AgentEvalStoredRunOptions::forSingleModelRun(null, 'call', [])->addCaseFilter(null)->toArray()
        );
        self::assertSame(
            ['model' => 'VENDOR.MODEL_A', 'flavor' => 'judge', 'arms' => [], 'fixture' => 'General', 'case' => null],
            AgentEvalStoredRunOptions::forSingleModelRun('VENDOR.MODEL_A', 'judge', [])->addFixtureName('General')->addCaseFilter(null)->toArray()
        );
        self::assertNull(AgentEvalStoredRunOptions::forSingleModelRun(null, 'call', [])->getModelName(), 'the conversation default names no model');
    }

    public function testTheSuiteRunStoresTheRecordsSettingsVerdictAndBaseline(): void
    {
        $agentEvalSuiteRecordSettings = AgentEvalSuiteRecordSettings::forDecisionLayer(AgentEvalSuiteDecisionLayerSettings::newInstance());
        $agentEvalSuiteRecordSettings->modelNames = ['VENDOR.MODEL_A'];
        $agentEvalSuiteRecordSettings->flavors = ['call', 'judge'];
        $agentEvalSuiteRecordSettings->suites = ['tools', 'selection'];
        $agentEvalSuiteRecordSettings->arms = ['reasoning-effort' => 'low'];
        $agentEvalSuiteRecordSettings->persistedConversations = true;
        $agentEvalSuiteRecord = AgentEvalSuiteRecord::forFinishedRun(1_700_000_000, 60, true, $agentEvalSuiteRecordSettings, AgentEvalSuitePlanSummary::newInstance(), null, null, AgentEvalSuiteRecordRuns::newInstance());

        $runOptions = AgentEvalStoredRunOptions::forSuiteRecord($agentEvalSuiteRecord);

        self::assertSame(
            [
                'models' => ['VENDOR.MODEL_A'],
                'flavors' => ['call', 'judge'],
                'suites' => ['tools', 'selection'],
                'arms' => ['reasoning-effort' => 'low'],
                'persistedConversations' => true,
                'stopped' => true,
                'verdict' => ['correctness' => 'FAILED', 'performance' => 'NOT_COMPARED'],
                'baseline' => null,
            ],
            $runOptions->toArray()
        );
        self::assertNull($runOptions->getModelName());
        self::assertNull($runOptions->getFlavor());
    }

    public function testTheStoredRunWritesAndReadsTheOptions(): void
    {
        $runOptions = AgentEvalStoredRunOptions::forSingleModelRun('VENDOR.MODEL_A', 'call', [])->addStoredValue('applicationKey', 7);

        $agentEvalSuiteRun = AgentEvalSuiteRun::forFinishedRun('app:test:eval', 'all', 'host', DateTime::fromTimestamp(1_700_000_000), DateTime::fromTimestamp(1_700_000_010), $runOptions, 'run', AgentEvalCaseRows::newInstance());

        self::assertSame('{"model":"VENDOR.MODEL_A","flavor":"call","arms":[],"applicationKey":7}', $agentEvalSuiteRun->runOptionsJson);
        self::assertSame($runOptions->toArray(), $agentEvalSuiteRun->getRunOptions()->toArray());
        self::assertSame(7, $agentEvalSuiteRun->getRunOptions()->getValue('applicationKey'));
        self::assertTrue(AgentEvalStoredRunOptions::none()->isEmpty());
        $agentEvalSuiteRun->runOptionsJson = '"no object"';
        self::assertTrue($agentEvalSuiteRun->getRunOptions()->isEmpty(), 'a column without a JSON object reads as no options');
    }
}
