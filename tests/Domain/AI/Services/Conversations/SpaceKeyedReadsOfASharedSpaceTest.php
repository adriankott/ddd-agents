<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Probes\ConversationsServiceRecordingPartitionConditions;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Probes\SchedulesServiceRecordingPartitionConditions;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The reads keyed by a space have a form keyed by space AND account for a space several accounts share (a space
 * without one owner): the newest open chat, the daily schedule-creation count, the unseen-runs badge and the monthly
 * cost estimate. The space-only forms keep their condition.
 */
class SpaceKeyedReadsOfASharedSpaceTest extends UnitTestCase
{
    public function testTheAccountFormsCarryTheAccountAndTheSpaceFormsDoNot(): void
    {
        $agentSpace = new AgentSpace();
        $agentSpace->id = 7;
        $account = new Account();
        $account->id = 12;
        $since = DateTime::fromString('2026-11-01 00:00:00');

        $schedulesService = new SchedulesServiceRecordingPartitionConditions();
        $schedulesService->countAgentRecurringSchedulesCreatedForAgentSpaceOfAccountSince($agentSpace, $account, $since);
        $schedulesService->buildMonthlyCostEstimateForActiveRecurringSchedulesOfAgentSpaceOfAccount($agentSpace, $account);
        $schedulesService->countUnseenFinishedRunsOfAgentSpaceOfAccount($agentSpace, $account);
        $schedulesService->countAgentRecurringSchedulesCreatedForAgentSpaceSince($agentSpace, $since);
        $conversationsService = new ConversationsServiceRecordingPartitionConditions();
        $conversationsService->findNewestOpenCustomerChatIdOfAgentSpaceAndAccount($agentSpace, $account, $since);
        $conversationsService->findNewestOpenCustomerChatIdOfAgentSpace($agentSpace, $since);

        $accountFormConditions = [...array_slice($schedulesService->recordedConditions, 0, 3), $conversationsService->recordedConditions[0]];
        foreach ($accountFormConditions as $agentSpaceSqlCondition) {
            self::assertSame(['agentSpaceId' => 7, 'accountIdOfRowsWithoutAgentSpace' => 12], $agentSpaceSqlCondition->getParameters());
        }
        self::assertSame(['agentSpaceId' => 7], $schedulesService->recordedConditions[3]->getParameters(), 'the space form keeps its condition');
        self::assertSame(['agentSpaceId' => 7], $conversationsService->recordedConditions[1]->getParameters());
    }
}
