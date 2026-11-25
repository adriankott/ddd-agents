<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\AI;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Presentation\Mcp\Client\Agents\Dtos\BackgroundTaskStartRequestDto;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The payer of the task tools' budget pot: the acting account by default; an application that returns another
 * account from findBudgetPayerOfCall() makes the budget line and the start-time check of startBackgroundTask read
 * that account's pot.
 */
class AgentTaskSchedulesMCPPackageBudgetPayerTest extends UnitTestCase
{
    public function testTheBudgetLineReadsThePotOfTheActingAccountByDefault(): void
    {
        $schedulesService = new BudgetPotRecordingSchedulesServiceDouble();
        $package = static::buildPackageActingAs(3);

        $budgetLine = $package->composeScheduleBudgetPotNoteOfCallForTest($schedulesService, new AgentToolCallProvenance());

        self::assertSame([3], $schedulesService->accountIdsOfPotReads);
        self::assertStringContainsString('USED UP', $budgetLine);
    }

    public function testTheBudgetLineReadsThePotOfThePayerTheApplicationNames(): void
    {
        $schedulesService = new BudgetPotRecordingSchedulesServiceDouble();
        $package = static::buildPackageActingAs(3);
        $package->fixtureBudgetPayerAccount = static::buildAccount(8);

        $package->composeScheduleBudgetPotNoteOfCallForTest($schedulesService, new AgentToolCallProvenance());

        self::assertSame([8], $schedulesService->accountIdsOfPotReads);
    }

    public function testTheStartCheckOfABackgroundTaskReadsThePotOfTheActingAccountByDefault(): void
    {
        $schedulesService = new BudgetPotRecordingSchedulesServiceDouble();
        $package = static::buildPackageActingAs(3);

        static::assertStartIsRefusedOnTheUsedUpPot($package, $schedulesService);
        self::assertSame([3], $schedulesService->accountIdsOfPotReads);
    }

    public function testTheStartCheckOfABackgroundTaskReadsThePotOfThePayerTheApplicationNames(): void
    {
        $schedulesService = new BudgetPotRecordingSchedulesServiceDouble();
        $package = static::buildPackageActingAs(3);
        $package->fixtureBudgetPayerAccount = static::buildAccount(8);

        static::assertStartIsRefusedOnTheUsedUpPot($package, $schedulesService);
        self::assertSame([8], $schedulesService->accountIdsOfPotReads);
    }

    protected static function assertStartIsRefusedOnTheUsedUpPot(
        AgentTaskSchedulesMCPPackageWithBudgetPayerDouble $package,
        BudgetPotRecordingSchedulesServiceDouble $schedulesService
    ): void {
        $requestDto = new BackgroundTaskStartRequestDto();
        $requestDto->task = 'Summarise the bookings of last week.';
        try {
            $package->startBackgroundTask($requestDto, $schedulesService, new AgentToolCallProvenance());
            self::fail('A used-up pot refuses the start of a background task');
        } catch (BadRequestException $badRequestException) {
            self::assertStringContainsString('USED UP', $badRequestException->getMessage());
        }
    }

    protected static function buildPackageActingAs(int $actingAccountId): AgentTaskSchedulesMCPPackageWithBudgetPayerDouble
    {
        $package = new AgentTaskSchedulesMCPPackageWithBudgetPayerDouble();
        $package->fixtureActingAccount = static::buildAccount($actingAccountId);
        return $package;
    }

    protected static function buildAccount(int $accountId): Account
    {
        $account = new Account();
        $account->id = $accountId;
        return $account;
    }
}
