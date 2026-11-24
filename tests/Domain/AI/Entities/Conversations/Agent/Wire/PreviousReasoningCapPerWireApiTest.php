<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Wire;

use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentWireConfiguration;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Infrastructure\Libs\Config;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionProperty;

/**
 * The cap of the carry-over `capped` per wire API: a wire API's own entry wins, a wire API without one takes the shared
 * cap, and without any configuration the built-in 4,000 holds.
 */
class PreviousReasoningCapPerWireApiTest extends UnitTestCase
{
    /** @var array<string, mixed> */
    protected array $configValuesBefore = [];

    protected function tearDown(): void
    {
        (new ReflectionProperty(Config::class, 'configTree'))->setValue(null, $this->configValuesBefore);
        parent::tearDown();
    }

    /** @param array<string, mixed> $contextRetention */
    protected function declareContextRetention(array $contextRetention): void
    {
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $this->configValuesBefore = $configTree->isInitialized() ? (array)$configTree->getValue() : [];
        $configValues = $this->configValuesBefore;
        [$topKey, $subKey] = explode('.', ContextRetentionSettings::CONFIG_KEY);
        $configValues[$topKey][$subKey] = $contextRetention;
        $configTree->setValue(null, $configValues);
    }

    public function testAWireApiWithItsOwnEntryTakesItAndTheOthersTakeTheSharedCap(): void
    {
        $this->declareContextRetention([
            'previousReasoningCapTokens' => 5000,
            AgentWireConfiguration::CONFIG_KEY_PREVIOUS_REASONING_CAP_TOKENS_BY_AGENT_WIRE_API => [AIModel::AGENT_WIRE_API_MESSAGES => 12000],
        ]);

        self::assertSame(12000, AgentWireConfiguration::resolveDefaultPreviousReasoningCapTokens(AIModel::AGENT_WIRE_API_MESSAGES));
        self::assertSame(5000, AgentWireConfiguration::resolveDefaultPreviousReasoningCapTokens(AIModel::AGENT_WIRE_API_RESPONSES));
        self::assertSame(5000, AgentWireConfiguration::resolveDefaultPreviousReasoningCapTokens());
    }

    public function testAnInvalidEntryFallsBackToTheSharedCap(): void
    {
        $this->declareContextRetention([
            'previousReasoningCapTokens' => 5000,
            AgentWireConfiguration::CONFIG_KEY_PREVIOUS_REASONING_CAP_TOKENS_BY_AGENT_WIRE_API => [AIModel::AGENT_WIRE_API_MESSAGES => 0],
        ]);

        self::assertSame(5000, AgentWireConfiguration::resolveDefaultPreviousReasoningCapTokens(AIModel::AGENT_WIRE_API_MESSAGES));
    }

    public function testWithoutAnyCapTheBuiltInDefaultHolds(): void
    {
        $this->declareContextRetention([]);

        self::assertSame(AgentWireConfiguration::DEFAULT_PREVIOUS_REASONING_CAP_TOKENS, AgentWireConfiguration::resolveDefaultPreviousReasoningCapTokens(AIModel::AGENT_WIRE_API_MESSAGES));
    }

    public function testTheShippedConfigurationKeepsEveryWireApiAtTheSharedDefault(): void
    {
        $contextRetention = require dirname(__DIR__, 7) . '/config/app/AI/contextRetention.php';

        self::assertSame([], $contextRetention[AgentWireConfiguration::CONFIG_KEY_PREVIOUS_REASONING_CAP_TOKENS_BY_AGENT_WIRE_API]);
        self::assertSame(4000, $contextRetention['previousReasoningCapTokens']);
    }
}
