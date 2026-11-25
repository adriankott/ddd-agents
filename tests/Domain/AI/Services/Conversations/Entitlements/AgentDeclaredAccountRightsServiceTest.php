<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Entitlements;

use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRights;
use DDD\Domain\AI\Services\Conversations\Entitlements\AgentDeclaredAccountRightsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Client\Agents\AgentTaskSchedulesMCPPackage;
use PHPUnit\Framework\TestCase;

/**
 * The rights the installed packages declare: the `RIGHTS` list of each rights constant class and every right of
 * `#[MCPRequiresAccountRights]` on the packages and their tools — the read a host's guard test checks its rights
 * model against.
 */
class AgentDeclaredAccountRightsServiceTest extends TestCase
{
    public function testThePackagesOwnRightsAreDeclaredWithTheirDeclaringClasses(): void
    {
        /** @var AgentDeclaredAccountRightsService $agentDeclaredAccountRightsService */
        $agentDeclaredAccountRightsService = DDDService::instance()->getService(AgentDeclaredAccountRightsService::class);
        $agentDeclaredAccountRightsService->resetDeclaredAccountRights();

        $declaredRightNames = $agentDeclaredAccountRightsService->getDeclaredAccountRightNames();
        foreach (AgentAccountRights::RIGHTS as $packageRightName) {
            self::assertContains($packageRightName, $declaredRightNames);
        }
        $scheduleRight = $agentDeclaredAccountRightsService->findDeclaredAccountRights()->findByRightName(AgentAccountRights::SCHEDULE_AGENT_TASKS);
        self::assertNotNull($scheduleRight);
        self::assertContains(AgentAccountRights::class, $scheduleRight->declaringClassNames);
        self::assertContains(AgentTaskSchedulesMCPPackage::class, $scheduleRight->declaringClassNames);
        $sortedRightNames = $declaredRightNames;
        sort($sortedRightNames);
        self::assertSame($sortedRightNames, $declaredRightNames);
    }
}
