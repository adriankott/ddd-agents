<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Evals;

use DDD\Domain\AI\Entities\Evals\AgentEvalStoredRunOptions;
use DDD\Domain\AI\Entities\Evals\SuiteRuns\AgentEvalSuiteCases\AgentEvalSuiteCase;
use DDD\Domain\AI\Entities\Evals\SuiteRuns\AgentEvalSuiteCases\AgentEvalSuiteCases;
use DDD\Domain\AI\Entities\Evals\SuiteRuns\AgentEvalSuiteRun;
use DDD\Domain\AI\Repo\DB\Evals\SuiteRuns\AgentEvalSuiteCases\DBAgentEvalSuiteCaseModel;
use DDD\Domain\AI\Repo\DB\Evals\SuiteRuns\DBAgentEvalSuiteRunModel;
use DDD\Domain\AI\Services\Eval\AgentEvalSuiteRunsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Entities\Roles\Role;
use DDD\Domain\Common\Entities\Roles\Roles;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Domain\AI\Entities\Evals\SuiteRuns\AgentEvalSuiteRunRecordTest;
use DDD\Tests\Agents\Support\DatabaseTestCase;
use Throwable;

/**
 * The record of a finished evaluation run against the database, on both engines: the run row and its case rows are
 * written with the rights lifted (a system write under an account that is no admin), an admin with access to all
 * resources reads the run and pages through its cases by verdict, every other account reads neither and neither
 * changes nor deletes the run.
 */
