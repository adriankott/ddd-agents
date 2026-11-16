<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Services\Eval\AgentEvalCasePackagesService;
use DDD\Domain\AI\Services\Eval\AgentEvalRunHooksService;
use DDD\Domain\AI\Services\Skills\AISkillsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * What the evaluation commands ask of the hooks and of the case's skills: the module bills a probe call of a scope to
 * the billing entity of its agent space and attaches a new schedule to the scope's stored space, never creating one;
 * a case's harness calls reach the packages of its expected skills and of their direct sub-skills.
 */
class AgentEvalCommandHooksTest extends IntegrationTestCase
{
    /** @var mixed The skill → packages binding before the test */
    protected mixed $packageClassesBySkillNameBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        $bindingProperty = new ReflectionProperty(AISkill::class, 'packageClassesBySkillName');
        $this->packageClassesBySkillNameBefore = $bindingProperty->getValue();
    }

    protected function onTearDown(): void
    {
        (new ReflectionProperty(AISkill::class, 'packageClassesBySkillName'))->setValue(null, $this->packageClassesBySkillNameBefore);
    }

    public function testAProbeOfAScopeIsBilledToItsSpacesBillingEntity(): void
    {
        $agentEvalRunHooksService = new AgentEvalRunHooksService();
        $owner = $this->account(5);
        $billingEntity = $this->account(99);
        $spaceWithBillingEntity = new AgentSpaceWithBillingEntityDouble();
        $spaceWithBillingEntity->id = 3;
        $spaceWithBillingEntity->billingEntity = $billingEntity;
        $spaceWithoutBillingEntity = new AgentSpace();
        $spaceWithoutBillingEntity->id = 4;

        self::assertSame($billingEntity, $agentEvalRunHooksService->findBillingEntityOfScope(AgentEvalScope::forAgentSpace($spaceWithBillingEntity, $owner)));
        self::assertNull($agentEvalRunHooksService->findBillingEntityOfScope(AgentEvalScope::forAgentSpace($spaceWithoutBillingEntity, $owner)), 'the module\'s space bills the account');
        self::assertNull($agentEvalRunHooksService->findBillingEntityOfScope(AgentEvalScope::forAgentSpace(null, $owner)), 'account level');
    }

    public function testANewScheduleGetsTheScopesStoredSpaceAndNeverACreatedOne(): void
    {
        $agentEvalRunHooksService = new AgentEvalRunHooksService();
        $storedSpace = new AgentSpace();
        $storedSpace->id = 8;
        $schedule = $this->scheduleWithoutId();

        $agentEvalRunHooksService->attachNewScheduleToScope($schedule, AgentEvalScope::forAgentSpace($storedSpace, $this->account(5)));

        self::assertSame(8, $schedule->agentSpaceId);
        self::assertSame($storedSpace, $schedule->agentSpace);

        $accountLevelSchedule = $this->scheduleWithoutId();
        $agentEvalRunHooksService->attachNewScheduleToScope($accountLevelSchedule, AgentEvalScope::forAgentSpace(new AgentSpace(), $this->account(5)));
        self::assertFalse(isset($accountLevelSchedule->agentSpaceId), 'a space without a row is not attached');
    }

    public function testTheHarnessReachesThePackagesOfTheExpectedSkillsAndTheirSubSkills(): void
    {
        (new ReflectionProperty(AISkill::class, 'packageClassesBySkillName'))->setValue(null, [
            'Catalog.Items' => ['App\\Mcp\\CatalogPackage'],
            'Catalog.Items.Edit' => ['App\\Mcp\\CatalogEditPackage', 'App\\Mcp\\CatalogPackage'],
            'Catalog.Items.Edit.Deep' => ['App\\Mcp\\DeepPackage'],
            'Reports' => ['App\\Mcp\\ReportsPackage'],
        ]);
        $skillsServiceDouble = new AISkillsServiceWithSubSkillsDouble();
        $skillsServiceDouble->subSkillNamesBySkillName = ['Catalog.Items' => ['Catalog.Items.Edit'], 'Catalog.Items.Edit' => ['Catalog.Items.Edit.Deep']];
        $this->replaceService(AISkillsService::class, $skillsServiceDouble);
        /** @var AgentEvalCasePackagesService $agentEvalCasePackagesService */
        $agentEvalCasePackagesService = DDDService::instance()->getService(AgentEvalCasePackagesService::class);
        $agentEvalCase = AgentEvalCase::fromArray(['id' => 'c', 'expect' => ['skills' => ['Reports'], 'skill' => 'Catalog.Items']]);

        self::assertSame(['Reports', 'Catalog.Items', 'Catalog.Items.Edit'], $agentEvalCasePackagesService->findExpectedSkillNamesWithSubSkills($agentEvalCase), 'a sub-skill\'s own sub-skills are not followed');
        self::assertSame(['App\\Mcp\\ReportsPackage', 'App\\Mcp\\CatalogPackage', 'App\\Mcp\\CatalogEditPackage'], $agentEvalCasePackagesService->findPackageClassesOfCase($agentEvalCase));
        self::assertSame([], $agentEvalCasePackagesService->findPackageClassesOfCase(AgentEvalCase::fromArray(['id' => 'c'])));
    }

    protected function account(int $accountId): Account
    {
        $account = new Account();
        $account->id = $accountId;
        return $account;
    }

    protected function scheduleWithoutId(): AIConversationSchedule
    {
        /** @var AIConversationSchedule $schedule */
        $schedule = (new ReflectionClass(AIConversationSchedule::class))->newInstanceWithoutConstructor();
        return $schedule;
    }
}
