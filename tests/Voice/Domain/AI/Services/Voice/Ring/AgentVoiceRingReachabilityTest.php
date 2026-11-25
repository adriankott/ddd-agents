<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Ring;

use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeAudience;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallReachableChannels;
use DDD\Domain\AI\Services\ClientTools\AgentClientPresenceService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallAdmissionService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStartsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AgentAccountRealtimeMessagesGetRequestDto;
use DDD\Tests\Agents\Presentation\Api\Agents\Conversations\AgentAccountRealtimeReadFixtureController;

/**
 * Whether a ring reaches the owner, as the realtime port of the package answers it WITHOUT a bound transport: the
 * port's only account-level presence is a fresh statement of a client of the account
 * ({@see AgentClientPresenceService::findNewestPresenceOfAccount()}: the newest one of a client that shows a
 * conversation, else the one of a client that shows none). A client that shows a conversation states it with the
 * run-state and message reads ({@see AgentClientPresenceService::recordClientStatement()}, key
 * `agent-client-presence-account-<accountId>`); a client that shows none states it with the account-level read
 * (`GET …/realtimeMessages`) when it gives its `clientId`
 * ({@see AgentClientPresenceService::recordClientStatementWithoutConversation()}, a key of its own,
 * `agent-client-presence-without-conversation-<accountId>`); that read also delivers the ring.
 * Pinned per case: (a) a client showing a conversation: reachable; (b) a client that only polls the account read with
 * its client id: reachable, and it receives the ring; (b') the same read without a client id states nothing: not
 * reachable; (c) no client: not reachable. A bound transport that answers for the account audience decides instead.
 */
class AgentVoiceRingReachabilityTest extends AgentVoiceRingStagingTestCase
{
    public const string CLIENT_ID = 'client-aaaa-0001';

    protected ?Account $accountBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->realtimeChannel->hasTransport = false;
        $agentVoiceCallAdmissionService = $this->createMock(AgentVoiceCallAdmissionService::class);
        $agentVoiceCallAdmissionService->method('findRefusalOfCallStart')->willReturn(null);
        $this->replaceService(AgentVoiceCallAdmissionService::class, $agentVoiceCallAdmissionService);
        $this->accountBefore = AuthService::instance()->getAccount();
        $signedInAccount = static::buildAccount(static::OWNER_ACCOUNT_ID);
        AuthService::instance()->setAccount($signedInAccount);
    }

    protected function onTearDown(): void
    {
        AuthService::instance()->setAccount($this->accountBefore);
        parent::onTearDown();
    }

    public function testAClientShowingAConversationMakesTheAccountReachable(): void
    {
        $this->getAgentClientPresenceService()->recordClientStatement(300, static::OWNER_ACCOUNT_ID, static::CLIENT_ID, null);

        static::assertTrue($this->getRingService()->isAnyClientOfAccountListening(static::OWNER_ACCOUNT_ID));
        static::assertSame(AgentVoiceCallReachableChannels::APP_RING_RINGS_NOW, $this->findAppRingValueForOwner());
        static::assertFalse($this->getRingService()->isAnyClientOfAccountListening(static::OTHER_ACCOUNT_ID), 'another account is not reached');
    }

    public function testAClientThatOnlyPollsTheAccountReadWithItsClientIdIsSeenAndReceivesTheRing(): void
    {
        $accountReadController = new AgentAccountRealtimeReadFixtureController($this->realtimeChannel);
        $accountReadRequestDto = new AgentAccountRealtimeMessagesGetRequestDto();
        $accountReadRequestDto->clientId = static::CLIENT_ID;
        $accountReadController->callBuildAccountRealtimeMessagesResponse($accountReadRequestDto);

        static::assertSame(0, $this->sharedCache->valuesByKey[AgentClientPresenceService::ACCOUNT_WITHOUT_CONVERSATION_KEY_PREFIX . static::OWNER_ACCOUNT_ID]['conversationId']);
        static::assertArrayNotHasKey(AgentClientPresenceService::ACCOUNT_KEY_PREFIX . static::OWNER_ACCOUNT_ID, $this->sharedCache->valuesByKey, 'the key of a client that shows a conversation is not written');
        static::assertTrue($this->getRingService()->isAnyClientOfAccountListening(static::OWNER_ACCOUNT_ID));
        static::assertSame(AgentVoiceCallReachableChannels::APP_RING_RINGS_NOW, $this->findAppRingValueForOwner());
        static::assertFalse($this->getRingService()->isAnyClientOfAccountListening(static::OTHER_ACCOUNT_ID), 'another account is not reached');

        $this->getRingService()->publishRing($this->storeRingingCall(501, time(), 90));
        $deliveredMessages = $accountReadController->callBuildAccountRealtimeMessagesResponse($accountReadRequestDto)->realtimeMessages->getElements();
        static::assertCount(1, $deliveredMessages, 'the polling client receives the ring it is rung for');
        static::assertSame('agent.voice.ring', $deliveredMessages[0]->messageType);
    }

    public function testAnAccountReadWithoutAClientIdStatesNoPresence(): void
    {
        $accountReadController = new AgentAccountRealtimeReadFixtureController($this->realtimeChannel);
        $accountReadController->callBuildAccountRealtimeMessagesResponse(new AgentAccountRealtimeMessagesGetRequestDto());

        static::assertFalse($this->getRingService()->isAnyClientOfAccountListening(static::OWNER_ACCOUNT_ID));
        static::assertSame(AgentVoiceCallReachableChannels::APP_RING_NOT_RINGABLE_NOW, $this->findAppRingValueForOwner());
    }

    public function testWithoutAnyClientTheAccountIsNotReachable(): void
    {
        static::assertFalse($this->getRingService()->isAnyClientOfAccountListening(static::OWNER_ACCOUNT_ID));
        static::assertSame(AgentVoiceCallReachableChannels::APP_RING_NOT_RINGABLE_NOW, $this->findAppRingValueForOwner());
    }

    public function testAClientShowingAConversationWithoutAClientIdIsNotSeen(): void
    {
        $this->getAgentClientPresenceService()->recordClientStatement(300, static::OWNER_ACCOUNT_ID, null, null);

        static::assertFalse($this->getRingService()->isAnyClientOfAccountListening(static::OWNER_ACCOUNT_ID));
    }

    public function testABoundTransportAnswersForTheAccountAudience(): void
    {
        $this->realtimeChannel->hasTransport = true;
        $this->realtimeChannel->listeningByMailboxName[AgentRealtimeAudience::forAccount(static::OWNER_ACCOUNT_ID)->getMailboxName()] = true;

        static::assertTrue($this->getRingService()->isAnyClientOfAccountListening(static::OWNER_ACCOUNT_ID));
    }

    protected function findAppRingValueForOwner(): string
    {
        /** @var AgentVoiceCallStartsService $agentVoiceCallStartsService */
        $agentVoiceCallStartsService = DDDService::instance()->getService(AgentVoiceCallStartsService::class);
        return $agentVoiceCallStartsService->findReachableChannelsForOwner(static::buildAccount(static::OWNER_ACCOUNT_ID), null, null)->getAppRingValue();
    }

    protected function getAgentClientPresenceService(): AgentClientPresenceService
    {
        /** @var AgentClientPresenceService $agentClientPresenceService */
        $agentClientPresenceService = DDDService::instance()->getService(AgentClientPresenceService::class);
        return $agentClientPresenceService;
    }
}
