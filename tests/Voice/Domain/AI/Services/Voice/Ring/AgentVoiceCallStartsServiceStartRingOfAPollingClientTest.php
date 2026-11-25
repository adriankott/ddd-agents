<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Ring;

use DDD\Domain\AI\Entities\ClientTools\AgentClientPresence;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallReachableChannels;
use DDD\Domain\AI\Services\ClientTools\AgentClientPresenceService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AgentAccountRealtimeMessagesGetRequestDto;
use DDD\Tests\Agents\Presentation\Api\Agents\Conversations\AgentAccountRealtimeReadFixtureController;

/**
 * A ring started while only a polling client of the owner is open, WITHOUT a bound transport: the client's
 * account-level realtime read with its client id states the presence under the key of its own
 * (`agent-client-presence-without-conversation-<accountId>`), so the owner is reachable
 * ({@see \DDD\Domain\AI\Services\Voice\AgentVoiceRingService::isAnyClientOfAccountListening()}, the app ring
 * `APP_RING_RINGS_NOW`) and {@see \DDD\Domain\AI\Services\Voice\AgentVoiceCallStartsService::startRing()} creates the
 * call. Once the statement is 26 seconds old, or when the read stated no client id, the ring is refused with
 * `no_registered_devices` and no call is created.
 *
 * The code reads the clock itself: the age is staged through the stored `lastSeenAt` (26 s is exact: a second that
 * turns between staging and the start only makes the statement older).
 */
class AgentVoiceCallStartsServiceStartRingOfAPollingClientTest extends AgentVoiceCallStartsStagingTestCase
{
    public const string POLLING_CLIENT_ID = 'poll-client-0001';

    protected ?Account $accountBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->realtimeChannel->hasTransport = false;
        $this->realtimeChannel->listeningByMailboxName = [];
        $this->accountBefore = AuthService::instance()->getAccount();
        $signedInAccount = static::buildAccount(static::OWNER_ACCOUNT_ID);
        AuthService::instance()->setAccount($signedInAccount);
    }

    protected function onTearDown(): void
    {
        AuthService::instance()->setAccount($this->accountBefore);
        parent::onTearDown();
    }

    public static function getStatementKey(): string
    {
        return AgentClientPresenceService::ACCOUNT_WITHOUT_CONVERSATION_KEY_PREFIX . static::OWNER_ACCOUNT_ID;
    }

    public function testAPollingClientWithItsIdMakesTheOwnerReachableAndTheRingCreatesTheCall(): void
    {
        $this->readTheAccountMailbox(static::POLLING_CLIENT_ID);

        static::assertSame(0, $this->sharedCache->valuesByKey[static::getStatementKey()]['conversationId']);
        static::assertTrue($this->getRingService()->isAnyClientOfAccountListening(static::OWNER_ACCOUNT_ID));
        static::assertSame(AgentVoiceCallReachableChannels::APP_RING_RINGS_NOW, $this->findAppRingValueForOwner());

        $agentVoiceCall = $this->getCallStartsService()->startRing(static::buildAccount(static::OWNER_ACCOUNT_ID), null, static::buildConversation(300));

        static::assertSame(501, $agentVoiceCall->id);
        static::assertSame(AgentVoiceCall::DIRECTION_OUTBOUND, $agentVoiceCall->direction);
        static::assertNull($agentVoiceCall->endedAt);
        static::assertContains('createCall', $this->callStore->getRecordedMethodNames());
        static::assertSame([501], array_keys($this->findRingPayloadsInMailbox()), 'the polling client reads the ring from the mailbox');
    }

    public function testOnceTheStatementIsTwentySixSecondsOldTheRingIsRefusedAgain(): void
    {
        $this->readTheAccountMailbox(static::POLLING_CLIENT_ID);
        $this->sharedCache->valuesByKey[static::getStatementKey()]['lastSeenAt'] -= AgentClientPresence::FRESH_FOR_SECONDS + 1;

        static::assertFalse($this->getRingService()->isAnyClientOfAccountListening(static::OWNER_ACCOUNT_ID));
        static::assertSame(AgentVoiceCallReachableChannels::APP_RING_NOT_RINGABLE_NOW, $this->findAppRingValueForOwner());
        $this->assertTheRingIsRefusedWithoutACall();
    }

    public function testAPollingClientWithoutAnIdIsStillRefused(): void
    {
        $this->readTheAccountMailbox(null);

        static::assertArrayNotHasKey(static::getStatementKey(), $this->sharedCache->valuesByKey);
        static::assertFalse($this->getRingService()->isAnyClientOfAccountListening(static::OWNER_ACCOUNT_ID));
        $this->assertTheRingIsRefusedWithoutACall();
    }

    protected function assertTheRingIsRefusedWithoutACall(): void
    {
        try {
            $this->getCallStartsService()->startRing(static::buildAccount(static::OWNER_ACCOUNT_ID), null, static::buildConversation(300));
            static::fail('no client can take the ring');
        } catch (BadRequestException $badRequestException) {
            static::assertSame('no_registered_devices', $badRequestException->getMessage());
        }
        static::assertNotContains('createCall', $this->callStore->getRecordedMethodNames());
        static::assertSame([], $this->messageBus->dispatchedMessages);
    }

    protected function readTheAccountMailbox(?string $clientId): void
    {
        $accountReadRequestDto = new AgentAccountRealtimeMessagesGetRequestDto();
        $accountReadRequestDto->clientId = $clientId;
        (new AgentAccountRealtimeReadFixtureController($this->realtimeChannel))->callBuildAccountRealtimeMessagesResponse($accountReadRequestDto);
    }

    protected function findAppRingValueForOwner(): string
    {
        return $this->getCallStartsService()->findReachableChannelsForOwner(static::buildAccount(static::OWNER_ACCOUNT_ID), null, null)->getAppRingValue();
    }
}
