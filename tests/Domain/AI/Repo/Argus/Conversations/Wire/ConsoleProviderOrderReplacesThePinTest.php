<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\Conversations\Wire;

use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentWireConfiguration;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use ReflectionMethod;

/**
 * The provider pin of a call on the Messages API (`only: anthropic`, no fallback) and an operator's console provider
 * order: nothing automatic leaves the pin; the console order is itself a pin for one process, replaces the egress's
 * pin, and the egress then sends no carried reasoning — thinking minted on the pinned host would reach another one.
 */
class ConsoleProviderOrderReplacesThePinTest extends IntegrationTestCase
{
    protected function onTearDown(): void
    {
        ArgusAIAgentConversation::forceProviderOrder(null);
    }

    public function testWithoutAConsoleOrderTheCallIsPinnedAndCarriesItsReasoning(): void
    {
        $egress = $this->buildEgressOnMessages();

        self::assertFalse(ArgusAIAgentConversation::hasConsoleProviderOrder());
        self::assertTrue($egress->agentWireConfiguration->carriesPreviousReasoning());
        $providerPreference = $this->resolveProviderPreference($egress);
        self::assertSame(['anthropic'], $providerPreference['only']);
        self::assertFalse($providerPreference['allow_fallbacks']);
    }

    public function testAConsoleOrderReplacesThePinAndTheCallCarriesNoReasoning(): void
    {
        ArgusAIAgentConversation::forceProviderOrder(['google-vertex']);
        $egress = $this->buildEgressOnMessages();

        self::assertTrue(ArgusAIAgentConversation::hasConsoleProviderOrder());
        self::assertTrue($egress->agentWireConfiguration->usesMessagesApi(), 'the wire API stays');
        self::assertFalse($egress->agentWireConfiguration->carriesPreviousReasoning(), 'no thinking block goes to a host that did not mint it');
        $providerPreference = $this->resolveProviderPreference($egress);
        self::assertSame(['google-vertex'], $providerPreference['order']);
        self::assertArrayNotHasKey('only', $providerPreference, 'the console order replaced the pin');
    }

    public function testTheConfigurationOfTheRunIsNotChanged(): void
    {
        ArgusAIAgentConversation::forceProviderOrder(['google-vertex']);
        $agentWireConfigurationOfTheRun = $this->buildConfiguration();

        (new ArgusAIAgentConversation())->applyAgentWireConfiguration($agentWireConfigurationOfTheRun);

        self::assertTrue($agentWireConfigurationOfTheRun->carriesPreviousReasoning(), 'the egress works on a copy: a later process without the order carries the reasoning again');
    }

    protected function buildEgressOnMessages(): ArgusAIAgentConversation
    {
        $egress = new ArgusAIAgentConversation();
        $egress->aiModelNameOverride = AIModel::MODEL_ANTHROPIC_CLAUDE_OPUS_5_5;
        $egress->applyAgentWireConfiguration($this->buildConfiguration());
        return $egress;
    }

    protected function buildConfiguration(): AgentWireConfiguration
    {
        $agentWireConfiguration = new AgentWireConfiguration();
        $agentWireConfiguration->wireApi = RunSettings::AGENT_WIRE_API_MESSAGES;
        $agentWireConfiguration->skillLoadingStrategy = ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND;
        $agentWireConfiguration->previousReasoningCarryOver = RunSettings::PREVIOUS_REASONING_CARRY_OVER_CAPPED;
        return $agentWireConfiguration;
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveProviderPreference(ArgusAIAgentConversation $egress): array
    {
        return (array)(new ReflectionMethod($egress, 'resolveOpenRouterProviderPreference'))->invoke($egress, $egress->getArgusLanguageModelInstance(), $egress->getAIModel());
    }
}
