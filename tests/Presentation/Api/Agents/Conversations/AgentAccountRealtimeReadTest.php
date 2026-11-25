<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Api\Agents\Conversations;

use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeAudience;
use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeMessage;
use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeMessageDelivery;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AgentAccountRealtimeMessagesGetRequestDto;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Realtime\DemoRealtimeMessageTypeProviderDouble;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Realtime\RecordingTransportChannelServiceDouble;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Realtime\SharedCacheWithCountersDouble;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The account-level realtime read (`GET …/realtimeMessages`): a client that shows no conversation reads the messages
 * addressed to the signed-in account — not a conversation's, not another account's, no expired one, a message for one
 * client only by that client — and the listener credential without a conversation.
 */
class AgentAccountRealtimeReadTest extends UnitTestCase
{
    protected ?Account $accountBefore = null;

    protected RecordingTransportChannelServiceDouble $channel;

    protected AgentAccountRealtimeReadFixtureController $fixtureController;

    protected function setUp(): void
    {
        $this->accountBefore = AuthService::instance()->getAccount();
        $this->channel = new RecordingTransportChannelServiceDouble(new SharedCacheWithCountersDouble());
        $this->fixtureController = new AgentAccountRealtimeReadFixtureController($this->channel);
        $signedInAccount = new Account();
        $signedInAccount->id = 3;
        AuthService::instance()->setAccount($signedInAccount);
    }

    protected function tearDown(): void
    {
        AuthService::instance()->setAccount($this->accountBefore);
    }

    public function testTheReadReturnsTheAccountsMessagesNotAConversationsNorAnotherAccounts(): void
    {
        $this->channel->sendMessage(static::buildRing(AgentRealtimeAudience::forAccount(3), 'ring-of-3'));
        $this->channel->sendMessage(static::buildRing(AgentRealtimeAudience::forConversation(3, 70), 'conversation-message'));
        $this->channel->sendMessage(static::buildRing(AgentRealtimeAudience::forAccount(4), 'ring-of-4'));

        $responseDto = $this->fixtureController->callBuildAccountRealtimeMessagesResponse(static::buildRequest('client-a'));

        self::assertSame(['ring-of-3'], static::keysOf($responseDto->realtimeMessages->getElements()));
    }

    public function testAnExpiredMessageIsLeftOutAndATargetedOneReachesOnlyItsClient(): void
    {
        $accountAudience = AgentRealtimeAudience::forAccount(3);
        $this->channel->sendMessage(AgentRealtimeMessage::create(DemoRealtimeMessageTypeProviderDouble::MESSAGE_TYPE_RING, 'expired', '{}', (new DateTime())->modify('-1 second'), $accountAudience));
        $this->channel->sendMessage(static::buildRing($accountAudience, 'to-a', 'client-a'));
        $this->channel->sendMessage(static::buildRing($accountAudience, 'to-every-client'));

        self::assertSame(['to-a', 'to-every-client'], static::keysOf($this->fixtureController->callBuildAccountRealtimeMessagesResponse(static::buildRequest('client-a'))->realtimeMessages->getElements()));
        self::assertSame(['to-every-client'], static::keysOf($this->fixtureController->callBuildAccountRealtimeMessagesResponse(static::buildRequest('client-b'))->realtimeMessages->getElements()));
        self::assertSame(['to-every-client'], static::keysOf($this->fixtureController->callBuildAccountRealtimeMessagesResponse(static::buildRequest(null))->realtimeMessages->getElements()));
    }

    public function testTheListenerCredentialIsIssuedWithoutAConversation(): void
    {
        $responseDto = $this->fixtureController->callBuildAccountRealtimeMessagesResponse(static::buildRequest(null));
        self::assertSame('token-of-3', $responseDto->realtimeListenerCredential?->credential);

        $this->channel->hasTransport = false;
        self::assertNull($this->fixtureController->callBuildAccountRealtimeMessagesResponse(static::buildRequest(null))->realtimeListenerCredential);
    }

    public function testWithoutASignedInAccountTheReadIsRefused(): void
    {
        $noAccount = null;
        AuthService::instance()->setAccount($noAccount);

        $this->expectException(ForbiddenException::class);
        $this->fixtureController->callBuildAccountRealtimeMessagesResponse(static::buildRequest('client-a'));
    }

    protected static function buildRequest(?string $clientId): AgentAccountRealtimeMessagesGetRequestDto
    {
        $requestDto = new AgentAccountRealtimeMessagesGetRequestDto();
        $requestDto->clientId = $clientId;
        return $requestDto;
    }

    protected static function buildRing(AgentRealtimeAudience $audience, string $messageKey, ?string $targetClientId = null): AgentRealtimeMessage
    {
        return AgentRealtimeMessage::create(DemoRealtimeMessageTypeProviderDouble::MESSAGE_TYPE_RING, $messageKey, '{}', (new DateTime())->modify('+30 seconds'), $audience, $targetClientId);
    }

    /**
     * @param AgentRealtimeMessageDelivery[] $deliveries
     * @return string[]
     */
    protected static function keysOf(array $deliveries): array
    {
        return array_map(static fn(AgentRealtimeMessageDelivery $agentRealtimeMessageDelivery) => $agentRealtimeMessageDelivery->messageKey, $deliveries);
    }
}
