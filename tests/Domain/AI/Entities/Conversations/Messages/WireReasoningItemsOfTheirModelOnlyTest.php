<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentPreviousReasoningCut;
use DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A model never receives another model's reasoning items: a conversation may switch models mid-way. Items stored
 * without a model name go back only on the Responses API (model-bound encrypted items); on Chat Completions they are
 * dropped.
 */
class WireReasoningItemsOfTheirModelOnlyTest extends UnitTestCase
{
    public function testItemsGoBackToTheModelThatProducedThem(): void
    {
        $requestSnapshot = static::buildSnapshot(AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS, 'MOONSHOT.KIMI_K3_1_PRO');

        self::assertNotNull($requestSnapshot->findWireReasoningItemsFor(AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS, 'MOONSHOT.KIMI_K3_1_PRO'));
        self::assertNull($requestSnapshot->findWireReasoningItemsFor(AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS, 'ANTHROPIC.CLAUDE_OPUS_5_5'));
        self::assertNull($requestSnapshot->findWireReasoningItemsFor(AIModel::AGENT_WIRE_API_RESPONSES, 'MOONSHOT.KIMI_K3_1_PRO'), 'other API');
        self::assertNotNull($requestSnapshot->findWireReasoningItemsFor(AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS), 'no model named: API only');
    }

    public function testItemsWithoutAModelNameAreDroppedOnChatCompletionsAndKeptOnResponses(): void
    {
        self::assertNull(static::buildSnapshot(AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS, null)
            ->findWireReasoningItemsFor(AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS, 'MOONSHOT.KIMI_K3_1_PRO'));
        self::assertNotNull(static::buildSnapshot(AIModel::AGENT_WIRE_API_RESPONSES, null)
            ->findWireReasoningItemsFor(AIModel::AGENT_WIRE_API_RESPONSES, 'OPENAI.GPT6_LUNA'));
    }

    public function testTheCappedCutCountsOnlyTheItemsTheCallWouldSend(): void
    {
        $requestSnapshot = static::buildSnapshot(AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS, 'MOONSHOT.KIMI_K3_1_PRO');
        $requestSnapshot->wireReasoningTokens = 700;

        self::assertSame(700, AgentPreviousReasoningCut::estimateReasoningTokensOfStep($requestSnapshot, AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS, 'MOONSHOT.KIMI_K3_1_PRO'));
        self::assertNull(AgentPreviousReasoningCut::estimateReasoningTokensOfStep($requestSnapshot, AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS, 'OPENAI.GPT6_LUNA'));
    }

    protected static function buildSnapshot(string $wireApi, ?string $modelName): RequestSnapshot
    {
        $requestSnapshot = new RequestSnapshot();
        $requestSnapshot->modelName = $modelName;
        $requestSnapshot->wireReasoningApi = $wireApi;
        $requestSnapshot->wireReasoningItemsJson = '[{"type":"reasoning.text","text":"slide 3 has an overlapping title","index":0}]';
        return $requestSnapshot;
    }
}
