<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\Chat;

use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Symfony\Commands\Agents\Chat\AgentConsoleChatScope;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A new console conversation is stamped from the model the command runs on (`--model`), not from the agentic default:
 * a Chat Completions model gets the swap even when the default speaks the Responses API. A named strategy is kept.
 */
class ConsoleChatNewConversationLeavesTheStrategyToItsModelTest extends UnitTestCase
{
    public function testAChatCompletionsModelOfTheCommandStampsTheSwap(): void
    {
        $consoleChatScopeProbe = new ConsoleChatScopeProbe();
        $consoleChatScopeProbe->agentWireApiByModelName = [AIModel::MODEL_ANTHROPIC_CLAUDE_OPUS_5_5 => AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS];

        $conversation = $consoleChatScopeProbe->buildNewConversationForTest(new AgentConsoleChatScope(), AIModel::MODEL_ANTHROPIC_CLAUDE_OPUS_5_5, null);

        self::assertSame(ContextRetentionSettings::SKILL_LOADING_STRATEGY_SYSTEM_PROMPT_SWAP, $conversation->contextRetentionSettings->skillLoadingStrategy);
    }

    public function testARequestedStrategyIsStamped(): void
    {
        $conversation = (new ConsoleChatScopeProbe())->buildNewConversationForTest(
            new AgentConsoleChatScope(),
            null,
            ContextRetentionSettings::SKILL_LOADING_STRATEGY_SYSTEM_PROMPT_SWAP
        );

        self::assertSame(ContextRetentionSettings::SKILL_LOADING_STRATEGY_SYSTEM_PROMPT_SWAP, $conversation->contextRetentionSettings->skillLoadingStrategy);
    }
}
