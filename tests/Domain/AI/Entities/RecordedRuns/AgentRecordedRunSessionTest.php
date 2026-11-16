<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\RecordedRuns;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunIdNormalization;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunPlayedAnswerIdTranslation;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunCreatedIdRead;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunQueuedMessagesHost;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunRecording;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunScope;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunSession;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunSnapshot;
use DDD\Domain\AI\Entities\RecordedRuns\RecordedRunIdSource;
use DDD\Domain\AI\Entities\UsageLogs\AIBudgetBypassGrant;
use DDD\Domain\AI\Libs\RecordedRuns\RecordedRunClockAndIdSources;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\AI\Services\Conversations\AgentToolCallForkJoinService;
use DDD\Domain\AI\Services\Decisions\AgentDecisionModelCircuitBreakerService;
use DDD\Domain\AI\Services\Eval\AgentRecordedRunsService;
use DDD\Domain\Base\Entities\MessageHandlers\MessageDispatchCapture;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperations;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The per-run session: it installs every process switch of a recorded run and puts back what was installed before, a
 * replay takes its seed and its live tools from the recording, a live-tools replay translates played answers, the
 * host of one call of the queue restores the harness's account and grant, and the run is described in one line.
 * The high-water marks are given (no database is read).
 */
class AgentRecordedRunSessionTest extends UnitTestCase
{
    protected ?Account $accountBeforeTest = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountBeforeTest = AuthService::instance()->getAccount();
    }

    protected function tearDown(): void
    {
        AuthService::instance()->setAccount($this->accountBeforeTest);
        parent::tearDown();
    }

    protected static function newSession(?AgentRecordedRunRecording $agentRecordedRunRecording = null, bool $toolsRunLive = false): AgentRecordedRunSession
    {
        /** @var AgentRecordedRunsService $agentRecordedRunsService */
        $agentRecordedRunsService = DDDService::instance()->getService(AgentRecordedRunsService::class);
        $agentRecordedRunSession = $agentRecordedRunsService->startSession($agentRecordedRunRecording, $toolsRunLive);
        // the marks are given: start() reads none from the database
        $agentRecordedRunSession->idNormalization = AgentRecordedRunIdNormalization::withHighWaterMarks(['notes' => 10], []);
        return $agentRecordedRunSession;
    }

    public function testStartInstallsEverySwitchAndStopPutsBackWhatWasInstalledBefore(): void
    {
        $idSourceBefore = RecordedRunIdSource::seeded('installed-before');
        RecordedRunClockAndIdSources::$installedIdSource = $idSourceBefore;
        $agentRecordedRunSession = self::newSession();
        try {
            $agentRecordedRunSession->start();

            self::assertSame($agentRecordedRunSession->transportRecording, ArgusApiOperations::$transportRecording);
            self::assertSame($agentRecordedRunSession->toolResultRecording, MCPToolExecutorService::$toolResultRecording);
            self::assertTrue(AgentToolCallForkJoinService::$forkingDisabled);
            self::assertTrue(ArgusLoad::$deactivateArgusCache);
            self::assertTrue(DDDService::$noCache);
            self::assertTrue(AgentDecisionModelCircuitBreakerService::$harnessMode);
            self::assertSame($agentRecordedRunSession->messageQueue, MessageDispatchCapture::$activeCapture);
            self::assertSame($agentRecordedRunSession, $agentRecordedRunSession->messageQueue->host);
            self::assertNotSame($idSourceBefore, RecordedRunClockAndIdSources::$installedIdSource);
            self::assertSame(16, strlen($agentRecordedRunSession->idSeed), 'a recording draws its seed');
        } finally {
            $agentRecordedRunSession->stop(runRemainingMessages: false);
        }

        self::assertNull(ArgusApiOperations::$transportRecording);
        self::assertNull(MCPToolExecutorService::$toolResultRecording);
        self::assertFalse(AgentToolCallForkJoinService::$forkingDisabled);
        self::assertFalse(ArgusLoad::$deactivateArgusCache);
        self::assertFalse(DDDService::$noCache);
        self::assertFalse(AgentDecisionModelCircuitBreakerService::$harnessMode);
        self::assertNull(MessageDispatchCapture::$activeCapture);
        self::assertSame($idSourceBefore, RecordedRunClockAndIdSources::$installedIdSource, 'the id source of before is back');
        RecordedRunClockAndIdSources::$installedIdSource = null;
    }

    public function testAReplayTakesItsSeedAndItsLiveToolsFromTheRecordingAndTranslatesPlayedAnswers(): void
    {
        $agentRecordedRunRecording = AgentRecordedRunRecording::fromStoredRecording([
            'toolsRunLive' => true,
            'idSeed' => 'seed-of-the-recording',
            'idNormalization' => ['createdIdsByTable' => ['notes' => [11, 12]]],
            'calls' => [],
            'snapshot' => [],
        ]);
        $agentRecordedRunSession = self::newSession($agentRecordedRunRecording, false);
        try {
            $agentRecordedRunSession->start();

            self::assertTrue($agentRecordedRunSession->isReplay());
            self::assertTrue($agentRecordedRunSession->toolsRunLive, 'the recording decides, not the caller');
            self::assertSame('seed-of-the-recording', $agentRecordedRunSession->idSeed);
            self::assertInstanceOf(AgentRecordedRunPlayedAnswerIdTranslation::class, $agentRecordedRunSession->playedAnswerIdTranslation);
            self::assertSame($agentRecordedRunSession, $agentRecordedRunSession->transportRecording?->playedAnswerTranslator);
            self::assertSame('{"text":"no arguments"}', $agentRecordedRunSession->translatePlayedAnswerBody('{"text":"no arguments"}', 1), 'an answer without a candidate is handed over as it is');
        } finally {
            $agentRecordedRunSession->stop(runRemainingMessages: false);
        }
    }

    public function testAReplayWhoseToolResultsArePlayedTranslatesNothing(): void
    {
        $agentRecordedRunSession = self::newSession(AgentRecordedRunRecording::fromStoredRecording(['idNormalization' => ['createdIdsByTable' => ['notes' => [11]]], 'calls' => [], 'snapshot' => []]));
        try {
            $agentRecordedRunSession->start();

            self::assertFalse($agentRecordedRunSession->toolsRunLive);
            self::assertNull($agentRecordedRunSession->playedAnswerIdTranslation);
            self::assertSame('', $agentRecordedRunSession->idSeed, 'a recording without a seed mints random ids');
        } finally {
            $agentRecordedRunSession->stop(runRemainingMessages: false);
        }
    }

    public function testTheHostOfOneQueueCallRestoresTheAccountAndTheGrantOfTheHarness(): void
    {
        $harnessAccount = Account::newInstance();
        $harnessAccount->id = 3;
        $processBudgetBypassGrant = AIBudgetBypassGrant::forReason(AIBudgetBypassGrant::REASON_CONSOLE_OPTION);
        $agentRecordedRunQueuedMessagesHost = AgentRecordedRunQueuedMessagesHost::forSession(self::newSession(), $harnessAccount, $processBudgetBypassGrant);
        $otherAccount = Account::newInstance();
        $otherAccount->id = 4;
        AuthService::instance()->setAccount($otherAccount);

        $agentRecordedRunQueuedMessagesHost->restoreStateAfterQueuedMessage();

        self::assertSame($harnessAccount, AuthService::instance()->getAccount());
        /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
        $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
        self::assertSame($processBudgetBypassGrant, $aIUsageEnvelopesService->getBudgetBypassGrantForTheProcess());
        $aIUsageEnvelopesService->reset();
    }

    public function testTheRunIsDescribedInOneLineAndTheSharedTablesInAnother(): void
    {
        $agentRecordedRunSession = self::newSession();
        $agentRecordedRunSession->setScope(AgentRecordedRunScope::forScopeIds(['agentSpace' => 7, 'account' => 3], ['account', 'agentSpace']));
        $rootConversation = AIConversation::newInstance();
        $rootConversation->id = 501;
        $agentRecordedRunSession->setRootConversation($rootConversation);

        self::assertSame(501, $agentRecordedRunSession->scope->rootConversationId);
        self::assertNull($agentRecordedRunSession->describeSharedScopeTables(), 'nothing read yet');
        $agentRecordedRunSession->createdIdRead = AgentRecordedRunCreatedIdRead::fromStoredRead([
            'sharedScopeTables' => $agentRecordedRunSession->findSharedScopeTables(['conversationMessages' => 'aiConversationId', 'accountNotes' => 'accountId', 'spaceNotes' => 'agentSpaceId']),
        ]);
        self::assertSame('record/replay alone on the account — account-scoped tables: accountNotes (accountId), spaceNotes (agentSpaceId)', $agentRecordedRunSession->describeSharedScopeTables());
        self::assertSame(
            '0 Argus calls, 2 conversations, 0 dispatches',
            $agentRecordedRunSession->describeRun(AgentRecordedRunSnapshot::fromRowsBySection(['conversations' => [[], []], 'dispatches' => []]))
        );
    }
}
