<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Wire;

use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentWireConfiguration;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The cap of the reasoning carry-over `capped` has one value: the built-in default, the shipped
 * `contextRetention.php` and the class documentation of the cut agree on 4,000.
 */
class PreviousReasoningCapDefaultAgreesTest extends UnitTestCase
{
    public function testTheBuiltInDefaultTheShippedConfigurationAndTheCutsDocumentationAgree(): void
    {
        $packageRoot = dirname(__DIR__, 7);
        $contextRetention = require $packageRoot . '/config/app/AI/contextRetention.php';

        self::assertSame(4000, AgentWireConfiguration::DEFAULT_PREVIOUS_REASONING_CAP_TOKENS);
        self::assertSame(AgentWireConfiguration::DEFAULT_PREVIOUS_REASONING_CAP_TOKENS, $contextRetention['previousReasoningCapTokens']);
        self::assertStringContainsString('the 4,000 default', (string)file_get_contents($packageRoot . '/src/Domain/AI/Entities/Conversations/Agent/Wire/AgentPreviousReasoningCut.php'));
    }
}
