<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Ring;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeAudience;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Services\Conversations\Realtime\AgentRealtimeChannelService;
use DDD\Domain\AI\Services\Conversations\TraceEvents\AIConversationTraceEventsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceRingService;
use DDD\Domain\AI\Services\Voice\AgentVoiceRealtimeMessageTypeProviderService;
use DDD\Domain\AI\Services\Voice\Calls\AgentVoiceCallEntityStoreService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Voice\Support\AgentVoiceIntegrationTestCase;

/**
 * The staging of the ring and the call start: the package's own services from the kernel, with doubles behind
 * - the call store (`entity` binding, {@see InMemoryAgentVoiceCallStoreDouble}),
 * - the calls service's writes into a conversation ({@see RecordingAgentVoiceCallsServiceDouble}),
 * - the realtime port of the package (its fake transport with the voice ring type,
 *   {@see VoiceRingRealtimeChannelServiceDouble}, over an in-memory shared cache, {@see VoiceRingSharedCacheDouble}),
 * - the trace event writes ({@see RecordingTraceEventsServiceDouble}), the message bus ({@see RecordingMessageBusDouble})
 *   and the logger ({@see RecordingLoggerDouble}): a test whose code path logged a database error fails.
 * The voice channel is enabled with a ring of 90 seconds. Every time is relative to `time()` read in the test.
 */
abstract class AgentVoiceRingStagingTestCase extends AgentVoiceIntegrationTestCase
{
    public const int OWNER_ACCOUNT_ID = 41;
    public const int OTHER_ACCOUNT_ID = 42;
    public const int RING_SECONDS = 90;

    protected InMemoryAgentVoiceCallStoreDouble $callStore;
    protected RecordingAgentVoiceCallsServiceDouble $callsService;
    protected VoiceRingRealtimeChannelServiceDouble $realtimeChannel;
    protected VoiceRingSharedCacheDouble $sharedCache;
    protected RecordingTraceEventsServiceDouble $traceEventsService;
    protected RecordingMessageBusDouble $messageBus;
    protected RecordingLoggerDouble $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith([
            'enabled' => true,
            'callStore' => 'entity',
            'limits' => ['ringSeconds' => static::RING_SECONDS],
        ]));
        $this->callStore = new InMemoryAgentVoiceCallStoreDouble();
        $this->replaceService(AgentVoiceCallEntityStoreService::class, $this->callStore);
        $this->callsService = new RecordingAgentVoiceCallsServiceDouble();
        $this->callsService->accountsById = [
            static::OWNER_ACCOUNT_ID => static::buildAccount(static::OWNER_ACCOUNT_ID),
            static::OTHER_ACCOUNT_ID => static::buildAccount(static::OTHER_ACCOUNT_ID),
        ];
        $this->replaceService(AgentVoiceCallsService::class, $this->callsService);
        $this->sharedCache = new VoiceRingSharedCacheDouble();
        $this->realtimeChannel = new VoiceRingRealtimeChannelServiceDouble($this->sharedCache);
        $this->replaceService(AgentRealtimeChannelService::class, $this->realtimeChannel);
        $this->traceEventsService = new RecordingTraceEventsServiceDouble();
        $this->replaceService(AIConversationTraceEventsService::class, $this->traceEventsService);
        $this->messageBus = new RecordingMessageBusDouble();
        static::getContainer()->set('messenger.default_bus', $this->messageBus);
        $this->logger = new RecordingLoggerDouble();
        static::getContainer()->set('logger', $this->logger);
    }

    protected function assertPostConditions(): void
    {
        static::assertSame([], $this->logger->findDatabaseErrorRecords(), 'no code path of the test reached the database');
        parent::assertPostConditions();
    }

    protected function getRingService(): AgentVoiceRingService
    {
        /** @var AgentVoiceRingService $agentVoiceRingService */
        $agentVoiceRingService = DDDService::instance()->getService(AgentVoiceRingService::class);
        return $agentVoiceRingService;
    }

    /** A stored ringing call of the owner whose deadline is `$ringDeadlineOffsetSeconds` from `$nowUnixTime`. */
    protected function storeRingingCall(int $callId, int $nowUnixTime, int $ringDeadlineOffsetSeconds, ?int $conversationId = 300, int $accountId = self::OWNER_ACCOUNT_ID): AgentVoiceCall
    {
        $agentVoiceCall = new AgentVoiceCall();
        $agentVoiceCall->id = $callId;
        $agentVoiceCall->accountId = $accountId;
        $agentVoiceCall->direction = AgentVoiceCall::DIRECTION_OUTBOUND;
        $agentVoiceCall->aiConversationId = $conversationId;
        $agentVoiceCall->ringDeadlineAt = DateTime::fromTimestamp($nowUnixTime + $ringDeadlineOffsetSeconds);
        return $this->callStore->storeCall($agentVoiceCall);
    }

    /** The ring messages the owner's account mailbox holds now, by key. @return array<string, array<string, mixed>> */
    protected function findRingPayloadsInMailbox(int $accountId = self::OWNER_ACCOUNT_ID): array
    {
        $ringPayloadsByKey = [];
        $accountAudience = AgentRealtimeAudience::forAccount($accountId);
        foreach ($this->realtimeChannel->findOpenMessages($accountAudience, AgentVoiceRealtimeMessageTypeProviderService::MESSAGE_TYPE_RING)->getElements() as $ringMessage) {
            $ringPayloadsByKey[$ringMessage->messageKey] = json_decode($ringMessage->payloadJson, true);
        }
        return $ringPayloadsByKey;
    }

    protected static function buildAccount(int $accountId): Account
    {
        $account = Account::newInstance();
        $account->id = $accountId;
        return $account;
    }

    protected static function buildConversation(int $conversationId, ?int $accountId = self::OWNER_ACCOUNT_ID): AIConversation
    {
        $aiConversation = AIConversation::newInstance();
        $aiConversation->id = $conversationId;
        $aiConversation->accountId = $accountId;
        return $aiConversation;
    }
}
