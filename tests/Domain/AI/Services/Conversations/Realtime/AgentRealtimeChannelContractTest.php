<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Realtime;

use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeAudience;
use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeMessage;
use DDD\Domain\AI\Services\ClientTools\AgentUIRealtimeMessageTypeProviderService;
use DDD\Domain\AI\Services\Conversations\Realtime\AgentRealtimeChannelService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Cache\Apc;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The contract of the realtime port, with a fake transport bound as a host binds its own: (a) send a typed message,
 * (b) clear or replace it by key, (c) issue a listener credential, (d) answer whether a client listens. The mailbox in
 * the shared cache keeps every message, with or without a transport; the run-state read delivers it.
 */
class AgentRealtimeChannelContractTest extends UnitTestCase
{
    public function testASentMessageGoesToTheTransportAndIntoTheMailbox(): void
    {
        $sharedCache = new SharedCacheWithCountersDouble();
        $channel = new RecordingTransportChannelServiceDouble($sharedCache);
        $audience = AgentRealtimeAudience::forConversation(3, 70);

        $sendResult = $channel->sendMessage(static::buildRing($audience, 'call-1', 'client-a'));

        self::assertTrue($sendResult->sentThroughTransport);
        self::assertTrue($sendResult->keptInMailbox);
        self::assertCount(1, $channel->publishedMessages);
        self::assertSame(['call-1'], static::keysOf($channel->findOpenMessages($audience)->getElements()));
        self::assertSame([], $sharedCache->lockTokensByKey, 'the mailbox lock is released');
    }

    public function testTheSameTypeAndKeyReplacesAndAClearRemovesFromTransportAndMailbox(): void
    {
        $channel = new RecordingTransportChannelServiceDouble(new SharedCacheWithCountersDouble());
        $audience = AgentRealtimeAudience::forConversation(3, 70);
        $channel->sendMessage(static::buildRing($audience, 'call-1', null, '{"n":1}'));
        $channel->sendMessage(static::buildRing($audience, 'call-1', null, '{"n":2}'));
        $channel->sendMessage(static::buildRing($audience, 'call-2'));

        $openMessages = $channel->findOpenMessages($audience)->getElements();
        self::assertSame(['call-1', 'call-2'], static::keysOf($openMessages));
        self::assertSame('{"n":2}', $openMessages[0]->payloadJson, 'replaced, not added');

        $channel->clearMessage($audience, DemoRealtimeMessageTypeProviderDouble::MESSAGE_TYPE_RING, 'call-1');
        self::assertSame([DemoRealtimeMessageTypeProviderDouble::MESSAGE_TYPE_RING . '|call-1'], $channel->clearedMessages);
        self::assertSame(['call-2'], static::keysOf($channel->findOpenMessages($audience)->getElements()));
    }

    public function testADeliveryReachesOnlyTheAddressedClientAndAnAccountMessageEveryClientOfTheAccount(): void
    {
        $channel = new RecordingTransportChannelServiceDouble(new SharedCacheWithCountersDouble());
        $conversationAudience = AgentRealtimeAudience::forConversation(3, 70);
        $channel->sendMessage(static::buildRing($conversationAudience, 'to-a', 'client-a'));
        $channel->sendMessage(static::buildRing(AgentRealtimeAudience::forAccount(3), 'to-account'));

        self::assertSame(['to-a', 'to-account'], static::keysOf($channel->findDeliveriesForClient($conversationAudience, 'client-a')->getElements()));
        self::assertSame(['to-account'], static::keysOf($channel->findDeliveriesForClient($conversationAudience, 'client-b')->getElements()));
        self::assertSame([], static::keysOf($channel->findDeliveriesForClient(AgentRealtimeAudience::forAccount(4), 'client-a')->getElements()), 'another account reads nothing');
    }

    public function testAnExpiredMessageIsNotDelivered(): void
    {
        $channel = new RecordingTransportChannelServiceDouble(new SharedCacheWithCountersDouble());
        $audience = AgentRealtimeAudience::forAccount(3);
        $channel->sendMessage(AgentRealtimeMessage::create(DemoRealtimeMessageTypeProviderDouble::MESSAGE_TYPE_RING, 'old', '{}', (new DateTime())->modify('-1 second'), $audience));

        self::assertSame([], static::keysOf($channel->findOpenMessages($audience)->getElements()));
    }

