<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\Common\Services\AccountsService;
use DDD\Infrastructure\Cache\Cache;
use DDD\Tests\Agents\Voice\Support\AgentVoiceCallFixtures;
use DDD\Tests\Agents\Voice\Support\AgentVoiceIntegrationTestCase;
use React\EventLoop\Loop;
use ReflectionProperty;

require_once __DIR__ . '/SessionWorkerSleep.functions.php';

/**
 * The stage of every session worker test. Per test:
 * - a {@see ScriptedEventLoop} is the global event loop (`Loop::get()`), the loop of before is put back afterwards;
 * - a {@see LeaseAndCounterCacheDouble} is the shared cache group `redis` of the framework's instance registry;
 * - the worker's `sleep()` is recorded instead of waited ({@see RecordedSessionWorkerSleeps});
 * - the worker's conversation reads ({@see StagedAgentLiveVoiceSessionsService::getAIConversationsService()}) answer a
 *   {@see FixedAIConversationsService}; the same double and a {@see FixedAccountsService} stand in the container for the
 *   admission, which reads the conversation and the billed account of the session's model call;
 *   a test that ends with a database error in the voice log fails ({@see onTearDown()});
 * - the SIGTERM handler of the run is put back (the drain mode of a call replaces it).
 *
 * The call is window call 7 on session `live_session_seven`, answered a minute before the fixed "now" of the fixtures,
 * for account 5 on conversation 41.
 */
abstract class AgentLiveVoiceSessionWorkerTestCase extends AgentVoiceIntegrationTestCase
{
    public const string PROVIDER_SESSION_ID = 'live_session_seven';

    public const int CALL_ID = 7;

    public const int ACCOUNT_ID = 5;

    public const int CONVERSATION_ID = 41;

    protected ScriptedEventLoop $scriptedEventLoop;

    protected LeaseAndCounterCacheDouble $leaseAndCounterCache;

    protected FixedAccountsService $fixedAccountsService;

    protected FixedAIConversationsService $fixedAIConversationsService;

    /** @var StagedAgentLiveVoiceSessionsService[] The workers of this test */
    protected array $workersOfTheTest = [];

    protected mixed $eventLoopBeforeTheTest = null;

    protected bool $eventLoopStoppedBeforeTheTest = false;

    /** @var array<string, Cache>|null */
    protected ?array $cacheInstancesBeforeTheTest = null;

    protected mixed $terminationSignalHandlerBeforeTheTest = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eventLoopBeforeTheTest = (new ReflectionProperty(Loop::class, 'instance'))->getValue();
        $this->eventLoopStoppedBeforeTheTest = (bool)(new ReflectionProperty(Loop::class, 'stopped'))->getValue();
        $this->scriptedEventLoop = new ScriptedEventLoop();
        Loop::set($this->scriptedEventLoop);

        $cacheInstances = new ReflectionProperty(Cache::class, 'instances');
        $this->cacheInstancesBeforeTheTest = $cacheInstances->getValue();
        $this->leaseAndCounterCache = new LeaseAndCounterCacheDouble();
        $cacheInstances->setValue(null, [...($this->cacheInstancesBeforeTheTest ?? []), Cache::CACHE_GROUP_REDIS => $this->leaseAndCounterCache]);

        RecordedSessionWorkerSleeps::startRecording();
        $this->terminationSignalHandlerBeforeTheTest = pcntl_signal_get_handler(SIGTERM);

