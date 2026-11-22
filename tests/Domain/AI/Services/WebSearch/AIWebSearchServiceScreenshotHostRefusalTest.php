<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\WebSearch;

use DDD\Domain\AI\Entities\WebSearch\PageScreenshots\PageScreenshotPart;
use DDD\Domain\AI\Services\WebSearch\AIWebSearchService;
use DDD\Infrastructure\Http\PublicUrlGuard;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Tests\Agents\Support\WebSearchTestConfiguration;
use ReflectionProperty;

/**
 * A screenshot of a host without a DNS record is refused as UNRESOLVABLE ("check the spelling"), a private address as
 * NOT_PUBLIC — before, a typo host was told it was "not a public web address". The host classes are seeded, so no
 * resolver is asked.
 */
class AIWebSearchServiceScreenshotHostRefusalTest extends UnitTestCase
{
    public function testATypoHostIsUnresolvableAndAPrivateHostIsNotPublic(): void
    {
        $hostClassByHost = new ReflectionProperty(AIWebSearchService::class, 'hostClassByHost');
        $hostClassesBefore = $hostClassByHost->getValue();
        $configValuesBefore = WebSearchTestConfiguration::declareWebSearchConfiguration(WebSearchTestConfiguration::buildEnabledConfiguration());
        try {
            $hostClassByHost->setValue(null, [
                'exmaple-typo.com' => PublicUrlGuard::HOST_UNRESOLVABLE,
                'intranet.example.com' => PublicUrlGuard::HOST_PRIVATE,
            ]);
            $aiWebSearchService = new AIWebSearchService();

            $typoPart = $aiWebSearchService->capturePagePartForModel('https://www.exmaple-typo.com/pricing');
            self::assertSame(PageScreenshotPart::REASON_UNRESOLVABLE, $typoPart->failureReason);
            self::assertStringContainsString('cannot be resolved (check the spelling)', $typoPart->buildFailureText());
            self::assertStringNotContainsString('not a public web address', $typoPart->buildFailureText());

            $privatePart = $aiWebSearchService->capturePagePartForModel('https://intranet.example.com/');
            self::assertSame(PageScreenshotPart::REASON_NOT_PUBLIC, $privatePart->failureReason);
            self::assertStringContainsString('not a public web address', $privatePart->buildFailureText());
        } finally {
            $hostClassByHost->setValue(null, $hostClassesBefore);
            WebSearchTestConfiguration::restoreConfigValues($configValuesBefore);
        }
    }
}
