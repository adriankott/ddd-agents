<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\Services;

use DDD\DDDBundle;
use DDD\Domain\AI\Services\WebSearch\WebSearchHostService;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use DDD\Tests\Agents\Presentation\Mcp\Services\Fixtures\ApplicationWebSearchHostService;
use DDD\Tests\Agents\Support\IntegrationTestCase;

/**
 * A tool parameter typed on a package service receives the application's same-path subclass, like
 * `DDDService::getService()`: the executor resolves the parameter through the application's class name.
 */
class ToolParametersResolveTheApplicationsServiceTest extends IntegrationTestCase
{
    public function testAPackageServiceParameterResolvesToTheApplicationsSubclass(): void
    {
        $frameworkToAppClassNamesBefore = StaticRegistry::$frameworkToAppClassNames;
        $applicationWebSearchHostService = new ApplicationWebSearchHostService();
        DDDBundle::getContainer()->set(ApplicationWebSearchHostService::class, $applicationWebSearchHostService);
        try {
            StaticRegistry::$frameworkToAppClassNames[WebSearchHostService::class] = ApplicationWebSearchHostService::class;
            /** @var MCPToolExecutorService $mCPToolExecutorService */
            $mCPToolExecutorService = DDDService::instance()->getService(MCPToolExecutorService::class);
            self::assertSame($applicationWebSearchHostService, $mCPToolExecutorService->resolveFromContainer(WebSearchHostService::class));

            StaticRegistry::$frameworkToAppClassNames[WebSearchHostService::class] = WebSearchHostService::class;
            self::assertNotInstanceOf(ApplicationWebSearchHostService::class, $mCPToolExecutorService->resolveFromContainer(WebSearchHostService::class));
        } finally {
            StaticRegistry::$frameworkToAppClassNames = $frameworkToAppClassNamesBefore;
        }
    }
}
