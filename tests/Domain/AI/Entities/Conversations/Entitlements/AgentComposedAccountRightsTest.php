<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Entitlements;

use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRights;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentComposedAccountRights;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentRoleMappedAccountRights;
use DDD\Domain\AI\Services\Conversations\Entitlements\AIConversationEntitlementsService;
use DDD\Domain\Common\Entities\Roles\Role;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * An application's own rights object composed with the mapping `AI.accountRights`: the application answers the names
 * it knows, the package rights it does not hold follow the mapping (roles, `*`, a delegation to an application right);
 * a name the application declares to answer is final.
 */
class AgentComposedAccountRightsTest extends UnitTestCase
{
    /** @return array<string, mixed> */
    protected function buildMapping(): array
    {
        return [
            AgentAccountRights::OPERATE_PLATFORM => [Role::SUPERADMIN],
            AgentAccountRights::USE_WEB => [AgentRoleMappedAccountRights::EVERY_ACCOUNT],
            AgentAccountRights::SCHEDULE_AGENT_TASKS => [AgentRoleMappedAccountRights::DELEGATED_RIGHT_KEY => 'manageTasks'],
        ];
    }

    public function testPackageRightsTheApplicationDoesNotKnowFollowTheMapping(): void
    {
        $composedRights = AgentComposedAccountRights::compose(
            new ApplicationKnownRightsDouble(['manageBookings']),
            AgentRoleMappedAccountRights::forRoleNames([Role::LOGIN], $this->buildMapping())
        );

        self::assertSame(
            [AgentAccountRights::OPERATE_PLATFORM, AgentAccountRights::SCHEDULE_AGENT_TASKS, AgentAccountRights::USE_AGENT_MEMORY, 'manageInvoices'],
            $composedRights->getMissingRightNames([
                AgentAccountRights::OPERATE_PLATFORM,
                AgentAccountRights::USE_WEB,
                AgentAccountRights::SCHEDULE_AGENT_TASKS,
                AgentAccountRights::USE_AGENT_MEMORY,
                'manageBookings',
                'manageInvoices',
            ])
        );
    }

    public function testADelegationAsksTheApplicationForItsOwnRight(): void
    {
        $composedRights = AgentComposedAccountRights::compose(
            new ApplicationKnownRightsDouble(['manageTasks']),
            AgentRoleMappedAccountRights::forRoleNames([Role::LOGIN], $this->buildMapping())
        );

        self::assertSame([], $composedRights->getMissingRightNames([AgentAccountRights::SCHEDULE_AGENT_TASKS]));
    }

    public function testAnAnsweredNameIsFinalEvenAgainstTheMapping(): void
    {
        $composedRights = AgentComposedAccountRights::compose(
            new ApplicationAnsweringRightsDouble([], [AgentAccountRights::USE_WEB]),
            AgentRoleMappedAccountRights::forRoleNames([Role::LOGIN], $this->buildMapping())
        );

        self::assertSame([AgentAccountRights::USE_WEB], $composedRights->getMissingRightNames([AgentAccountRights::USE_WEB]));
    }

    public function testWithoutAnApplicationObjectADelegationLooksTheNamedRightUpInTheMapping(): void
    {
        $mapping = $this->buildMapping();
        $mapping['manageTasks'] = [Role::ADMIN];

        self::assertSame([AgentAccountRights::SCHEDULE_AGENT_TASKS], AgentRoleMappedAccountRights::forRoleNames([Role::LOGIN], $mapping)->getMissingRightNames([AgentAccountRights::SCHEDULE_AGENT_TASKS]));
        self::assertSame([], AgentRoleMappedAccountRights::forRoleNames([Role::ADMIN], $mapping)->getMissingRightNames([AgentAccountRights::SCHEDULE_AGENT_TASKS]));
    }

    public function testADelegationEntryIsNoRoleName(): void
    {
        $roleMappedRights = AgentRoleMappedAccountRights::forRoleNames(['manageTasks'], $this->buildMapping());

        self::assertSame([], $roleMappedRights->getRoleNamesOfRight(AgentAccountRights::SCHEDULE_AGENT_TASKS));
        self::assertSame('manageTasks', $roleMappedRights->findDelegatedRightName(AgentAccountRights::SCHEDULE_AGENT_TASKS));
        self::assertTrue($roleMappedRights->namesRight(AgentAccountRights::SCHEDULE_AGENT_TASKS));
    }

    public function testThePackagesOwnObjectsAreNotComposedAgain(): void
    {
        $aIConversationEntitlementsService = new AIConversationEntitlementsService();
        $roleMappedRights = AgentRoleMappedAccountRights::forRoleNames([], $this->buildMapping());
        $composedRights = $aIConversationEntitlementsService->composeAccountRights(new ApplicationKnownRightsDouble(), null);

        self::assertSame($roleMappedRights, $aIConversationEntitlementsService->composeAccountRights($roleMappedRights, null));
        self::assertInstanceOf(AgentComposedAccountRights::class, $composedRights);
        self::assertSame($composedRights, $aIConversationEntitlementsService->composeAccountRights($composedRights, null));
    }
}