        $this->fixedAccountsService = new FixedAccountsService();
        $this->fixedAccountsService->accountsById[static::ACCOUNT_ID] = FixedAccountsService::buildAccount(static::ACCOUNT_ID);
        StaticRegistry::$entityServices = [];
        $this->replaceService(AccountsService::class, $this->fixedAccountsService);
        $this->fixedAIConversationsService = new FixedAIConversationsService();
        $this->fixedAIConversationsService->conversationsById[static::CONVERSATION_ID] = static::buildConversation();
        $this->replaceService(AIConversationsService::class, $this->fixedAIConversationsService);
    }

    protected function onTearDown(): void
    {
        foreach ($this->workersOfTheTest as $workerOfTheTest) {
            static::assertSame([], $workerOfTheTest->findVoiceLogLinesContaining('SQLSTATE'), 'a worker of this test reached for the database');
        }
        $this->workersOfTheTest = [];
        parent::onTearDown();
        RecordedSessionWorkerSleeps::stopRecording();
        (new ReflectionProperty(Cache::class, 'instances'))->setValue(null, $this->cacheInstancesBeforeTheTest);
        (new ReflectionProperty(Loop::class, 'instance'))->setValue(null, $this->eventLoopBeforeTheTest);
        (new ReflectionProperty(Loop::class, 'stopped'))->setValue(null, $this->eventLoopStoppedBeforeTheTest);
        pcntl_signal(SIGTERM, $this->terminationSignalHandlerBeforeTheTest);
    }

    protected function buildWorker(): StagedAgentLiveVoiceSessionsService
    {
        $worker = new StagedAgentLiveVoiceSessionsService();
        $worker->aIConversationsService = $this->fixedAIConversationsService;

        return $this->workersOfTheTest[] = $worker;
    }

    /** The running window call of every test: answered a minute ago, not ended. */
    protected static function buildRunningCall(?int $conversationId = self::CONVERSATION_ID, ?int $accountId = self::ACCOUNT_ID): AgentVoiceCall
    {
        $agentVoiceCall = AgentVoiceCallFixtures::buildCallAt(AgentVoiceCallFixtures::NOW_UNIX_TIME, createdOffsetSeconds: -70, startedOffsetSeconds: -60);
        $agentVoiceCall->id = static::CALL_ID;
        $agentVoiceCall->providerCallId = static::PROVIDER_SESSION_ID;
        $agentVoiceCall->voiceProvider = 'openAiLive';
        $agentVoiceCall->accountId = $accountId;
        $agentVoiceCall->aiConversationId = $conversationId;

        return $agentVoiceCall;
    }

    /** The call's conversation as the worker holds it after its one read; `$accountId` null = a conversation of no account. */
    protected static function buildConversation(
        int $conversationId = self::CONVERSATION_ID,
        ?int $accountId = self::ACCOUNT_ID,
        ?int $rootConversationId = null,
        string $runState = AIConversationRunState::IDLE
    ): AIConversation {
        $aiConversation = AIConversation::newInstance();
        $aiConversation->id = $conversationId;
        $aiConversation->accountId = $accountId;
        $aiConversation->rootConversationId = $rootConversationId;
        $aiConversation->runState = $runState;

        return $aiConversation;
    }

    /** A worker that already holds the call's conversation (its one read happened). */
    protected function buildWorkerWithConversation(?AIConversation $aiConversation = null): StagedAgentLiveVoiceSessionsService
    {
        $aiConversation ??= $this->fixedAIConversationsService->conversationsById[static::CONVERSATION_ID];
        $this->fixedAIConversationsService->conversationsById[(int)$aiConversation->id] = $aiConversation;
        $worker = $this->buildWorker();
        $worker->setWorkerState('attachedAiConversation', $aiConversation);

        return $worker;
    }

    /** A transcript delta event of the provider. */
    protected static function buildTranscriptDelta(string $role, string $deltaText, int $startMs, int $endMs): array
    {
        return [
            'type' => $role === 'user' ? 'session.input_transcript.delta' : 'session.output_transcript.delta',
            'delta' => $deltaText,
            'start_ms' => $startMs,
            'end_ms' => $endMs,
        ];
    }

    /** @return AIConversationTraceEvent[] The events of one type still in the worker's buffer */
    protected static function findBufferedTraceEvents(StagedAgentLiveVoiceSessionsService $worker, string $eventType): array
    {
        return array_values(array_filter(
            $worker->getWorkerState('traceEventBuffer'),
            static fn (AIConversationTraceEvent $bufferedTraceEvent): bool => $bufferedTraceEvent->eventType === $eventType
        ));
    }

    /** @return AIConversationTraceEvent[] The events of one type, buffered or written */
    protected static function findTraceEvents(StagedAgentLiveVoiceSessionsService $worker, string $eventType): array
    {
        return [
            ...$worker->aIConversationTraceEventsService->findRecordedEventsOfType($eventType),
            ...static::findBufferedTraceEvents($worker, $eventType),
        ];
    }
}
