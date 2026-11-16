<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Evals\SuiteRuns\AgentEvalSuiteRun;
use DDD\Domain\AI\Services\Eval\AgentEvalSuiteCasesService;
use DDD\Domain\AI\Services\Eval\AgentEvalSuiteRunsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Domain\AI\Entities\Evals\SuiteRuns\AgentEvalSuiteRunRecordTest;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The record of a finished run is written best effort: a failed write is reported and never fails the evaluation; a
 * written run carries this host and both moments in UTC.
 */
class AgentEvalSuiteRunsServiceTest extends UnitTestCase
{
    public function testAFailedWriteIsLoggedAndNeverFailsTheEvaluation(): void
    {
        $agentEvalSuiteRunsService = new AgentEvalSuiteRunsServiceWithoutDatabaseDouble();
        $agentEvalSuiteRunsService->insertFails = true;
        $recordedRun = $agentEvalSuiteRunsService->recordSuiteRun(
            'app:ai-agent:eval-tools',
            'all',
            1_761_658_758,
            ['model' => 'VENDOR.MODEL_A'],
            'run-folder',
            AgentEvalSuiteRunRecordTest::loadFixtureCaseRows()
        );
        self::assertNull($recordedRun);
        self::assertSame("app:ai-agent:eval-tools|all|6|Table 'AgentEvalSuiteRuns' doesn't exist", $agentEvalSuiteRunsService->loggedFailure);
    }

    public function testAWrittenRunCarriesThisHostAndBothMomentsInUtc(): void
    {
        $agentEvalSuiteRunsService = new AgentEvalSuiteRunsServiceWithoutDatabaseDouble();
        $secondsBefore = time();
        $recordedRun = $agentEvalSuiteRunsService->recordSuiteRun(
            'app:ai-agent:eval-tools',
            'all',
            1_761_658_758,
            ['model' => 'VENDOR.MODEL_A', 'flavor' => 'call'],
            'run-folder',
            AgentEvalSuiteRunRecordTest::loadFixtureCaseRows()
        );
        self::assertInstanceOf(AgentEvalSuiteRun::class, $recordedRun);
        self::assertSame($agentEvalSuiteRunsService->insertedAgentEvalSuiteRun, $recordedRun);
        self::assertNull($agentEvalSuiteRunsService->loggedFailure);
        self::assertSame(gethostname() ?: null, $recordedRun->host);
        self::assertSame('UTC', $recordedRun->startedAt->getTimezone()->getName());
        self::assertSame('UTC', $recordedRun->finishedAt->getTimezone()->getName());
        self::assertSame(1_761_658_758, $recordedRun->startedAt->getTimestamp());
        self::assertGreaterThanOrEqual($secondsBefore, $recordedRun->finishedAt->getTimestamp());
        self::assertSame(6, $recordedRun->casesTotal);
        self::assertCount(6, $recordedRun->agentEvalSuiteCases);
    }

    public function testBothServicesAreContainerServicesOfTheirEntities(): void
    {
        self::assertInstanceOf(AgentEvalSuiteRunsService::class, DDDService::instance()->getService(AgentEvalSuiteRunsService::class));
        self::assertInstanceOf(AgentEvalSuiteCasesService::class, DDDService::instance()->getService(AgentEvalSuiteCasesService::class));
        self::assertInstanceOf(AgentEvalSuiteRunsService::class, AgentEvalSuiteRun::getService());
    }
}
