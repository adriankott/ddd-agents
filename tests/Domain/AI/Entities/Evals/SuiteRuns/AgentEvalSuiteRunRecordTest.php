<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals\SuiteRuns;

use DateTimeZone;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRow;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRows;
use DDD\Domain\AI\Entities\Evals\AgentEvalStoredRunOptions;
use DDD\Domain\AI\Entities\Evals\SuiteRuns\AgentEvalSuiteCases\AgentEvalSuiteCase;
use DDD\Domain\AI\Entities\Evals\SuiteRuns\AgentEvalSuiteCases\AgentEvalSuiteCases;
use DDD\Domain\AI\Entities\Evals\SuiteRuns\AgentEvalSuiteRun;
use DDD\Domain\AI\Entities\Evals\SuiteRuns\AgentEvalSuiteRuns;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Libs\Config;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionProperty;

/**
 * The record of a finished evaluation run is built from the case rows the evaluation commands aggregate. The rows of
 * the fixture have the shapes of a suite run's `_summary.json` and of a purposed run's `summary.json`; the build is
 * pure.
 */
class AgentEvalSuiteRunRecordTest extends UnitTestCase
{
    protected const int STARTED_AT_TIMESTAMP = 1_761_658_758;

    /** @var array<string, mixed>|null the config values read so far, restored after every test */
    protected ?array $configTreeBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $this->configTreeBefore = $configTree->isInitialized() ? $configTree->getValue() : null;
    }

    protected function tearDown(): void
    {
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $configTree->setValue(null, $this->configTreeBefore ?? []);
        parent::tearDown();
    }

    public function testTheRunCountsEveryVerdict(): void
    {
        $agentEvalSuiteRun = $this->buildRunFromFixture();
        self::assertSame(6, $agentEvalSuiteRun->casesTotal);
        self::assertSame(1, $agentEvalSuiteRun->casesPassed);
        self::assertSame(2, $agentEvalSuiteRun->casesFailed);
        self::assertSame(1, $agentEvalSuiteRun->casesErrored);
        self::assertSame(1, $agentEvalSuiteRun->casesNotApplicable);
        self::assertSame(1, $agentEvalSuiteRun->casesFailedAsExpected);
        self::assertSame(5, $agentEvalSuiteRun->getCountedCases());
        self::assertSame('app:ai-agent:eval-suite', $agentEvalSuiteRun->command);
        self::assertSame('run-folder-default', $agentEvalSuiteRun->logDirectoryName);
        self::assertSame(gmdate('Y-m-d H:i:s', self::STARTED_AT_TIMESTAMP), $agentEvalSuiteRun->startedAt->format('Y-m-d H:i:s'));
        self::assertSame(['models' => ['VENDOR.MODEL_A'], 'model' => 'VENDOR.MODEL_A', 'flavor' => 'judge', 'arms' => []], $agentEvalSuiteRun->getRunOptions()->toArray());
        self::assertCount(6, $agentEvalSuiteRun->agentEvalSuiteCases);
        self::assertStringContainsString('1/5 passed, 2 failed, 1 errors, 1 failed as expected, 1 not applicable', $agentEvalSuiteRun->getSummaryLine());
        self::assertStringContainsString('1 h 02 min', $agentEvalSuiteRun->getSummaryLine());
    }

    public function testEachCaseCarriesItsVerdictConversationAndNumbers(): void
    {
        $agentEvalSuiteCases = array_values($this->buildRunFromFixture()->agentEvalSuiteCases->getElements());
        self::assertSame(
            [AgentEvalSuiteCase::VERDICT_PASS, AgentEvalSuiteCase::VERDICT_FAIL, AgentEvalSuiteCase::VERDICT_FAIL, AgentEvalSuiteCase::VERDICT_ERROR, AgentEvalSuiteCase::VERDICT_NOT_APPLICABLE, AgentEvalSuiteCase::VERDICT_EXPECTED_FAIL],
            array_map(static fn (AgentEvalSuiteCase $agentEvalSuiteCase): ?string => $agentEvalSuiteCase->verdict, $agentEvalSuiteCases)
        );
        $passedCase = $agentEvalSuiteCases[0];
        self::assertSame('rankings.last-7-days', $passedCase->caseId);
        self::assertSame('tools', $passedCase->suiteName);
        self::assertSame('Reports.Rankings', $passedCase->fixtureName);
        self::assertSame('call', $passedCase->flavor);
        self::assertSame(855901, $passedCase->aiConversationId);
        self::assertNull($passedCase->findings);
        self::assertSame(38.4, $passedCase->wallSeconds, 'the process seconds of a suite case are its wall time');
        self::assertSame(19.0, $passedCase->agentSeconds);
        self::assertSame(1, $passedCase->ownerTurns);
        self::assertSame(3, $passedCase->modelCalls);
        self::assertSame(3, $passedCase->toolCalls);
        self::assertSame(0.0041, $passedCase->costUsd);

        $deterministicFail = $agentEvalSuiteCases[1];
        self::assertStringStartsWith("failure: forbidden tool called: 'sendMessage'", (string)$deterministicFail->findings);

        $judgedFail = $agentEvalSuiteCases[2];
        self::assertSame(
            "judge: The plan stayed flat: no subtasks for the six variants.\n"
            . "criterion violated: Task plan has a real two-level structure — update_task_plan created only 3 flat items.\n"
            . 'ungrounded claim: claims variant C was published',
            $judgedFail->findings,
            'the judge reasons, the criteria not met (a met one is left out) and the ungrounded claims'
        );

        $erroredCase = $agentEvalSuiteCases[3];
        self::assertNull($erroredCase->aiConversationId);
        self::assertSame(600.2, $erroredCase->wallSeconds);
        self::assertNull($erroredCase->modelCalls);
        self::assertStringContainsString('error: the case\'s child process exceeded its 600s ceiling', (string)$erroredCase->findings);
        self::assertStringContainsString('ran twice after an infrastructure error: cURL error 28', (string)$erroredCase->findings);
        self::assertStringContainsString('a restore step was refused', (string)$erroredCase->findings);

        $notApplicableCase = $agentEvalSuiteCases[4];
        self::assertSame('Campaigns.Overview', $notApplicableCase->fixtureName, 'a row without a fixture names its skill');
        self::assertSame('not applicable: the workspace does not offer Campaigns.Overview', $notApplicableCase->findings);

        $caseFailingByDesign = $agentEvalSuiteCases[5];
        self::assertSame('VENDOR.MODEL_A', $caseFailingByDesign->modelName, 'a row without a model takes the run\'s');
        self::assertSame('judge', $caseFailingByDesign->flavor);
        self::assertSame(402.6, $caseFailingByDesign->wallSeconds, 'without process or duration the conversation wall time');
        self::assertSame(31, $caseFailingByDesign->modelCalls);
    }

    public function testFindingsAreCutAtTheirMaximum(): void
    {
        $agentEvalSuiteCase = AgentEvalSuiteCase::fromEvalCaseRow(AgentEvalCaseRow::fromArray([
            'case' => 'long.findings',
            'passed' => false,
            'failures' => [str_repeat('a', 900), str_repeat('b', 900)],
        ]));
        self::assertSame(AgentEvalSuiteCase::FINDINGS_MAX_LENGTH, mb_strlen((string)$agentEvalSuiteCase->findings));
        self::assertStringEndsWith('…', (string)$agentEvalSuiteCase->findings);
    }

    public function testTheCaseListFencesTheFindingsAsData(): void
    {
        $markdown = $this->buildRunFromFixture()->agentEvalSuiteCases->toLlmMarkdown();
        self::assertStringContainsString('- multistep.nesteddone · FAIL · multistep/MultiStep · VENDOR.MODEL_A judge · conversation 855954 · 83.5 s wall', $markdown);
        self::assertStringContainsString('<untrusted source="model">judge: The plan stayed flat', $markdown);
        self::assertSame(5, substr_count($markdown, '<untrusted'), 'one fence per case with findings (the passed case has none)');
    }

    public function testTheRefusedRestoreNamesTheAgentSpaceWithTheApplicationsWord(): void
    {
        $caseRow = AgentEvalCaseRow::fromArray(['case' => 'reply.edit', 'passed' => false, 'restoreFailed' => true]);
        self::assertSame('a restore step was refused: the workspace was left changed', AgentEvalSuiteCase::fromEvalCaseRow($caseRow)->findings);

        $this->declareWordingOfTheApplication(['agentSpaceName' => 'site']);
        self::assertSame(
            'a restore step was refused: the site was left changed',
            AgentEvalSuiteCase::fromEvalCaseRow($caseRow)->findings,
            'the space is named with the word the application declares'
        );
    }

    public function testTheVerdictOrderOfARow(): void
    {
        $verdictsByRow = [
            AgentEvalSuiteCase::VERDICT_NOT_APPLICABLE => ['case' => 'a', 'passed' => true, 'outcome' => AgentEvalCaseRow::OUTCOME_NOT_APPLICABLE],
            AgentEvalSuiteCase::VERDICT_PASS => ['case' => 'b', 'passed' => true, 'expectedFail' => true, 'error' => 'late error'],
            AgentEvalSuiteCase::VERDICT_EXPECTED_FAIL => ['case' => 'c', 'passed' => false, 'expectedFail' => true, 'error' => 'an error'],
            AgentEvalSuiteCase::VERDICT_ERROR => ['case' => 'd', 'passed' => false, 'error' => 'an error'],
            AgentEvalSuiteCase::VERDICT_FAIL => ['case' => 'e', 'passed' => 'yes', 'error' => ''],
        ];
        foreach ($verdictsByRow as $expectedVerdict => $caseRowValues) {
            self::assertSame($expectedVerdict, AgentEvalSuiteCase::fromEvalCaseRow(AgentEvalCaseRow::fromArray($caseRowValues))->verdict, $caseRowValues['case']);
        }
    }

    public function testTheNumbersFallBackToTheRowsOwnFields(): void
    {
        $agentEvalSuiteCase = AgentEvalSuiteCase::fromEvalCaseRow(AgentEvalCaseRow::fromArray([
            'case' => 'row.fields',
            'passed' => true,
            'durationMs' => 1234,
            'toolCallCount' => '4',
            'costUsd' => 0.1234567,
            'conversationId' => 0,
        ]), 'VENDOR.MODEL_B', 'call');
        self::assertSame(1.2, $agentEvalSuiteCase->wallSeconds, 'without process seconds the duration');
        self::assertSame(4, $agentEvalSuiteCase->toolCalls);
        self::assertSame(0.123457, $agentEvalSuiteCase->costUsd);
        self::assertNull($agentEvalSuiteCase->aiConversationId, 'a conversation id of 0 is no conversation');
        self::assertNull($agentEvalSuiteCase->agentSeconds);
        self::assertSame('VENDOR.MODEL_B', $agentEvalSuiteCase->modelName);
        self::assertSame('row.fields · PASS · - · VENDOR.MODEL_B call · no conversation · 1.2 s wall, 4 tool calls, $0.1235', $agentEvalSuiteCase->getSummaryLine());
    }

    public function testTheHeaderIsCutToItsColumnsAndEmptyValuesAreNotStored(): void
    {
        $startedAt = DateTime::fromTimestamp(self::STARTED_AT_TIMESTAMP);
        $agentEvalSuiteRun = AgentEvalSuiteRun::forFinishedRun(
            str_repeat('c', 70),
            '',
            str_repeat('h', 130),
            $startedAt,
            DateTime::fromTimestamp(self::STARTED_AT_TIMESTAMP + 65),
            AgentEvalStoredRunOptions::none(),
            '',
            AgentEvalCaseRows::newInstance()
        );
        self::assertSame(AgentEvalSuiteRun::COMMAND_MAX_LENGTH, mb_strlen((string)$agentEvalSuiteRun->command));
        self::assertSame(AgentEvalSuiteRun::HOST_MAX_LENGTH, mb_strlen((string)$agentEvalSuiteRun->host));
        self::assertNull($agentEvalSuiteRun->suiteLabel);
        self::assertNull($agentEvalSuiteRun->logDirectoryName);
        self::assertNull($agentEvalSuiteRun->runOptionsJson, 'no options, no JSON');
        self::assertSame([], $agentEvalSuiteRun->getRunOptions()->toArray());
        self::assertTrue($agentEvalSuiteRun->getRunOptions()->isEmpty());
        self::assertSame(0, $agentEvalSuiteRun->casesTotal);
        self::assertCount(0, $agentEvalSuiteRun->agentEvalSuiteCases);
        self::assertStringEndsWith('· 1 min 05 s · 0/0 passed', $agentEvalSuiteRun->getSummaryLine());
        self::assertSame(
            "Eval run {$agentEvalSuiteRun->getSummaryLine()}\nHost: " . $agentEvalSuiteRun->host . ' · traces under var/agent-evals/(not written) on that host',
            $agentEvalSuiteRun->toLlmMarkdown()
        );
    }

    public function testTheRunHeaderNamesHostFolderAndOptions(): void
    {
        $markdown = $this->buildRunFromFixture()->toLlmMarkdown();
        self::assertStringStartsWith('Eval run #? app:ai-agent:eval-suite "default-suite" · started ', $markdown);
        self::assertStringContainsString("\nHost: eval-host · traces under var/agent-evals/run-folder-default on that host\n", $markdown);
        self::assertStringEndsWith('Options: {"models":["VENDOR.MODEL_A"],"model":"VENDOR.MODEL_A","flavor":"judge","arms":[]}', $markdown);
    }

    public function testTheSetsSayWhenNothingMatches(): void
    {
        self::assertSame('No eval run matches.', AgentEvalSuiteRuns::newInstance()->toLlmMarkdown());
        self::assertSame('No case matches.', AgentEvalSuiteCases::newInstance()->toLlmMarkdown());
        $agentEvalSuiteRuns = AgentEvalSuiteRuns::newInstance();
        $agentEvalSuiteRun = $this->buildRunFromFixture();
        $agentEvalSuiteRuns->add($agentEvalSuiteRun);
        self::assertStringStartsWith('- #? app:ai-agent:eval-suite "default-suite"', $agentEvalSuiteRuns->toLlmMarkdown());
    }

    protected function buildRunFromFixture(): AgentEvalSuiteRun
    {
        $startedAt = DateTime::fromTimestamp(self::STARTED_AT_TIMESTAMP);
        $startedAt->setTimezone(new DateTimeZone('UTC'));
        $finishedAt = DateTime::fromTimestamp(self::STARTED_AT_TIMESTAMP + 3_725);
        $finishedAt->setTimezone(new DateTimeZone('UTC'));
        return AgentEvalSuiteRun::forFinishedRun(
            'app:ai-agent:eval-suite',
            'default-suite',
            'eval-host',
            $startedAt,
            $finishedAt,
            AgentEvalStoredRunOptions::fromStoredDocument(['models' => ['VENDOR.MODEL_A'], 'model' => 'VENDOR.MODEL_A', 'flavor' => 'judge', 'arms' => []]),
            'run-folder-default',
            static::loadFixtureCaseRows()
        );
    }

    /** The case rows of the fixture file. */
    public static function loadFixtureCaseRows(): AgentEvalCaseRows
    {
        $fixture = json_decode((string)file_get_contents(__DIR__ . '/Fixtures/eval-suite-case-rows.json'), true);
        $caseRows = AgentEvalCaseRows::newInstance();
        foreach ($fixture['cases'] as $caseRowValues) {
            $caseRow = AgentEvalCaseRow::fromArray($caseRowValues);
            $caseRows->add($caseRow);
        }
        return $caseRows;
    }

    /**
     * Writes the application's wording where {@see Config::get()} reads it first: the values it has already read.
     *
     * @param array<string, mixed> $wordingOfTheApplication
     */
    protected function declareWordingOfTheApplication(array $wordingOfTheApplication): void
    {
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $configValues = $configTree->isInitialized() ? $configTree->getValue() : [];
        $configValues['AI']['agentApplicationWording'] = $wordingOfTheApplication;
        $configTree->setValue(null, $configValues);
    }
}
