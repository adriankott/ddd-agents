<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Calls\StoreAdditions;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\CallLife\InMemoryAgentVoiceCallStore;
use DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\CallLife\InMemoryStoreAgentVoiceCallStoreService;
use DDD\Tests\Agents\Voice\Support\AgentVoiceCallFixtures;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;

/** The port hands each method added after its core tests to the bound store, with the arguments as given. */
class AgentVoiceCallStorePortAdditionsTest extends AgentVoiceUnitTestCase
{
    public function testEachAddedMethodGoesToTheBoundStore(): void
    {
        $callStore = new InMemoryAgentVoiceCallStore();
        $agentVoiceCallStoreService = new InMemoryStoreAgentVoiceCallStoreService($callStore);
        $agentVoiceCall = new AgentVoiceCall();
        $agentVoiceCall->id = 7;
        $account = Account::newInstance();
        $account->id = 5;

        $agentVoiceCallStoreService->setVoiceProviderOfCall($agentVoiceCall, 'openAiLive');
        $agentVoiceCallStoreService->findRunningCallOfConversationId(30);
        $agentVoiceCallStoreService->findCallsOfAccountIds(null);
        $agentVoiceCallStoreService->findCallsOfAccountIds([]);
        $agentVoiceCallStoreService->findCallOfAccountIds(9, [5, 6]);
        $agentVoiceCallStoreService->findUnansweredCallsOfAccountPastRingDeadline($account, AgentVoiceCallFixtures::NOW_UNIX_TIME);

        static::assertSame(
            [
                ['method' => 'setVoiceProviderOfCall', 'arguments' => [7, 'openAiLive']],
                ['method' => 'findRunningCallOfConversationId', 'arguments' => [30]],
                ['method' => 'findCallsOfAccountIds', 'arguments' => [null]],
                ['method' => 'findCallsOfAccountIds', 'arguments' => [[]]],
                ['method' => 'findCallOfAccountIds', 'arguments' => [9, [5, 6]]],
                ['method' => 'findUnansweredCallsOfAccountPastRingDeadline', 'arguments' => [5, AgentVoiceCallFixtures::NOW_UNIX_TIME]],
            ],
            $callStore->recordedCalls
        );
    }
}
