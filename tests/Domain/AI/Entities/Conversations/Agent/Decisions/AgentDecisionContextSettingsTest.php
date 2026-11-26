<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionContextSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionTextCuts;
use PHPUnit\Framework\TestCase;

/** The settings `AI.decisionContext`: the package file equals the defaults, an invalid value keeps its default; the marked cuts. */
class AgentDecisionContextSettingsTest extends TestCase
{
    public function testThePackageFileHoldsTheDefaultsAndAnInvalidValueKeepsItsDefault(): void
    {
        $packageSettings = AgentDecisionContextSettings::fromConfigNode(require dirname(__DIR__, 7) . '/config/app/AI/decisionContext.php');
        self::assertEquals(new AgentDecisionContextSettings(), $packageSettings);

        $configuredSettings = AgentDecisionContextSettings::fromConfigNode([
            'ownerConsent' => ['lineCharacters' => 1200, 'expandedLineCharacters' => -5, 'secondPass' => 'no', 'nearMissFloor' => 1.4],
            'stallRecovery' => ['assistantTextCharacters' => 900],
        ]);
        self::assertSame(1200, $configuredSettings->ownerConsentLineCharacters);
        self::assertSame(12000, $configuredSettings->ownerConsentExpandedLineCharacters);
        self::assertTrue($configuredSettings->ownerConsentSecondPass);
        self::assertSame(0.5, $configuredSettings->ownerConsentNearMissFloor);
        self::assertSame(900, $configuredSettings->stallRecoveryAssistantTextCharacters);
        self::assertSame(300, $configuredSettings->turnStartAssistantTextCharacters);
    }

    public function testACutIsMarkedWithItsSideAndLengthAndAFittingTextStaysAsItIs(): void
    {
        self::assertSame('[… 3 characters cut at the start] defg', AgentDecisionTextCuts::keepEnd('abcdefg', 4));
        self::assertSame('abcd [… 3 characters cut at the end]', AgentDecisionTextCuts::keepStart('abcdefg', 4));
        self::assertSame('äöü', AgentDecisionTextCuts::keepEnd('äöü', 3));
        self::assertSame(0, AgentDecisionTextCuts::countCutCharacters('äöü', 3));
    }
}
