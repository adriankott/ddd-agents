<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Wire;

use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentWireConfiguration;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Models\Settings\AILanguageModelSetting;
use DDD\Domain\AI\Entities\Models\Settings\AIModelAgenticUseCaseConfig;
use DDD\Domain\AI\Entities\Models\Settings\AIModelPreviousReasoningCarryOver;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;

/**
 * A model's catalog carry-over of its own previous reasoning: it applies on Chat Completions over the direct
 * OpenRouter egress (where `reasoning_details` reach the provider) and on the Responses API; the conversation's row
 * still wins; without the catalog value the per-API default stays.
 */
class ModelCatalogPreviousReasoningCarryOverTest extends UnitTestCase
{
    public function testTheCatalogValueAppliesOnChatCompletionsOverDirectOpenRouter(): void
    {
        $agentWireConfiguration = AgentWireConfiguration::resolveFor(
            static::buildModel(AIModelPreviousReasoningCarryOver::CARRY_OVER_CURRENT_TURN),
            null,
            null,
            true
        );

        self::assertSame(AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS, $agentWireConfiguration->wireApi);
        self::assertSame(RunSettings::PREVIOUS_REASONING_CARRY_OVER_CURRENT_TURN, $agentWireConfiguration->previousReasoningCarryOver);
        self::assertTrue($agentWireConfiguration->carriesOnlyTheCurrentTurnsReasoning());
    }

    public function testTheCatalogValueDoesNotApplyThroughAProxyOnChatCompletions(): void
    {
        $agentWireConfiguration = AgentWireConfiguration::resolveFor(
            static::buildModel(AIModelPreviousReasoningCarryOver::CARRY_OVER_CURRENT_TURN),
            null,
            null,
            false
        );

        self::assertSame(RunSettings::PREVIOUS_REASONING_CARRY_OVER_OFF, $agentWireConfiguration->previousReasoningCarryOver);
    }

    public function testTheConversationsRowWinsOverTheCatalog(): void
    {
        self::assertSame(
            RunSettings::PREVIOUS_REASONING_CARRY_OVER_OFF,
            AgentWireConfiguration::resolvePreviousReasoningCarryOver(
                RunSettings::PREVIOUS_REASONING_CARRY_OVER_OFF,
                AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS,
                AIModelPreviousReasoningCarryOver::CARRY_OVER_CURRENT_TURN
            )
        );
    }

    public function testWithoutACatalogValueThePerApiDefaultStays(): void
    {
        self::assertSame(
            RunSettings::PREVIOUS_REASONING_CARRY_OVER_OFF,
            AgentWireConfiguration::resolvePreviousReasoningCarryOver(null, AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS)
        );
        self::assertSame(
            RunSettings::PREVIOUS_REASONING_CARRY_OVER_CAPPED,
            AgentWireConfiguration::resolvePreviousReasoningCarryOver(null, AIModel::AGENT_WIRE_API_RESPONSES)
        );
        self::assertSame(
            RunSettings::PREVIOUS_REASONING_CARRY_OVER_OFF,
            AgentWireConfiguration::resolvePreviousReasoningCarryOver(
                null,
                RunSettings::AGENT_WIRE_API_RESPONSES_SERVER_STATE,
                AIModelPreviousReasoningCarryOver::CARRY_OVER_ALL
            ),
            'the server-state arm never carries items'
        );
    }

    protected static function buildModel(?string $previousReasoningCarryOver): AIModel
    {
        /** @var AIModel $aiModel */
        $aiModel = (new ReflectionClass(AIModel::class))->newInstanceWithoutConstructor();
        /** @var AILanguageModelSetting $aiLanguageModelSetting */
        $aiLanguageModelSetting = (new ReflectionClass(AILanguageModelSetting::class))->newInstanceWithoutConstructor();
        $aiModel->settings = $aiLanguageModelSetting;
        $aiModel->vendor = AIModel::VENDOR_MOONSHOT;
        $aiModel->agenticUseCase = new AIModelAgenticUseCaseConfig(previousReasoning: new AIModelPreviousReasoningCarryOver($previousReasoningCarryOver));
        return $aiModel;
    }
}
