<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Schedules\RecurringScheduleMonthlyCostEstimate;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\Eligibility\AgentAutomationEligibilityService;
use DDD\Domain\AI\Services\Conversations\Schedules\ScheduleBudgetPotsService;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Tests\Agents\Support\IntegrationTestCase;

/**
 * The payer of a stored schedule's budget pot: the schedule's account by default; an application that returns
 * another account from findBudgetPayerOfSchedule() makes the pot check of a firing run and the budget of the monthly
 * cost estimate read that account's.
 */
class AIConversationSchedulesServiceBudgetPayerTest extends IntegrationTestCase
{
    protected ScheduleBudgetPotsServiceRecordingPayersDouble $scheduleBudgetPotsService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scheduleBudgetPotsService = new ScheduleBudgetPotsServiceRecordingPayersDouble();
        $this->replaceService(AgentAutomationEligibilityService::class, new AutomationEligibilityServiceWithEverythingEligibleDouble());
        $this->replaceService(ScheduleBudgetPotsService::class, $this->scheduleBudgetPotsService);
        $this->replaceService(AIConversationsService::class, new ConversationsServiceWithScheduleRunDouble(null));
        StaticRegistry::$entityServices = [];
    }

    protected function onTearDown(): void
    {
        StaticRegistry::$entityServices = [];
    }

    public function testTheFiringRunIsCheckedAgainstThePotOfTheSchedulesAccountByDefault(): void
    {
        $schedulesService = new SchedulesServiceWithBudgetPayerDouble();

        $occurrenceSpawn = $schedulesService->spawnRunConversationForClaimedSchedule(static::buildRecurringScheduleOfAccount(34));

        self::assertSame([34], $this->scheduleBudgetPotsService->accountIdsOfPotReads);
        self::assertTrue($schedulesService->isSkipReasonOfExhaustedBudgetPot((string)$occurrenceSpawn->skipReason));
    }

    public function testTheFiringRunIsCheckedAgainstThePotOfThePayerTheApplicationNames(): void
    {
        $schedulesService = new SchedulesServiceWithBudgetPayerDouble();
        $schedulesService->fixtureBudgetPayerAccount = static::buildAccount(99);

        $occurrenceSpawn = $schedulesService->spawnRunConversationForClaimedSchedule(static::buildRecurringScheduleOfAccount(34));

        self::assertSame([99], $this->scheduleBudgetPotsService->accountIdsOfPotReads);
        self::assertTrue($schedulesService->isSkipReasonOfExhaustedBudgetPot((string)$occurrenceSpawn->skipReason));
    }

    public function testTheCostEstimateTakesTheBudgetOfTheSchedulesAccountByDefault(): void
    {
        $monthlyCostEstimate = new RecurringScheduleMonthlyCostEstimate();

        (new SchedulesServiceWithBudgetPayerDouble())->applyBudgetOfPayerToMonthlyCostEstimateForTest($monthlyCostEstimate, static::buildRecurringScheduleOfAccount(34));

        self::assertSame([34], $this->scheduleBudgetPotsService->accountIdsOfBudgetReads);
        self::assertSame(340.0, $monthlyCostEstimate->monthlyBudgetUsd);
    }

    public function testTheCostEstimateTakesTheBudgetOfThePayerTheApplicationNames(): void
    {
        $monthlyCostEstimate = new RecurringScheduleMonthlyCostEstimate();
        $schedulesService = new SchedulesServiceWithBudgetPayerDouble();
        $schedulesService->fixtureBudgetPayerAccount = static::buildAccount(99);

        $schedulesService->applyBudgetOfPayerToMonthlyCostEstimateForTest($monthlyCostEstimate, static::buildRecurringScheduleOfAccount(34));

        self::assertSame([99], $this->scheduleBudgetPotsService->accountIdsOfBudgetReads);
        self::assertSame(990.0, $monthlyCostEstimate->monthlyBudgetUsd);
    }

    protected static function buildRecurringScheduleOfAccount(int $accountId): AIConversationSchedule
    {
        $schedule = new AIConversationSchedule();
        $schedule->id = 4420;
        $schedule->accountId = $accountId;
        $schedule->agentSpaceId = 7;
        $schedule->account = static::buildAccount($accountId);
        return $schedule;
    }

    protected static function buildAccount(int $accountId): Account
    {
        $account = Account::newInstance();
        $account->id = $accountId;
        return $account;
    }
}
