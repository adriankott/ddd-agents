<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Wire;

use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentWireConfiguration;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Models\Settings\AILanguageModelSetting;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;

/**
 * A fresh install answers: the package's default `skillLoadingStrategy` is `conversationAppend`, and a catalog model
 * whose entry names no `settings.agentWireApi` speaks Chat Completions, where append-only loading is refused. The
 * default must fall back to the system-prompt swap on every path — a creation that knows the wire API, a creation that
 * does not (left unstamped for the run), and a run over a conversation without a stamp. A strategy a caller names is
 * still refused on Chat Completions.
 */
class FreshInstallDefaultsAnswerTheFirstTurnTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (ContextRetentionSettings::resolveDefaultSkillLoadingStrategy() !== ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND) {
            self::fail('the package default of AI.contextRetention.skillLoadingStrategy is conversationAppend');
        }
    }

    public function testACatalogModelWithoutAWireApiSpeaksChatCompletions(): void
    {
        self::assertSame(AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS, AgentWireConfiguration::resolveWireApiFor(static::buildModelWithoutAgentWireApi(), null));
    }

    public function testACreationOnChatCompletionsIsStampedWithTheSwap(): void
    {
        $contextRetentionSettings = ContextRetentionSettings::buildForNewConversation(null, null, agentWireApi: AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS);

        self::assertSame(ContextRetentionSettings::SKILL_LOADING_STRATEGY_SYSTEM_PROMPT_SWAP, $contextRetentionSettings->skillLoadingStrategy);
        self::assertFalse(AgentWireConfiguration::resolveFor(static::buildModelWithoutAgentWireApi(), null, $contextRetentionSettings, false)->isRejected());
    }

    public function testACreationThatDoesNotKnowTheWireApiLeavesTheStrategyToTheRun(): void
    {
        $contextRetentionSettings = ContextRetentionSettings::buildForNewConversation(null, null);

        self::assertNull($contextRetentionSettings->skillLoadingStrategy);
        self::assertSame(
            ContextRetentionSettings::SKILL_LOADING_STRATEGY_SYSTEM_PROMPT_SWAP,
            $contextRetentionSettings->getSkillLoadingStrategyForAgentWireApi(AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS)
        );
        self::assertSame(
            ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND,
            $contextRetentionSettings->getSkillLoadingStrategyForAgentWireApi(AIModel::AGENT_WIRE_API_RESPONSES)
        );
    }

    public function testARunOverAnUnstampedConversationOnChatCompletionsIsNotRejected(): void
    {
        foreach ([null, new ContextRetentionSettings()] as $contextRetentionSettings) {
            $agentWireConfiguration = AgentWireConfiguration::resolveFor(static::buildModelWithoutAgentWireApi(), null, $contextRetentionSettings, false);

            self::assertFalse($agentWireConfiguration->isRejected(), (string)$agentWireConfiguration->rejectionReason);
            self::assertSame(AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS, $agentWireConfiguration->wireApi);
            self::assertSame(ContextRetentionSettings::SKILL_LOADING_STRATEGY_SYSTEM_PROMPT_SWAP, $agentWireConfiguration->skillLoadingStrategy);
        }
    }

    public function testANamedAppendOnlyStrategyIsStillRefusedOnChatCompletions(): void
    {
        $contextRetentionSettings = ContextRetentionSettings::buildForNewConversation(
            null,
            ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND,
            agentWireApi: AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS
        );

        $agentWireConfiguration = AgentWireConfiguration::resolveFor(static::buildModelWithoutAgentWireApi(), null, $contextRetentionSettings, false);

        self::assertTrue($agentWireConfiguration->isRejected());
        self::assertSame(AgentWireConfiguration::REJECTION_CONVERSATION_APPEND_NEEDS_RESPONSES, $agentWireConfiguration->rejectionReason);
    }

    /** A catalog model whose settings name no agent wire API. */
    protected static function buildModelWithoutAgentWireApi(): AIModel
    {
        /** @var AIModel $aiModel */
        $aiModel = (new ReflectionClass(AIModel::class))->newInstanceWithoutConstructor();
        /** @var AILanguageModelSetting $aiLanguageModelSetting */
        $aiLanguageModelSetting = (new ReflectionClass(AILanguageModelSetting::class))->newInstanceWithoutConstructor();
        $aiModel->settings = $aiLanguageModelSetting;
        $aiModel->vendor = AIModel::VENDOR_OPENAI;
        return $aiModel;
    }
}
