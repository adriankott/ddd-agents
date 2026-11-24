<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Entitlements;

use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRights;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentRoleMappedAccountRights;
use DDD\Domain\Common\Entities\Roles\Role;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The default rights: a right is held by the roles the mapping names, by every account with `*`, by nobody when the
 * mapping does not name it. The shipped mapping gives the platform to the superadmins and the everyday rights to all.
 */
class AgentRoleMappedAccountRightsTest extends UnitTestCase
{
    public function testARightIsHeldByItsRolesByEveryAccountWithTheWildcardAndByNobodyUnmapped(): void
    {
        $roleNamesByRightName = [
            AgentAccountRights::OPERATE_PLATFORM => [Role::SUPERADMIN],
            AgentAccountRights::USE_WEB => [AgentRoleMappedAccountRights::EVERY_ACCOUNT],
        ];
        $customerRights = AgentRoleMappedAccountRights::forRoleNames([Role::LOGIN], $roleNamesByRightName);
        $superadminRights = AgentRoleMappedAccountRights::forRoleNames([Role::LOGIN, Role::SUPERADMIN], $roleNamesByRightName);

        self::assertSame([AgentAccountRights::OPERATE_PLATFORM, 'manageBookings'], $customerRights->getMissingRightNames([AgentAccountRights::OPERATE_PLATFORM, AgentAccountRights::USE_WEB, 'manageBookings']));
        self::assertSame(['manageBookings'], $superadminRights->getMissingRightNames([AgentAccountRights::OPERATE_PLATFORM, AgentAccountRights::USE_WEB, 'manageBookings']));
    }

    public function testTheShippedMappingOpensThePlatformToSuperadminsOnly(): void
    {
        $shippedMapping = require dirname(__DIR__, 6) . '/config/app/AI/accountRights.php';
        $customerRights = AgentRoleMappedAccountRights::forRoleNames([Role::LOGIN, Role::ADMIN], $shippedMapping);

        self::assertSame(AgentAccountRights::OPERATOR_RIGHTS, $customerRights->getMissingRightNames(AgentAccountRights::RIGHTS));
        self::assertSame(AgentAccountRights::PLATFORM_RIGHTS_OF_OTHER_PACKAGES, $customerRights->getMissingRightNames(AgentAccountRights::PLATFORM_RIGHTS_OF_OTHER_PACKAGES));
        self::assertSame([], AgentRoleMappedAccountRights::forRoleNames([Role::SUPERADMIN], $shippedMapping)->getMissingRightNames(AgentAccountRights::RIGHTS));
    }
}
