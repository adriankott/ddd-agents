<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuitePlanOptions;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTask;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\Eval\AgentEvalSuitePlanService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use RuntimeException;

/**
 * An evaluation run that names an account (`--accountId`): a case without a space runs at account level for it, a
 * space without an owner runs for it, a space with an owner keeps its owner; without the option the scope is the
 * space-only one (an application's override of it keeps deciding). The planning gives such a case a scope of its own,
 * so a host without spaces plans its cases.
 */
class AgentEvalAccountLevelScopeTest extends IntegrationTestCase
{
    protected AgentSpacesServiceWithKnownSpacesDouble $agentSpacesServiceDouble;

    protected AgentEvalRunHooksServiceWithKnownAccountsDouble $agentEvalRunHooksService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agentSpacesServiceDouble = new AgentSpacesServiceWithKnownSpacesDouble();
        $this->replaceService(AgentSpacesService::class, $this->agentSpacesServiceDouble);
        $this->agentEvalRunHooksService = new AgentEvalRunHooksServiceWithKnownAccountsDouble();
        $this->agentEvalRunHooksService->accountsById[40] = $this->buildAccount(40);
    }

    public function testACaseWithoutASpaceRunsAtAccountLevelForTheNamedAccount(): void
    {
        $agentEvalScope = $this->agentEvalRunHooksService->resolveEvalScopeWithFallbackAccount(AgentEvalCase::fromArray(['id' => 'c']), null, 40);

        self::assertNull($agentEvalScope->getAgentSpace());
        self::assertFalse($agentEvalScope->hasStoredAgentSpace());
        self::assertSame(40, $agentEvalScope->getOwnerAccountId());
        self::assertSame(0, $this->agentEvalRunHooksService->spaceOnlyScopeCalls);
    }

    public function testASpaceWithoutAnOwnerRunsForTheNamedAccountAndAnOwnedSpaceKeepsItsOwner(): void
    {
        $ownerlessAgentSpace = $this->knowAgentSpace(1, null);
        $ownedAgentSpace = $this->knowAgentSpace(2, 5);

        $ownerlessScope = $this->agentEvalRunHooksService->resolveEvalScopeWithFallbackAccount(AgentEvalCase::fromArray(['id' => 'c', 'agentSpaceId' => 1]), null, 40);
        $ownedScope = $this->agentEvalRunHooksService->resolveEvalScopeWithFallbackAccount(AgentEvalCase::fromArray(['id' => 'c']), 2, 40);

        self::assertSame($ownerlessAgentSpace, $ownerlessScope->getAgentSpace());
        self::assertSame(40, $ownerlessScope->getOwnerAccountId());
        self::assertSame($ownedAgentSpace, $ownedScope->getAgentSpace());
        self::assertSame(5, $ownedScope->getOwnerAccountId());
        self::assertSame([true, true], $this->agentSpacesServiceDouble->rightsLiftedPerRead);
    }

    public function testWithoutTheOptionTheSpaceOnlyScopeDecides(): void
    {
        $this->knowAgentSpace(2, 5);

        $agentEvalScope = $this->agentEvalRunHooksService->resolveEvalScopeWithFallbackAccount(AgentEvalCase::fromArray(['id' => 'c']), 2, null);

        self::assertSame(5, $agentEvalScope->getOwnerAccountId());
        self::assertSame(1, $this->agentEvalRunHooksService->spaceOnlyScopeCalls);
        $this->knowAgentSpace(1, null);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not resolve the account that owns agent space 1');
        $this->agentEvalRunHooksService->resolveEvalScopeWithFallbackAccount(AgentEvalCase::fromArray(['id' => 'c', 'agentSpaceId' => 1]), null, null);
    }

    public function testAnAccountThatIsNotStoredIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Account 41 not found');
        $this->agentEvalRunHooksService->resolveEvalScopeWithFallbackAccount(AgentEvalCase::fromArray(['id' => 'c']), null, 41);
    }

    public function testThePlanningGivesACaseWithoutASpaceTheAccountScope(): void
    {
        $agentEvalSuitePlanService = new AgentEvalSuitePlanService();
        $agentEvalSuitePlanOptions = AgentEvalSuitePlanOptions::forModelsAndFlavors([null], [AgentEvalSuiteTask::FLAVOR_CALL]);
        $spacelessCase = AgentEvalCase::fromArray(['id' => 'spaceless', 'prompt' => 'p']);

        $taskWithoutScope = $agentEvalSuitePlanService->createTask();
        self::assertFalse($agentEvalSuitePlanService->assignScopeOfCaseToTask($taskWithoutScope, $spacelessCase, $agentEvalSuitePlanOptions, true), 'neither space nor account: not planned');

        $agentEvalSuitePlanOptions->fallbackAccountId = 40;
        $accountLevelTask = $agentEvalSuitePlanService->createTask();
        self::assertTrue($agentEvalSuitePlanService->assignScopeOfCaseToTask($accountLevelTask, $spacelessCase, $agentEvalSuitePlanOptions, true));
        self::assertNull($accountLevelTask->agentSpaceId);
        self::assertSame(40, $accountLevelTask->accountId);
        self::assertSame(-40, $accountLevelTask->getScopeId(), 'never the id of a space');

        $agentEvalSuitePlanOptions->fallbackAgentSpaceId = 3;
        $spaceTask = $agentEvalSuitePlanService->createTask();
        $agentEvalSuitePlanService->assignScopeOfCaseToTask($spaceTask, $spacelessCase, $agentEvalSuitePlanOptions, true);
        self::assertSame(3, $spaceTask->getScopeId(), 'a fallback space wins over the account level');
        self::assertNull($spaceTask->accountId);

        $withoutFallbackTask = $agentEvalSuitePlanService->createTask();
        self::assertFalse($agentEvalSuitePlanService->assignScopeOfCaseToTask($withoutFallbackTask, $spacelessCase, $agentEvalSuitePlanOptions, false));
    }

    protected function knowAgentSpace(int $agentSpaceId, ?int $ownerAccountId): AgentSpace
    {
        $agentSpace = new AgentSpace();
        $agentSpace->id = $agentSpaceId;
        $this->agentSpacesServiceDouble->agentSpacesById[$agentSpaceId] = $agentSpace;
        if ($ownerAccountId !== null) {
            $this->agentSpacesServiceDouble->ownerAccountsByAgentSpaceId[$agentSpaceId] = $this->buildAccount($ownerAccountId);
        }
        return $agentSpace;
    }

    protected function buildAccount(int $accountId): Account
    {
        $account = new Account();
        $account->id = $accountId;
        return $account;
    }
}
