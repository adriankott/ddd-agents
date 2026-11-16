<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\MCPPackages\ToolCallComparisons;

use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonRecording;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonScript;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonSession;
use DDD\Domain\AI\Services\Eval\ToolCallComparisonsService;
use DDD\Domain\Base\Entities\MessageHandlers\MessageDispatchCapture;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperations;
use DDD\Domain\Base\Repo\DB\Dialect\MariaDbDialect;
use DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect;
use DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseIdsAboveHighWaterMarks;
use DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseWriteStatementAudit;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use DDD\Tests\Agents\Fixtures\ToolCallComparisons\Host\Presentation\Mcp\Notes\ComparisonNotesMCPPackage;
use DDD\Tests\Agents\Support\UnitTestCase;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;

/**
 * The agent space of a comparison run, with a double for its preparation and release and for the database reads: the
 * run refuses an account before it prepares anything, prepares the space before any hook, declares its harness rows,
 * pairs them with the recorded ids on a replay, reads the minted ids around harness calls, and releases the space in
 * stop() — also when the start failed after the preparation. Ids of a table read without a scope read as placeholders
 * only when the same call inserted into that table; the application's own opaque token keys read as `<key#n>`; the
 * service builds the application's session class.
 */
class ToolCallComparisonSessionLifecycleTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        ArgusApiOperations::$transportRecording = null;
        MCPToolExecutorService::$toolResultRecording = null;
        MessageDispatchCapture::$activeCapture = null;
        parent::tearDown();
    }

    protected static function buildScript(): ToolCallComparisonScript
    {
        return ToolCallComparisonScript::fromJson((string)json_encode([
            'package' => 'ComparisonNotesMCPPackage',
            'fixture' => [['tool' => 'recordComparisonNote', 'arguments' => ['text' => 'warm up'], 'conversation' => 'chat']],
            'cases' => [['id' => 'c', 'tool' => 'recordComparisonNote', 'conversation' => 'chat']],
        ]), 'ComparisonNotes.json');
    }

    protected static function buildSessionDouble(?ToolCallComparisonSession $recordedSession, int $agentSpaceId, int $secondRowId): ToolCallComparisonSessionDouble
    {
        $sessionDouble = new ToolCallComparisonSessionDouble();
        $sessionDouble->prepareRecording(self::buildScript(), ComparisonNotesMCPPackage::class, []);
        if ($recordedSession !== null) {
            $sessionDouble->prepareReplay(self::buildScript(), ComparisonNotesMCPPackage::class, [], $recordedSession->recording);
        }
        $sessionDouble->preparedAgentSpaceId = $agentSpaceId;
        $sessionDouble->preparedSecondRowId = $secondRowId;
        return $sessionDouble;
    }

    public function testTheRecordRunPreparesDeclaresAndReleasesItsAgentSpace(): void
    {
        $sessionDouble = self::buildSessionDouble(null, 11111111, 7700001);

        $sessionDouble->start([]);

        self::assertSame(['prepare'], $sessionDouble->lifecycleEvents, 'prepared before the hooks');
        self::assertNotNull(ArgusApiOperations::$transportRecording, 'the hooks are installed after the preparation');
        self::assertSame(11111111, $sessionDouble->comparisonAgentSpaceId);
        self::assertSame(11111111, $sessionDouble->recording->comparisonAgentSpaceId);
        self::assertSame(['<comparison:agentSpace>' => '11111111', '<comparison:owner>' => '7700001'], $sessionDouble->recording->harnessIdsByPlaceholder);
        self::assertSame('{"agentSpaceId":"<comparison:agentSpace>","ownerId":"<comparison:owner>"}', json_encode($sessionDouble->idPlaceholders->normalizeStructure(['agentSpaceId' => 11111111, 'ownerId' => 7700001])));
        self::assertSame('space <comparison:agentSpace>', $sessionDouble->idPlaceholders->normalizeText('space 11111111'));
        self::assertSame(1, $sessionDouble->mintedIdReadCount, 'the rows the preparation minted are read');

        $sessionDouble->stop();

        self::assertSame(['prepare', 'release 11111111'], $sessionDouble->lifecycleEvents);
        self::assertNull(ArgusApiOperations::$transportRecording, 'the hooks are removed');
        self::assertNull(MessageDispatchCapture::$activeCapture, 'the harness capture is off');
        self::assertSame(0, $sessionDouble->comparisonAgentSpaceId);
        $sessionDouble->stop();
        self::assertSame(['prepare', 'release 11111111'], $sessionDouble->lifecycleEvents, 'released exactly once');
    }

    public function testTheReplayPairsItsOwnAgentSpaceWithTheRecordedOneForThePlayers(): void
    {
        $recordedSession = self::buildSessionDouble(null, 11111111, 7700001);
        $recordedSession->start([]);
        $recordedSession->stop();

        $replaySession = self::buildSessionDouble($recordedSession, 22222222, 7700002);
        $replaySession->start([]);

        self::assertSame(['22222222' => '11111111', '7700002' => '7700001'], $replaySession->idCounterparts->recordedIdsByReplayId);
        self::assertSame($replaySession->idCounterparts, ArgusApiOperations::$transportRecording->idCounterparts);
        self::assertSame(
            $recordedSession->idPlaceholders->normalizeText('saved space 11111111 of owner 7700001'),
            $replaySession->idPlaceholders->normalizeText('saved space 22222222 of owner 7700002'),
            'the two runs\' different ids are no difference'
        );
        $replaySession->stop();
        self::assertSame(['prepare', 'release 22222222'], $replaySession->lifecycleEvents);
    }

    public function testAFixtureCallReadsTheIdsItMinted(): void
    {
        $sessionDouble = self::buildSessionDouble(null, 11111111, 0);
        $sessionDouble->start([]);
        $mintedIdReadCountAfterStart = $sessionDouble->mintedIdReadCount;

        self::assertSame([], $sessionDouble->runFixtureSteps());

        self::assertSame(['recordComparisonNote'], $sessionDouble->executedToolNames);
        self::assertSame($mintedIdReadCountAfterStart + 1, $sessionDouble->mintedIdReadCount);
        $sessionDouble->stop();
    }

    public function testAFailedStartAfterThePreparationStillReleasesTheAgentSpace(): void
    {
        $sessionDouble = self::buildSessionDouble(null, 11111111, 0);
        $sessionDouble->refusesTheScriptReferences = true;
        try {
            $sessionDouble->start([]);
            self::fail('a script that names a record that exists refuses the run');
        } catch (RuntimeException $startFailure) {
            self::assertStringContainsString('names a record that exists', $startFailure->getMessage());
        }
        self::assertNull(ArgusApiOperations::$transportRecording, 'no hook was installed');

        $sessionDouble->stop();

        self::assertSame(['prepare', 'release 11111111'], $sessionDouble->lifecycleEvents);
    }

    public function testEveryHarnessCallStartsFromAFreshWorkerState(): void
    {
        $sessionDouble = self::buildSessionDouble(null, 11111111, 0);
        $sessionDouble->start([]);
        self::assertSame(0, $sessionDouble->workerStateResetCount, 'creating the conversations is no call');

        $sessionDouble->runFixtureSteps();

        self::assertSame(1, $sessionDouble->workerStateResetCount, 'the fixture call reads the database, not what the run left in the process');
        $sessionDouble->stop();
    }

    public function testAWarmUpCallRefusedOnPurposeIsNoFailureAndOneThatCompletesIs(): void
    {
        $sessionDouble = new ToolCallComparisonSessionDouble();
        $sessionDouble->prepareRecording(ToolCallComparisonScript::fromJson((string)json_encode([
            'package' => 'ComparisonNotesMCPPackage',
            'fixture' => [['tool' => 'recordComparisonNote', 'arguments' => [], 'conversation' => 'chat', 'expectsError' => true]],
            'cases' => [['id' => 'c', 'tool' => 'recordComparisonNote', 'conversation' => 'chat']],
        ]), 'ComparisonNotes.json'), ComparisonNotesMCPPackage::class, []);
        $sessionDouble->preparedAgentSpaceId = 11111111;
        $sessionDouble->start([]);

        $sessionDouble->toolAnswersError = true;
        self::assertSame([], $sessionDouble->runFixtureSteps());
        $sessionDouble->toolAnswersError = false;
        self::assertSame(['fixture#1 (recordComparisonNote) was expected to answer an error and completed'], $sessionDouble->runFixtureSteps());

        $sessionDouble->toolAnswersError = true;
        $sessionDouble->script->fixtureSteps->first()->expectsError = false;
        self::assertStringContainsString('fixture#1 (recordComparisonNote) answered an error', $sessionDouble->runFixtureSteps()[0], 'without the flag an error is a failure');
        $sessionDouble->stop();
    }

    public function testUnusedRecordedCallsOfHarnessCallsAreNoDifferenceButThoseOfACaseAre(): void
    {
        $recordedSession = self::buildSessionDouble(null, 11111111, 0);
        $recordedSession->start([]);
        $recordedSession->stop();
        $storedFields = json_decode($recordedSession->recording->buildStoredJson(), true);
        $storedFields['argusCalls'] = [
            ['endpoint' => 'POST:https://gateway.test/places/details', 'requestBody' => '{}', 'duringTool' => true, 'toolCallLabel' => 'fixture#1'],
            ['endpoint' => 'POST:https://gateway.test/places/details', 'requestBody' => '{}', 'duringTool' => true, 'toolCallLabel' => ToolCallComparisonSession::CONVERSATION_SETUP_LABEL],
            ['endpoint' => 'POST:https://gateway.test/places/details', 'requestBody' => '{}', 'duringTool' => true, 'toolCallLabel' => 'c'],
        ];
        $recordedSession->recording = ToolCallComparisonRecording::fromJson((string)json_encode($storedFields), 'recording.json');

        $replaySession = self::buildSessionDouble($recordedSession, 22222222, 0);
        $hostRecording = new ToolCallComparisonHostRecordingDouble((string)json_encode([
            ['label' => 'fixture#1', 'request' => ['method' => 'GET', 'endpoint' => '/space/1']],
            ['label' => 'c', 'request' => ['method' => 'GET', 'endpoint' => '/space/1']],
        ]));
        $replaySession->hostRecordingToInstall = $hostRecording;
        $replaySession->start([]);
        $replaySession->stop();

        self::assertTrue($replaySession->isHarnessCallLabel('fixture#1'));
        self::assertFalse($replaySession->isHarnessCallLabel('c'));
        self::assertSame(
            ['unused recorded Argus call #2 POST:https://gateway.test/places/details (c)', 'unused recorded back-end call #1 c'],
            $replaySession->runDifferences,
            'a call the CASE no longer makes is still a difference, for the Argus calls and for the application\'s recorder'
        );
        self::assertTrue($hostRecording->uninstalled, 'the application\'s recorder is removed when the run stops');
    }

    public function testThePinnedMomentLiesBeforeTheRunsWallClockWindow(): void
    {
        /** @var ToolCallComparisonsService $toolCallComparisonsService */
        $toolCallComparisonsService = DDDService::instance()->getService(ToolCallComparisonsService::class);
        $toolCallComparisonSession = $toolCallComparisonsService->buildSession(self::buildScript(), ComparisonNotesMCPPackage::class, []);
        $pinnedTimestamp = (int)strtotime($toolCallComparisonSession->recording->pinnedMoment);
        $toolCallComparisonSession->runStartedAtTimestamp = time();

        $wallClockNormalization = $toolCallComparisonSession->buildWallClockNormalization();

        self::assertLessThan($wallClockNormalization->clockWindow->fromTimestamp, $pinnedTimestamp, 'no stamp of the run can equal the pinned moment');
        self::assertFalse($wallClockNormalization->isUnpinnedWallClockMoment('createdTime', $toolCallComparisonSession->recording->pinnedMoment));
        self::assertTrue($wallClockNormalization->isUnpinnedWallClockMoment('createdTime', date('Y-m-d H:i:s')));
    }

    public function testARunTheAccountCheckRefusesPreparesNothing(): void
    {
        $sessionDouble = self::buildSessionDouble(null, 11111111, 0);
        $sessionDouble->refusesTheAccount = true;
        try {
            $sessionDouble->start([]);
            self::fail('the account check refuses the run');
        } catch (RuntimeException $startFailure) {
            self::assertStringContainsString('runs only as the comparison account', $startFailure->getMessage());
        }
        $sessionDouble->stop();

        self::assertSame([], $sessionDouble->lifecycleEvents);
    }

    /**
     * On a shared database a table without any of the run's scope columns holds the ids of every writer: its ids read as
     * placeholders only when the same call inserted into it; a table read through a scope column keeps its ids.
     */
    public function testAnUnscopedTableIsDeclaredOnlyWhenTheSameCallInsertedIntoIt(): void
    {
        $sessionDouble = self::buildSessionDouble(null, 11111111, 0);
        $sessionDouble->start([]);
        $idsAboveHighWaterMarks = new DatabaseIdsAboveHighWaterMarks();
        $idsAboveHighWaterMarks->idsByTable = ['scopedRows' => [900001], 'insertedRows' => [900002], 'foreignRows' => [900003], 'pgInsertedRows' => [900004]];
        $idsAboveHighWaterMarks->scopeColumnByTable = ['scopedRows' => 'agentSpaceId'];
        $sessionDouble->idsAboveHighWaterMarks = $idsAboveHighWaterMarks;
        $statementAuditOfTheFixtureCall = new DatabaseWriteStatementAudit();
        $statementAuditOfTheFixtureCall->keepIssuedStatement('INSERT INTO `insertedRows` (`label`) VALUES (?)', ['a'], new MariaDbDialect());
        $statementAuditOfTheFixtureCall->keepIssuedStatement('INSERT INTO "pgInsertedRows" ("label") VALUES (?)', ['c'], new PostgresDialect());
        $statementAuditOfTheFixtureCall->keepIssuedStatement('UPDATE `foreignRows` SET `label` = ? WHERE `id` = ?', ['b', 900003], new MariaDbDialect());
        $sessionDouble->statementAuditOfNextCall = $statementAuditOfTheFixtureCall;

        $sessionDouble->runFixtureSteps();

        self::assertSame(
            ['scopedId' => '<scopedRows#1>', 'insertedId' => '<insertedRows#1>', 'pgInsertedId' => '<pgInsertedRows#1>', 'foreignId' => 900003],
            $sessionDouble->idPlaceholders->normalizeStructure(['scopedId' => 900001, 'insertedId' => 900002, 'pgInsertedId' => 900004, 'foreignId' => 900003]),
            'a scoped table is declared as before, an unscoped table with an INSERT is declared (MariaDB or PostgreSQL quoting), one without is not'
        );
        $sessionDouble->stop();
    }

    /** A key the application declares for every script reads `<key#n>` in a dispatch body; equal tokens share it. */
    public function testAnApplicationOpaqueTokenKeyReadsAsPlaceholderInADispatchBody(): void
    {
        $sessionDouble = new ToolCallComparisonSessionDouble();
        $sessionDouble->applicationOpaqueTokenKeys = ['lockToken'];
        $sessionDouble->prepareRecording(self::buildScript(), ComparisonNotesMCPPackage::class, []);
        $messageDispatchCapture = new MessageDispatchCapture();
        $messageDispatchCapture->captureEnvelope(new Envelope(new ToolCallComparisonProbeMessage('f16531e01538788b', 'first')));
        $messageDispatchCapture->captureEnvelope(new Envelope(new ToolCallComparisonProbeMessage('f16531e01538788b', 'second')));
        $messageDispatchCapture->captureEnvelope(new Envelope(new ToolCallComparisonProbeMessage('8497aaeb0694', 'third')));

        $normalizedDispatches = $sessionDouble->idPlaceholders->normalizeStructure($messageDispatchCapture->buildComparableDispatches()->toArray());

        $lockTokens = [];
        foreach ($normalizedDispatches as $normalizedDispatch) {
            $lockTokens[] = $normalizedDispatch['body']['lockToken'] ?? null;
        }
        self::assertSame(['<lockToken#1>', '<lockToken#1>', '<lockToken#2>'], $lockTokens);
    }

    public function testTheServiceBuildsTheApplicationsSessionClass(): void
    {
        $frameworkToAppClassNamesBefore = StaticRegistry::$frameworkToAppClassNames;
        StaticRegistry::$frameworkToAppClassNames[ToolCallComparisonSession::class] = ToolCallComparisonSessionDouble::class;
        try {
            /** @var ToolCallComparisonsService $toolCallComparisonsService */
            $toolCallComparisonsService = DDDService::instance()->getService(ToolCallComparisonsService::class);

            self::assertInstanceOf(ToolCallComparisonSessionDouble::class, $toolCallComparisonsService->newSession());
            self::assertInstanceOf(ToolCallComparisonSessionDouble::class, $toolCallComparisonsService->buildSession(self::buildScript(), ComparisonNotesMCPPackage::class, []));
        } finally {
            StaticRegistry::$frameworkToAppClassNames = $frameworkToAppClassNamesBefore;
        }
    }
}
