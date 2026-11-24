<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Wire;

use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentWireConfiguration;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Models\Settings\AILanguageModelSetting;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;

/**
 * The Anthropic Messages API in the wire configuration: an Anthropic model whose catalog entry names it speaks it over
 * the direct OpenRouter egress, with append-only skill loading and the capped carry-over by default; a catalog value
 * the egress cannot serve falls back to Chat Completions, a row of the conversation is refused instead.
 */
class MessagesWireConfigurationTest extends UnitTestCase
{
    public function testACatalogModelOnMessagesSpeaksItOverTheDirectEgressWithAppendOnlyLoadingAndTheCappedCarryOver(): void
    {
        $agentWireConfiguration = AgentWireConfiguration::resolveFor(static::buildModel(AIModel::VENDOR_ANTHROPIC, AIModel::AGENT_WIRE_API_MESSAGES), null, null, true);

        self::assertTrue($agentWireConfiguration->usesMessagesApi());
        self::assertFalse($agentWireConfiguration->usesResponsesApi());
        self::assertFalse($agentWireConfiguration->isRejected());
        self::assertTrue($agentWireConfiguration->usesConversationAppend());
        self::assertSame(RunSettings::PREVIOUS_REASONING_CARRY_OVER_CAPPED, $agentWireConfiguration->previousReasoningCarryOver);
        self::assertTrue($agentWireConfiguration->carriesCappedPreviousReasoning());
    }

    public function testACatalogValueTheEgressCannotServeFallsBackToChatCompletions(): void
    {
        $agentWireConfiguration = AgentWireConfiguration::resolveFor(static::buildModel(AIModel::VENDOR_ANTHROPIC, AIModel::AGENT_WIRE_API_MESSAGES), null, null, false);

        self::assertSame(AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS, $agentWireConfiguration->wireApi);
        self::assertFalse($agentWireConfiguration->isRejected(), 'a catalog default never makes a model unusable');
        self::assertFalse($agentWireConfiguration->usesConversationAppend());
    }

    public function testTheCatalogValueIsMatchedWhateverItsCaseAndBlanks(): void
    {
        self::assertSame(AIModel::AGENT_WIRE_API_MESSAGES, AgentWireConfiguration::resolveWireApiOfCatalogValue(static::buildModel(AIModel::VENDOR_ANTHROPIC, ' Messages ')));
        self::assertSame(AIModel::AGENT_WIRE_API_RESPONSES, AgentWireConfiguration::resolveWireApiOfCatalogValue(static::buildModel(AIModel::VENDOR_OPENAI, 'RESPONSES')));
        self::assertSame(AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS, AgentWireConfiguration::resolveWireApiOfCatalogValue(static::buildModel(AIModel::VENDOR_OPENAI, null)));
    }

    public function testACatalogValueThisAgentDoesNotSpeakIsChatCompletionsAndIsLoggedOncePerModel(): void
    {
        AgentWireConfigurationWithRecordedLog::$loggedValues = [];
        $modelOfANewerCatalog = static::buildModel(AIModel::VENDOR_ANTHROPIC, 'realtime');
        $modelOfANewerCatalog->name = 'TEST.MODEL_OF_A_NEWER_CATALOG';

        $agentWireConfiguration = AgentWireConfigurationWithRecordedLog::resolveFor($modelOfANewerCatalog, null, null, true);
        AgentWireConfigurationWithRecordedLog::resolveFor($modelOfANewerCatalog, null, null, true);

        self::assertSame(AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS, $agentWireConfiguration->wireApi);
        self::assertFalse($agentWireConfiguration->isRejected(), 'a catalog value never makes a model unusable');
        self::assertFalse($agentWireConfiguration->usesConversationAppend(), 'the default strategy follows the wire API the agent speaks');
        self::assertSame([['TEST.MODEL_OF_A_NEWER_CATALOG', 'realtime']], AgentWireConfigurationWithRecordedLog::$loggedValues, 'one line per model and process');
    }

    public function testTheServerStateArmIsNoCatalogValue(): void
    {
        AgentWireConfigurationWithRecordedLog::$loggedValues = [];
        $model = static::buildModel(AIModel::VENDOR_OPENAI, RunSettings::AGENT_WIRE_API_RESPONSES_SERVER_STATE);
        $model->name = 'TEST.SERVER_STATE_IN_THE_CATALOG';

        self::assertSame(AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS, AgentWireConfigurationWithRecordedLog::resolveWireApiFor($model, null, true));
        self::assertCount(1, AgentWireConfigurationWithRecordedLog::$loggedValues);
    }

