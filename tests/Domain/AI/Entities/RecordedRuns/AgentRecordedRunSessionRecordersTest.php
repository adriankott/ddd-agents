<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\RecordedRuns;

use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunIdNormalization;
use DDD\Domain\AI\Entities\MCPPackages\ToolResult;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunRecording;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunSession;
use DDD\Domain\AI\MessageHandlers\AIConversationScorecardHandler;
use DDD\Domain\AI\MessageHandlers\AIConversationScorecardMessage;
use DDD\Domain\AI\Services\Eval\AgentRecordedRunsService;
use DDD\Domain\Base\Entities\MessageHandlers\MessageDispatchCapture;
use DDD\Domain\Base\Entities\MessageHandlers\RecordedRunMessageQueue;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperations;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use GuzzleHttp\Client;
use Symfony\Component\Messenger\Envelope;

/**
 * The recorders of a session, with the framework's own classes and no database: the service builds the application's
 * session class; an Argus call made under a recording session reaches the (stub) network once and is answered from the
 * recording on replay; a tool result recorded is played back; a captured message runs through the bus in this process,
 * after which the harness's account is back; a capture active before the session is active again after it.
 */
class AgentRecordedRunSessionRecordersTest extends IntegrationTestCase
{
    public const string ENDPOINT_URL = 'https://models.example.test/chat';

    public const string REQUEST_BODY = '{"messages":[{"role":"user","content":"Hello"}]}';

    /** @var array<string, string> The application classes the registry knew before the test */
    protected array $frameworkToAppClassNamesBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->frameworkToAppClassNamesBefore = StaticRegistry::$frameworkToAppClassNames;
        StaticRegistry::$frameworkToAppClassNames[AgentRecordedRunSession::class] = RecordedRunSessionWithStubNetwork::class;
        RecordedRunSessionWithStubNetwork::$stubModelNetworkHandler = new StubModelNetworkHandler();
    }

    protected function onTearDown(): void
    {
        StaticRegistry::$frameworkToAppClassNames = $this->frameworkToAppClassNamesBefore;
        RecordedRunSessionWithStubNetwork::$stubModelNetworkHandler = null;
        MessageDispatchCapture::$activeCapture = null;
        RecordedRunMessageQueue::$runningQueue = null;
    }

    protected function startSession(?AgentRecordedRunRecording $agentRecordedRunRecording): AgentRecordedRunSession
    {
        /** @var AgentRecordedRunsService $agentRecordedRunsService */
        $agentRecordedRunsService = DDDService::instance()->getService(AgentRecordedRunsService::class);
        $agentRecordedRunSession = $agentRecordedRunsService->startSession($agentRecordedRunRecording);
        // the marks are given: start() reads none from the database
        $agentRecordedRunSession->idNormalization = AgentRecordedRunIdNormalization::withHighWaterMarks([], []);
        return $agentRecordedRunSession;
    }

    /** One Argus call through the transport the session installed, as the Argus operations make it. */
    protected function sendOneArgusCall(): string
    {
        $client = new Client(['handler' => ArgusApiOperations::$transportRecording?->buildHandlerStack()]);
        return (string)$client->post(self::ENDPOINT_URL, ['body' => self::REQUEST_BODY])->getBody();
    }

    public function testTheServiceBuildsTheApplicationsSessionClass(): void
    {
        self::assertInstanceOf(RecordedRunSessionWithStubNetwork::class, $this->startSession(null));
    }

    public function testAnArgusCallAndAToolResultAreRecordedAndPlayedBack(): void
    {
        $recordingSession = $this->startSession(null);
        $recordingSession->start();
        try {
            $recordingSession->beginTurn(1);
            self::assertSame(StubModelNetworkHandler::ANSWER_BODY, $this->sendOneArgusCall());
            MCPToolExecutorService::$toolResultRecording?->recordResult('getNotes', ['noteId' => 1], new ToolResult('Note 1: buy coffee'));
        } finally {
            $recordingSession->stop(runRemainingMessages: false);
        }
        self::assertSame(1, RecordedRunSessionWithStubNetwork::$stubModelNetworkHandler?->requestCount);
        $agentRecordedRunRecording = AgentRecordedRunRecording::fromStoredRecording([
            'idSeed' => $recordingSession->idSeed,
            'calls' => $recordingSession->transportRecording?->toStorageArray() ?? [],
            'toolResults' => $recordingSession->toolResultRecording?->toStoredEntries() ?? [],
            'snapshot' => [],
            'idNormalization' => [],
        ]);

        $replaySession = $this->startSession($agentRecordedRunRecording);
        $replaySession->start();
        try {
            $replaySession->beginTurn(1);
            self::assertSame(StubModelNetworkHandler::ANSWER_BODY, $this->sendOneArgusCall(), 'the replay is answered from the recording');
            $playedToolResult = MCPToolExecutorService::$toolResultRecording?->playResult('getNotes', ['noteId' => 1]);
        } finally {
            $replaySession->stop(runRemainingMessages: false);
        }

        self::assertSame(1, RecordedRunSessionWithStubNetwork::$stubModelNetworkHandler?->requestCount, 'the replay reached no network');
        self::assertSame(1, $replaySession->transportRecording?->playedRequests->count());
        self::assertSame([], $replaySession->transportRecording?->unusedEntries());
        self::assertSame('Note 1: buy coffee', $playedToolResult?->content);
        self::assertSame([], $replaySession->toolResultRecording?->unusedEntries());
        self::assertSame($recordingSession->idSeed, $replaySession->idSeed);
    }

    public function testACapturedMessageRunsThroughTheBusAndTheHarnessAccountIsBackAfterIt(): void
    {
        $scorecardHandlerRecordingDouble = new ScorecardHandlerRecordingDouble();
        $this->replaceService(AIConversationScorecardHandler::class, $scorecardHandlerRecordingDouble);
        $harnessAccount = Account::newInstance();
        $harnessAccount->id = 3;
        AuthService::instance()->setAccount($harnessAccount);
        $captureBeforeTheSession = new MessageDispatchCapture();
        $captureBeforeTheSession->startCapturing();
        $agentRecordedRunSession = $this->startSession(null);
        $agentRecordedRunSession->start();
        try {
            self::assertSame($agentRecordedRunSession->messageQueue, MessageDispatchCapture::$activeCapture);
            $agentRecordedRunSession->beginTurn(1);
            // what the capture middleware of the bus does with a dispatched message while the queue captures
            $agentRecordedRunSession->messageQueue->captureEnvelope(new Envelope(new AIConversationScorecardMessage(77)));

            $messagesRun = $agentRecordedRunSession->runQueuedMessages();
        } finally {
            $agentRecordedRunSession->stop(runRemainingMessages: false);
        }

        self::assertSame(1, $messagesRun);
        self::assertSame([77], $scorecardHandlerRecordingDouble->handledConversationIds, 'the handler of the message ran in this process');
        self::assertSame(3, AuthService::instance()->getAccount()?->id, 'the harness account is back after the message');
        $dispatchedMessages = $agentRecordedRunSession->messageQueue->buildDispatchedMessagesWithOutcomes()->getElements();
        self::assertSame(RecordedRunMessageQueue::OUTCOME_HANDLED, $dispatchedMessages[0]->outcome);
        self::assertSame($captureBeforeTheSession, MessageDispatchCapture::$activeCapture, 'the capture active before the session is active again');
        $captureBeforeTheSession->stopCapturing();
    }
}