    public function testAnUnregisteredTypeABadKeyAndAnOversizedPayloadAreRefused(): void
    {
        $channel = new RecordingTransportChannelServiceDouble(new SharedCacheWithCountersDouble());
        $audience = AgentRealtimeAudience::forAccount(3);
        self::assertSame(['agent.ui.clientToolRequest', 'demo.voice.ring'], array_map(
            static fn($agentRealtimeMessageType) => $agentRealtimeMessageType->name,
            $channel->getRegisteredMessageTypes()->getElements()
        ), 'an invalid name is skipped, a second registration too');

        foreach ([
            AgentRealtimeMessage::create('demo.voice.unknown', 'k', '{}', (new DateTime())->modify('+30 seconds'), $audience),
            AgentRealtimeMessage::create(DemoRealtimeMessageTypeProviderDouble::MESSAGE_TYPE_RING, 'no spaces', '{}', (new DateTime())->modify('+30 seconds'), $audience),
            AgentRealtimeMessage::create(DemoRealtimeMessageTypeProviderDouble::MESSAGE_TYPE_RING, 'k', '[1,2]', (new DateTime())->modify('+30 seconds'), $audience),
            AgentRealtimeMessage::create(DemoRealtimeMessageTypeProviderDouble::MESSAGE_TYPE_RING, 'k', '{"text":"' . str_repeat('x', 300) . '"}', (new DateTime())->modify('+30 seconds'), $audience),
        ] as $refusedMessage) {
            try {
                $channel->sendMessage($refusedMessage);
                self::fail('refused: ' . $refusedMessage->messageKey);
            } catch (BadRequestException) {
                self::assertSame([], $channel->publishedMessages);
            }
        }
    }

    public function testAFailingTransportStillKeepsTheMessageInTheMailbox(): void
    {
        $channel = new RecordingTransportChannelServiceDouble(new SharedCacheWithCountersDouble());
        $channel->transportFails = true;
        $audience = AgentRealtimeAudience::forAccount(3);

        $sendResult = $channel->sendMessage(static::buildRing($audience, 'call-1'));

        self::assertFalse($sendResult->sentThroughTransport);
        self::assertTrue($sendResult->keptInMailbox);
        self::assertTrue($sendResult->isReachable());
    }

    public function testTheTransportIssuesTheCredentialAndAnswersPresence(): void
    {
        $channel = new RecordingTransportChannelServiceDouble(new SharedCacheWithCountersDouble());
        $credential = $channel->findListenerCredential(3, 70);
        self::assertSame('token-of-3', $credential?->credential);

        $audience = AgentRealtimeAudience::forConversation(3, 70);
        self::assertFalse($channel->isClientListening($audience));
        $channel->listeningByMailboxName[$audience->getMailboxName()] = true;
        self::assertTrue($channel->isClientListening($audience));
    }

    public function testWithoutATransportTheMailboxAloneDeliversAndNoCredentialIsIssued(): void
    {
        $channel = new RecordingTransportChannelServiceDouble(new SharedCacheWithCountersDouble());
        $channel->hasTransport = false;
        $audience = AgentRealtimeAudience::forConversation(3, 70);

        $sendResult = $channel->sendMessage(static::buildRing($audience, 'call-1'));

        self::assertFalse($sendResult->sentThroughTransport);
        self::assertTrue($sendResult->keptInMailbox);
        self::assertNull($channel->findListenerCredential(3, 70));
        self::assertSame(['call-1'], static::keysOf($channel->findDeliveriesForClient($audience, 'client-a')->getElements()));
    }

    public function testAProcessLocalCacheIsNoSharedCache(): void
    {
        $channel = new RecordingTransportChannelServiceDouble(new Apc([]));
        self::assertNull($channel->getSharedCache());
        self::assertStringContainsString('local to the process', (string)$channel->findSharedCacheProblem());
        self::assertFalse($channel->sendMessage(static::buildRing(AgentRealtimeAudience::forAccount(3), 'call-1'))->keptInMailbox);
        self::assertStringContainsString('no shared cache', (string)(new RecordingTransportChannelServiceDouble(null))->findSharedCacheProblem());
    }

    public function testThePackageRegistersTheClientToolRequestType(): void
    {
        self::assertSame('agent.ui.clientToolRequest', AgentUIRealtimeMessageTypeProviderService::MESSAGE_TYPE_CLIENT_TOOL_REQUEST);
        self::assertTrue(is_subclass_of(RecordingTransportChannelServiceDouble::class, AgentRealtimeChannelService::class));
    }

    protected static function buildRing(AgentRealtimeAudience $audience, string $messageKey, ?string $targetClientId = null, string $payloadJson = '{}'): AgentRealtimeMessage
    {
        return AgentRealtimeMessage::create(DemoRealtimeMessageTypeProviderDouble::MESSAGE_TYPE_RING, $messageKey, $payloadJson, (new DateTime())->modify('+30 seconds'), $audience, $targetClientId);
    }

    /**
     * @param object[] $messages
     * @return string[]
     */
    protected static function keysOf(array $messages): array
    {
        return array_map(static fn($message) => $message->messageKey, $messages);
    }
}