    public function testARowOfTheConversationIsKeptAndRefusedWhereItCannotRun(): void
    {
        $runSettings = new RunSettings();
        $runSettings->agentWireApi = RunSettings::AGENT_WIRE_API_MESSAGES;

        $throughAProxy = AgentWireConfiguration::resolveFor(static::buildModel(AIModel::VENDOR_ANTHROPIC, null), $runSettings, null, false);
        self::assertTrue($throughAProxy->usesMessagesApi());
        self::assertSame(AgentWireConfiguration::REJECTION_MESSAGES_NEEDS_DIRECT_OPENROUTER, $throughAProxy->rejectionReason);

        $otherVendor = AgentWireConfiguration::resolveFor(static::buildModel(AIModel::VENDOR_OPENAI, null), $runSettings, null, true);
        self::assertSame(AgentWireConfiguration::REJECTION_MESSAGES_NEEDS_ANTHROPIC_MODEL, $otherVendor->rejectionReason);

        $anthropicDirect = AgentWireConfiguration::resolveFor(static::buildModel(AIModel::VENDOR_ANTHROPIC, null), $runSettings, null, true);
        self::assertFalse($anthropicDirect->isRejected());
    }

    public function testBothSkillLoadingStrategiesRunOnMessages(): void
    {
        foreach ([ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND, ContextRetentionSettings::SKILL_LOADING_STRATEGY_SYSTEM_PROMPT_SWAP] as $skillLoadingStrategy) {
            self::assertNull(AgentWireConfiguration::findRejectionReason(AIModel::AGENT_WIRE_API_MESSAGES, $skillLoadingStrategy, AIModel::VENDOR_ANTHROPIC, true));
        }
    }

    public function testTheRowOfTheConversationAndTheCatalogValueWinOverTheCappedDefault(): void
    {
        self::assertSame(
            RunSettings::PREVIOUS_REASONING_CARRY_OVER_ALL,
            AgentWireConfiguration::resolvePreviousReasoningCarryOver(RunSettings::PREVIOUS_REASONING_CARRY_OVER_ALL, AIModel::AGENT_WIRE_API_MESSAGES, AIModel::PREVIOUS_REASONING_CARRY_OVER_CURRENT_TURN)
        );
        self::assertSame(
            RunSettings::PREVIOUS_REASONING_CARRY_OVER_CURRENT_TURN,
            AgentWireConfiguration::resolvePreviousReasoningCarryOver(null, AIModel::AGENT_WIRE_API_MESSAGES, AIModel::PREVIOUS_REASONING_CARRY_OVER_CURRENT_TURN)
        );
        self::assertSame(
            RunSettings::PREVIOUS_REASONING_CARRY_OVER_CAPPED,
            AgentWireConfiguration::resolvePreviousReasoningCarryOver(null, AIModel::AGENT_WIRE_API_MESSAGES)
        );
    }

    public function testAnthropicsThinkingBlockErrorIsARejectionOfTheCarriedReasoning(): void
    {
        self::assertTrue(AgentWireConfiguration::providerErrorRejectsTheCarriedReasoningItems(
            '{"code":400,"message":"Provider returned error","metadata":{"raw":"messages.2.content.0: Invalid `signature` in `thinking` block"}}'
        ));
        self::assertFalse(AgentWireConfiguration::providerErrorRejectsTheCarriedReasoningItems('{"code":400,"message":"max_tokens: must be greater than 0"}'));
    }

    public function testOnlyTheAppendOnlyCapableWireApisAreNamed(): void
    {
        self::assertTrue(RunSettings::isAppendOnlyCapableAgentWireApi(RunSettings::AGENT_WIRE_API_MESSAGES));
        self::assertTrue(RunSettings::isAppendOnlyCapableAgentWireApi(RunSettings::AGENT_WIRE_API_RESPONSES));
        self::assertFalse(RunSettings::isAppendOnlyCapableAgentWireApi(RunSettings::AGENT_WIRE_API_CHAT_COMPLETIONS));
        self::assertSame(RunSettings::AGENT_WIRE_API_MESSAGES, RunSettings::canonicalAgentWireApi('Messages'));
    }

    protected static function buildModel(string $vendor, ?string $agentWireApi): AIModel
    {
        /** @var AIModel $aiModel */
        $aiModel = (new ReflectionClass(AIModel::class))->newInstanceWithoutConstructor();
        /** @var AILanguageModelSetting $aiLanguageModelSetting */
        $aiLanguageModelSetting = (new ReflectionClass(AILanguageModelSetting::class))->newInstanceWithoutConstructor();
        $aiLanguageModelSetting->agentWireApi = $agentWireApi;
        $aiModel->settings = $aiLanguageModelSetting;
        $aiModel->vendor = $vendor;
        $aiModel->previousReasoningCarryOver = null;
        return $aiModel;
    }
}
