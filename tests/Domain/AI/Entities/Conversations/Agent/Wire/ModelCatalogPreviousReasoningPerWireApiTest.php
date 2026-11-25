<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Wire;

use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentWireConfiguration;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Models\Settings\AILanguageModelSetting;
use DDD\Domain\AI\Entities\Models\Settings\AIModelAgenticUseCaseConfig;
use DDD\Domain\AI\Entities\Models\Settings\AIModelPreviousReasoningCarryOver;
use DDD\Domain\AI\Entities\Models\Settings\AIModelWireApiPreviousReasoningCarryOver;
use DDD\Domain\AI\Entities\Models\Settings\AIModelWireApiPreviousReasoningCarryOvers;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;

/**
 * A model's catalog carry-over per wire API (`agenticUseCase.previousReasoningByWireApi`): the run reads the value of
 * the wire it actually speaks — the catalog's wire, the wire a fallback chose, or the run settings' row.
 */
class ModelCatalogPreviousReasoningPerWireApiTest extends UnitTestCase
{
    public function testTheValueOfTheResolvedWireIsUsed(): void
    {
        $agentWireConfiguration = AgentWireConfiguration::resolveFor(static::buildModelOnTheResponsesApi(), null, null, true);

        self::assertSame(AIModel::AGENT_WIRE_API_RESPONSES, $agentWireConfiguration->wireApi);
        self::assertSame(RunSettings::PREVIOUS_REASONING_CARRY_OVER_CAPPED, $agentWireConfiguration->previousReasoningCarryOver);
        self::assertSame(6000, $agentWireConfiguration->previousReasoningCapTokens);
    }

    public function testARunThatFallsBackToAnotherWireGetsThatWiresValue(): void
    {
        // without the direct OpenRouter egress the catalog's Responses API falls back to Chat Completions
        $agentWireConfiguration = AgentWireConfiguration::resolveFor(static::buildModelOnTheResponsesApi(), null, null, false);

        self::assertSame(AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS, $agentWireConfiguration->wireApi);
        self::assertSame(2500, $agentWireConfiguration->previousReasoningCapTokens, 'the cap of the Chat Completions entry');
    }

    public function testTheWireOfTheRunSettingsRowDecidesTheValue(): void
    {
        $runSettings = new RunSettings();
        $runSettings->agentWireApi = AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS;

        $agentWireConfiguration = AgentWireConfiguration::resolveFor(static::buildModelOnTheResponsesApi(), $runSettings, null, true);

        self::assertSame(AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS, $agentWireConfiguration->wireApi);
        self::assertSame(RunSettings::PREVIOUS_REASONING_CARRY_OVER_CURRENT_TURN, $agentWireConfiguration->previousReasoningCarryOver);
        self::assertSame(2500, $agentWireConfiguration->previousReasoningCapTokens);
    }

    /** A model whose catalog speaks the Responses API, with a value per wire and a model-wide one that neither run reads */
    protected static function buildModelOnTheResponsesApi(): AIModel
    {
        /** @var AIModel $aiModel */
        $aiModel = (new ReflectionClass(AIModel::class))->newInstanceWithoutConstructor();
        /** @var AILanguageModelSetting $aiLanguageModelSetting */
        $aiLanguageModelSetting = (new ReflectionClass(AILanguageModelSetting::class))->newInstanceWithoutConstructor();
        $aiLanguageModelSetting->agentWireApi = AIModel::AGENT_WIRE_API_RESPONSES;
        $aiModel->settings = $aiLanguageModelSetting;
        $aiModel->vendor = AIModel::VENDOR_OPENAI;
        $previousReasoningByWireApi = new AIModelWireApiPreviousReasoningCarryOvers();
        $previousReasoningByWireApi->add(new AIModelWireApiPreviousReasoningCarryOver(
            AIModel::AGENT_WIRE_API_RESPONSES,
            new AIModelPreviousReasoningCarryOver(AIModelPreviousReasoningCarryOver::CARRY_OVER_CAPPED, 6000)
        ));
        $previousReasoningByWireApi->add(new AIModelWireApiPreviousReasoningCarryOver(
            AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS,
            new AIModelPreviousReasoningCarryOver(AIModelPreviousReasoningCarryOver::CARRY_OVER_CURRENT_TURN, 2500)
        ));
        $aiModel->agenticUseCase = new AIModelAgenticUseCaseConfig(
            previousReasoning: new AIModelPreviousReasoningCarryOver(AIModelPreviousReasoningCarryOver::CARRY_OVER_OFF, 1000),
            previousReasoningByWireApi: $previousReasoningByWireApi
        );
        return $aiModel;
    }
}
