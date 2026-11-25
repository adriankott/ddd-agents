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
 * An application that answers rights with its own object gets nothing from the package's default mapping: without a
 * mapping of its own, a package right its object does not hold is not held (fail closed); with one, the mapping
 * applies; a host on the package's role-mapped rights keeps the default mapping.
 */
class AccountRightsFailClosedTest extends UnitTestCase
{
    /** @var string[] Package rights the package's default mapping grants to every account */
    public const array RIGHTS_THE_DEFAULT_GRANTS_TO_EVERYONE = [
        AgentAccountRights::SCHEDULE_AGENT_TASKS,
        AgentAccountRights::USE_AGENT_MEMORY,
        AgentAccountRights::USE_WEB,
        AgentAccountRights::USE_UI,
    ];

    public function testAnApplicationObjectWithoutAnApplicationMappingHoldsNoPackageRightItDoesNotHoldItself(): void
    {
        $entitlementsService = new ApplicationRightsEntitlementsServiceDouble(new ApplicationKnownRightsDouble(['manageBookings', AgentAccountRights::USE_WEB]), false);

        $checkedRights = $entitlementsService->findComposedAccountRightsOfCurrentAccount();

        self::assertNotInstanceOf(AgentComposedAccountRights::class, $checkedRights);
        self::assertSame(
            [AgentAccountRights::SCHEDULE_AGENT_TASKS, AgentAccountRights::USE_AGENT_MEMORY, AgentAccountRights::USE_UI, AgentAccountRights::OPERATE_PLATFORM],
            $checkedRights->getMissingRightNames([...self::RIGHTS_THE_DEFAULT_GRANTS_TO_EVERYONE, AgentAccountRights::OPERATE_PLATFORM])
        );
    }

    public function testAnApplicationObjectWithAnApplicationMappingIsComposedWithIt(): void
    {
        $entitlementsService = new ApplicationRightsEntitlementsServiceDouble(new ApplicationKnownRightsDouble(['manageBookings']), true);

        $checkedRights = $entitlementsService->findComposedAccountRightsOfCurrentAccount();

        self::assertInstanceOf(AgentComposedAccountRights::class, $checkedRights);
        // the mapping read here is the configured one; without an account only '*' entries hold
        self::assertSame([], $checkedRights->getMissingRightNames([AgentAccountRights::USE_WEB, 'manageBookings']));
        self::assertSame([AgentAccountRights::OPERATE_PLATFORM], $checkedRights->getMissingRightNames([AgentAccountRights::OPERATE_PLATFORM]));
    }

    public function testThePackagesRoleMappedRightsAreUnchanged(): void
    {
        $roleMappedRights = AgentRoleMappedAccountRights::forRoleNames([Role::LOGIN], [AgentAccountRights::USE_WEB => [AgentRoleMappedAccountRights::EVERY_ACCOUNT]]);

        self::assertSame($roleMappedRights, (new AIConversationEntitlementsService())->composeAccountRights($roleMappedRights, null));
        self::assertSame([], $roleMappedRights->getMissingRightNames([AgentAccountRights::USE_WEB]));
    }

    public function testThePackagesOwnMappingFileIsNoApplicationMappingAndThePackageServiceAnswersNoObjectOfItsOwn(): void
    {
        $aIConversationEntitlementsService = new AIConversationEntitlementsService();

        self::assertFalse($aIConversationEntitlementsService->isAccountRightsMappingShippedByTheApplication());
        self::assertFalse($aIConversationEntitlementsService->isAccountRightsAnsweredByTheApplication());
        self::assertTrue((new ApplicationRightsEntitlementsServiceDouble(new ApplicationKnownRightsDouble([]), false))->isAccountRightsAnsweredByTheApplication());
    }
}
