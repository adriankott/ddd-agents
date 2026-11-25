<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Presentation\Api\Agents\Voice;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Voice\AgentVoiceConfiguration;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceSessionContext;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallAdmissionService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStartsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStoreService;
use DDD\Domain\AI\Services\Voice\AgentVoiceRingService;
use DDD\Domain\AI\Services\Voice\AgentVoiceSessionContextsService;
use DDD\Domain\AI\Services\Voice\SessionWorker\AgentLiveVoiceSessionsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\AuthService;
use DDD\Tests\Agents\Voice\Support\AgentVoiceCallFixtures;
use DDD\Tests\Agents\Voice\Support\AgentVoiceIntegrationTestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * The staging of the voice routes: the voice channel switched on (the admission double answers `canVoiceRunHere()` from
 * the declared switch), account {@see ACCOUNT_ID} signed in, and a
 * {@see AgentVoiceSessionsFixtureController} whose every service is a double of this test (a mock that answers the
 * type's empty value until a test scripts it), the provider a {@see ScriptedSessionVoiceProviderAdapter} and the trace
 * a {@see RecordingConversationTraceEventsService}. No database, no network.
 */
abstract class AgentVoiceSessionsTestCase extends AgentVoiceIntegrationTestCase
{
    public const int ACCOUNT_ID = 12;

    public const int CALL_ID = 7;

    public const int CONVERSATION_ID = 40;

    public const string SDP_OFFER = "v=0\r\no=offer 1 1 IN IP4 127.0.0.1\r\n";

    protected AgentVoiceSessionsFixtureController $fixtureController;

    protected Account $signedInAccount;

    protected AgentVoiceCallsService&MockObject $agentVoiceCallsService;

    protected AgentVoiceCallStoreService&MockObject $agentVoiceCallStoreService;

    protected AgentVoiceCallStartsService&MockObject $agentVoiceCallStartsService;

    protected AgentVoiceCallAdmissionService&MockObject $agentVoiceCallAdmissionService;

    protected AgentVoiceRingService&MockObject $agentVoiceRingService;

    protected AgentVoiceSessionContextsService&MockObject $agentVoiceSessionContextsService;

    protected AgentLiveVoiceSessionsService&MockObject $agentLiveVoiceSessionsService;

    protected AIConversationsService&MockObject $aIConversationsService;

    protected RecordingConversationTraceEventsService $recordingConversationTraceEventsService;

    protected ScriptedSessionVoiceProviderAdapter $scriptedSessionVoiceProviderAdapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['enabled' => true]));
        $this->signedInAccount = new Account();
        $this->signedInAccount->id = static::ACCOUNT_ID;
        AuthService::instance()->setAccount($this->signedInAccount);

        $this->agentVoiceCallsService = $this->createMock(AgentVoiceCallsService::class);
        $this->agentVoiceCallStoreService = $this->createMock(AgentVoiceCallStoreService::class);
        $this->agentVoiceCallStartsService = $this->createMock(AgentVoiceCallStartsService::class);
        $this->agentVoiceCallAdmissionService = $this->createMock(AgentVoiceCallAdmissionService::class);
        $this->agentVoiceRingService = $this->createMock(AgentVoiceRingService::class);
        $this->agentVoiceSessionContextsService = $this->createMock(AgentVoiceSessionContextsService::class);
        $this->agentLiveVoiceSessionsService = $this->createMock(AgentLiveVoiceSessionsService::class);
        $this->aIConversationsService = $this->createMock(AIConversationsService::class);
        $this->recordingConversationTraceEventsService = new RecordingConversationTraceEventsService();
        $this->scriptedSessionVoiceProviderAdapter = new ScriptedSessionVoiceProviderAdapter();

        // the routes ask the admission whether voice can run here; the double answers it from the declared switch,
        // as the service does in an installation with the session worker's library
        $this->agentVoiceCallAdmissionService->method('canVoiceRunHere')->willReturnCallback([$this, 'isVoiceSwitchedOnInTheTest']);
        $this->agentVoiceSessionContextsService->method('buildVoiceSessionContext')->willReturn(AgentVoiceSessionContext::newInstance());
        $this->agentVoiceSessionContextsService->method('buildVoiceSessionContextWithoutConversation')->willReturn(AgentVoiceSessionContext::newInstance());

        $this->fixtureController = new AgentVoiceSessionsFixtureController();
        $this->fixtureController->agentVoiceCallsService = $this->agentVoiceCallsService;
        $this->fixtureController->agentVoiceCallStoreService = $this->agentVoiceCallStoreService;
        $this->fixtureController->agentVoiceCallStartsService = $this->agentVoiceCallStartsService;
        $this->fixtureController->agentVoiceCallAdmissionService = $this->agentVoiceCallAdmissionService;
        $this->fixtureController->agentVoiceRingService = $this->agentVoiceRingService;
        $this->fixtureController->agentVoiceSessionContextsService = $this->agentVoiceSessionContextsService;
        $this->fixtureController->agentLiveVoiceSessionsService = $this->agentLiveVoiceSessionsService;
        $this->fixtureController->aIConversationsService = $this->aIConversationsService;
        $this->fixtureController->aIConversationTraceEventsService = $this->recordingConversationTraceEventsService;
        $this->fixtureController->realtimeVoiceProviderAdapter = $this->scriptedSessionVoiceProviderAdapter;
    }

    /**
     * The admission double is asked nothing, except whether voice can run here (the routes' first gate, which reads
     * no call and no conversation): no refusal, no budget, no daily limit.
     */
    protected function expectTheAdmissionIsAskedNothingButWhetherVoiceCanRunHere(): void
    {
        $this->agentVoiceCallAdmissionService->expects(static::never())
            ->method(static::logicalNot(static::matchesRegularExpression('/^canVoiceRunHere$/i')));
    }

    /** Whether the test declared the voice channel switched on (`AI.agentVoice.enabled`): the answer of the admission double's `canVoiceRunHere()`. */
    public function isVoiceSwitchedOnInTheTest(): bool
    {
        return AgentVoiceConfiguration::current()->enabled;
    }

    /** A call of the signed-in account, with its times given as seconds relative to the fixed "now" of the fixtures. */
    protected static function buildCallOfSignedInAccount(
        ?int $ringDeadlineOffsetSeconds = null,
        ?int $startedOffsetSeconds = null,
        ?int $endedOffsetSeconds = null,
        string $direction = AgentVoiceCall::DIRECTION_INBOUND
    ): AgentVoiceCall {
        $agentVoiceCall = AgentVoiceCallFixtures::buildCallAt(
            AgentVoiceCallFixtures::NOW_UNIX_TIME,
            ringDeadlineOffsetSeconds: $ringDeadlineOffsetSeconds,
            startedOffsetSeconds: $startedOffsetSeconds,
            endedOffsetSeconds: $endedOffsetSeconds
        );
        $agentVoiceCall->id = static::CALL_ID;
        $agentVoiceCall->accountId = static::ACCOUNT_ID;
        $agentVoiceCall->aiConversationId = static::CONVERSATION_ID;
        $agentVoiceCall->direction = $direction;

        return $agentVoiceCall;
    }

    protected static function buildConversationOfSignedInAccount(int $conversationId = self::CONVERSATION_ID): AIConversation
    {
        $aiConversation = new AIConversation();
        $aiConversation->id = $conversationId;
        $aiConversation->accountId = static::ACCOUNT_ID;

        return $aiConversation;
    }

    /** The store answers `$agentVoiceCall` for its own id and the signed-in account, and nothing else. */
    protected function storeAnswersCallOfSignedInAccount(AgentVoiceCall $agentVoiceCall): void
    {
        $this->agentVoiceCallStoreService->method('findCallOfAccount')->willReturnCallback([$this, 'answerCallOfSignedInAccount']);
        $this->callOfSignedInAccount = $agentVoiceCall;
    }

    /** @var AgentVoiceCall|null The call {@see answerCallOfSignedInAccount()} answers */
    protected ?AgentVoiceCall $callOfSignedInAccount = null;

    /** The store's answer to `findCallOfAccount()`: the scripted call when the id and the account are its own. */
    public function answerCallOfSignedInAccount(int $callId, Account $account): ?AgentVoiceCall
    {
        if ($this->callOfSignedInAccount === null || $callId !== (int)$this->callOfSignedInAccount->id || (int)$account->id !== $this->callOfSignedInAccount->accountId) {
            return null;
        }

        return $this->callOfSignedInAccount;
    }

    protected static function buildMoment(int $offsetSeconds): DateTime
    {
        return AgentVoiceCallFixtures::buildMomentAt(AgentVoiceCallFixtures::NOW_UNIX_TIME, $offsetSeconds);
    }
}