class AgentEvalSuiteRunsDatabaseTest extends DatabaseTestCase
{
    protected ?Account $accountBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountBefore = AuthService::instance()->getAccount();
    }

    protected function onTearDown(): void
    {
        AuthService::instance()->setAccount($this->accountBefore);
    }

    public function testARunAndItsCasesAreStoredWithTheirColumns(): void
    {
        $this->authenticateWithRole('USER');
        $agentEvalSuiteRun = $this->recordFixtureRun();

        $storedRunRow = $this->findStoredRow(DBAgentEvalSuiteRunModel::getTableName(), (int)$agentEvalSuiteRun->id);
        self::assertNotNull($storedRunRow);
        self::assertSame('app:agent:eval-suite', $storedRunRow['command']);
        self::assertSame('default-suite', $storedRunRow['suiteLabel']);
        self::assertSame('run-folder-default', $storedRunRow['logDirectoryName']);
        self::assertSame(6, (int)$storedRunRow['casesTotal']);
        self::assertSame(1, (int)$storedRunRow['casesPassed']);
        self::assertSame(2, (int)$storedRunRow['casesFailed']);
        self::assertSame(1, (int)$storedRunRow['casesErrored']);
        self::assertSame(1, (int)$storedRunRow['casesNotApplicable']);
        self::assertSame(1, (int)$storedRunRow['casesFailedAsExpected']);
        self::assertSame(['model' => 'VENDOR.MODEL_A', 'flavor' => 'judge'], json_decode((string)$storedRunRow['runOptionsJson'], true));
        self::assertSame(gmdate('Y-m-d H:i:s', 1_761_658_758), substr((string)$storedRunRow['startedAt'], 0, 19));

        $storedCaseRows = $this->getDatabaseConnection()->fetchAllAssociative(
            'SELECT * FROM ' . $this->getSqlDialect()->quote(DBAgentEvalSuiteCaseModel::getTableName())
            . ' WHERE ' . $this->getSqlDialect()->quote('agentEvalSuiteRunId') . ' = ? ORDER BY ' . $this->getSqlDialect()->quote('id'),
            [(int)$agentEvalSuiteRun->id]
        );
        self::assertCount(6, $storedCaseRows);
        self::assertSame(
            [AgentEvalSuiteCase::VERDICT_PASS, AgentEvalSuiteCase::VERDICT_FAIL, AgentEvalSuiteCase::VERDICT_FAIL, AgentEvalSuiteCase::VERDICT_ERROR, AgentEvalSuiteCase::VERDICT_NOT_APPLICABLE, AgentEvalSuiteCase::VERDICT_EXPECTED_FAIL],
            array_column($storedCaseRows, 'verdict')
        );
        self::assertSame('rankings.last-7-days', $storedCaseRows[0]['caseId']);
        self::assertSame(855901, (int)$storedCaseRows[0]['aiConversationId']);
        self::assertSame(38.4, (float)$storedCaseRows[0]['wallSeconds']);
        self::assertSame(0.0041, (float)$storedCaseRows[0]['costUsd']);
        self::assertNull($storedCaseRows[3]['aiConversationId']);
        self::assertStringContainsString('a restore step was refused', (string)$storedCaseRows[3]['findings']);
    }

    public function testAnAdminReadsTheRunAndPagesThroughItsCasesByVerdict(): void
    {
        $this->authenticateWithRole('USER');
        $agentEvalSuiteRunId = (int)$this->recordFixtureRun()->id;

        $this->authenticateWithRole(Role::SUPERADMIN);
        $agentEvalSuiteRun = $this->getAgentEvalSuiteRunsService()->find($agentEvalSuiteRunId, false);
        self::assertInstanceOf(AgentEvalSuiteRun::class, $agentEvalSuiteRun);
        self::assertSame(6, $agentEvalSuiteRun->casesTotal);

        $firstPage = $agentEvalSuiteRun->findCases(null, 2, 0);
        self::assertSame(['rankings.last-7-days', 'taskplandelivery.carry-across-turns'], $this->collectCaseIds($firstPage));
        $secondPage = $agentEvalSuiteRun->findCases(null, 2, 2);
        self::assertSame(['multistep.nesteddone', 'reply.edit'], $this->collectCaseIds($secondPage));
        $failedOrErrored = $agentEvalSuiteRun->findCases([AgentEvalSuiteCase::VERDICT_FAIL, AgentEvalSuiteCase::VERDICT_ERROR], 25, 0);
        self::assertSame(['taskplandelivery.carry-across-turns', 'multistep.nesteddone', 'reply.edit'], $this->collectCaseIds($failedOrErrored));

        self::assertSame(6, $agentEvalSuiteRun->countCases(null));
        self::assertSame(3, $agentEvalSuiteRun->countCases([AgentEvalSuiteCase::VERDICT_FAIL, AgentEvalSuiteCase::VERDICT_ERROR]));
        self::assertSame(1, $this->getAgentEvalSuiteRunsService()->countAll());
    }

    public function testEveryOtherAccountReadsNeitherTheRunNorItsCases(): void
    {
        $this->authenticateWithRole('USER');
        $recordedRun = $this->recordFixtureRun();

        self::assertNull($this->getAgentEvalSuiteRunsService()->find((int)$recordedRun->id, false));
        self::assertSame(0, $recordedRun->countCases(null));
        self::assertCount(0, $recordedRun->findCases(null, 25, 0));
        self::assertSame(0, $this->getAgentEvalSuiteRunsService()->countAll());
    }

    public function testAnotherAccountChangesNeitherTheRunNorDeletesIt(): void
    {
        $this->authenticateWithRole('USER');
        $recordedRun = $this->recordFixtureRun();
        $recordedRunId = (int)$recordedRun->id;

        $recordedRun->suiteLabel = 'changed-by-another-account';
        $agentEvalSuiteRunsService = $this->getAgentEvalSuiteRunsService();
        // a write without the rights may refuse with an exception or match no row: both leave the stored rows as they are
        try {
            $agentEvalSuiteRunsService->update($recordedRun);
        } catch (Throwable) {
        }
        try {
            $agentEvalSuiteRunsService->delete($recordedRun);
        } catch (Throwable) {
        }

        $storedRunRow = $this->findStoredRow(DBAgentEvalSuiteRunModel::getTableName(), $recordedRunId);
        self::assertNotNull($storedRunRow, 'the run row is still stored');
        self::assertSame('default-suite', $storedRunRow['suiteLabel'], 'the label is unchanged');
        self::assertSame(6, (int)$this->getDatabaseConnection()->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->getSqlDialect()->quote(DBAgentEvalSuiteCaseModel::getTableName())
            . ' WHERE ' . $this->getSqlDialect()->quote('agentEvalSuiteRunId') . ' = ?',
            [$recordedRunId]
        ), 'its cases are still stored');
    }

    protected function recordFixtureRun(): AgentEvalSuiteRun
    {
        $agentEvalSuiteRun = $this->getAgentEvalSuiteRunsService()->recordSuiteRun(
            'app:agent:eval-suite',
            'default-suite',
            1_761_658_758,
            AgentEvalStoredRunOptions::fromStoredDocument(['model' => 'VENDOR.MODEL_A', 'flavor' => 'judge']),
            'run-folder-default',
            AgentEvalSuiteRunRecordTest::loadFixtureCaseRows()
        );
        self::assertInstanceOf(AgentEvalSuiteRun::class, $agentEvalSuiteRun, 'the record was written (a failed write returns null)');
        self::assertNotNull($agentEvalSuiteRun->id);
        return $agentEvalSuiteRun;
    }

    /**
     * @param AgentEvalSuiteCases $agentEvalSuiteCases
     * @return string[] The case ids in the order of the set
     */
    protected function collectCaseIds(AgentEvalSuiteCases $agentEvalSuiteCases): array
    {
        $caseIds = [];
        foreach ($agentEvalSuiteCases->getElements() as $agentEvalSuiteCase) {
            $caseIds[] = (string)$agentEvalSuiteCase->caseId;
        }
        return $caseIds;
    }

    protected function authenticateWithRole(string $roleName): void
    {
        $role = new Role();
        $role->setName($roleName);
        $roles = new Roles();
        $roles->add($role);
        $account = new Account();
        $account->id = 11;
        $account->roles = $roles;
        AuthService::instance()->setAccount($account);
    }

    protected function getAgentEvalSuiteRunsService(): AgentEvalSuiteRunsService
    {
        /** @var AgentEvalSuiteRunsService $agentEvalSuiteRunsService */
        $agentEvalSuiteRunsService = DDDService::instance()->getService(AgentEvalSuiteRunsService::class);
        return $agentEvalSuiteRunsService;
    }
}
