<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\Conversations\Wire;

use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentWireConfiguration;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation;
use DDD\Domain\AI\Services\AIModelCallUsageRecorderService;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The booking of a call on the Anthropic Messages API, from a recorded answer of Claude Opus 5.5 over OpenRouter
 * (2026-11-24: 2 uncached input tokens, 5,869 read from the cache, 185 written with the 5-minute lifetime, 117 output
 * tokens; the provider billed 0.0044468 USD): the booked input is the whole prompt, the read and the write are booked
 * at their own rates, the catalog prices give exactly the provider's amount, and no service tier is noted for a
 * Messages body, so nothing multiplies the price.
 */
class MessagesAnswerBookingTest extends IntegrationTestCase
{
    protected const string RECORDED_ANSWER = '{"id":"gen-recorded","type":"message","role":"assistant","model":"anthropic/claude-opus-5.5","provider":"Anthropic",'
        . '"content":[{"type":"thinking","thinking":"The second order is next.","signature":"c2ln"},{"type":"text","text":"Both orders go to Munich."}],'
        . '"stop_reason":"end_turn","usage":{"input_tokens":2,"output_tokens":117,"output_tokens_details":{"thinking_tokens":24},'
        . '"cache_creation_input_tokens":185,"cache_read_input_tokens":5869,"cache_creation":{"ephemeral_5m_input_tokens":185,"ephemeral_1h_input_tokens":0},'
        . '"service_tier":null,"speed":"standard","cost":0.0044468,"is_byok":false,'
        . '"cost_details":{"upstream_inference_cost":0.0044468,"upstream_inference_prompt_cost":0.0021068,"upstream_inference_completions_cost":0.00234}}}';

    protected RecordingUsageRecorderDouble $usageRecorderDouble;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usageRecorderDouble = new RecordingUsageRecorderDouble();
        $this->replaceService(AIModelCallUsageRecorderService::class, $this->usageRecorderDouble);
    }

    public function testTheRecordedAnswerIsBookedWithItsThreeInputCountsAtTheProvidersAmount(): void
    {
        $egress = $this->buildEgressOnMessages();

        $recordedAnswer = json_decode(self::RECORDED_ANSWER);
        $egress->handleLoadResponse($recordedAnswer);

        self::assertSame(6056, $egress->getLastInputTokens(), 'the booked input is the whole prompt: uncached + cache read + cache write');
        self::assertSame(117, $egress->getLastOutputTokens());
        self::assertSame(5869, $egress->getLastCachedInputTokens());
        self::assertSame(185, $egress->getLastCacheWriteTokens());
        self::assertCount(1, $this->usageRecorderDouble->recordedUsages);
        $recordedUsage = $this->usageRecorderDouble->recordedUsages[0];
        self::assertSame(6056, $recordedUsage->inputTokens);
        self::assertSame(5869, $recordedUsage->cachedInputTokens);
        self::assertSame(185, $recordedUsage->cacheWriteTokens);
        self::assertEqualsWithDelta(0.0044468, $recordedUsage->costs->amount, 0.0000001, 'the provider\'s amount is booked');
    }

    public function testTheCatalogPricesOfTheCallGiveTheProvidersAmount(): void
    {
        $egress = $this->buildEgressOnMessages();
        $aiModel = $egress->getAIModel();
        self::assertNotNull($aiModel);
        $promptCacheWriteTtl = (new ReflectionMethod($egress, 'getPromptCacheWriteTtl'))->invoke($egress);

        self::assertSame(AIModel::PROMPT_CACHE_WRITE_TTL_DEFAULT, $promptCacheWriteTtl, 'the request marks its cache entries, so its writes are priced at the write rate of the 5-minute lifetime');
        self::assertEqualsWithDelta(
            0.0044468,
            $aiModel->calculateEstimatedCostAmount(6056, 117, null, 5869, 0, 185, $promptCacheWriteTtl),
            0.0000001,
            '2 uncached at the input rate, 5,869 at the read rate, 185 at 1.25 times the input rate (the lifetime the answer names: ephemeral_5m), 117 at the output rate'
        );
    }

    public function testNoServiceTierIsNotedForAMessagesBody(): void
    {
        $egress = $this->buildEgressOnMessages();
        $egress->fastModeRequested = true;
        $aiModel = $egress->getAIModel();
        self::assertNotNull($aiModel);
        // a model that could run on the priority tier: on Chat Completions the egress would note the tier and the
        // booking would multiply the price
        $aiModel->supportsFastMode = true;
        $aiModel->fastModePriceMultiplier = 2.0;

        $agentWireRequestParameters = (new ReflectionMethod($egress, 'buildAgentWireRequestParameters'))->invoke($egress, $aiModel, true);
        $recordedAnswer = json_decode(self::RECORDED_ANSWER);
        $egress->handleLoadResponse($recordedAnswer);

        self::assertNull($agentWireRequestParameters->serviceTier);
        self::assertNull((new ReflectionProperty($egress, 'lastRequestServiceTier'))->getValue($egress));
        self::assertEqualsWithDelta(0.0044468, $this->usageRecorderDouble->recordedUsages[0]->costs->amount, 0.0000001);
    }

    protected function buildEgressOnMessages(): ArgusAIAgentConversation
    {
        $egress = new ArgusAIAgentConversation();
        $egress->aiModelNameOverride = AIModel::MODEL_ANTHROPIC_CLAUDE_OPUS_5_5;
        (new ReflectionProperty($egress, 'userContent'))->setValue($egress, [['role' => 'user', 'content' => 'Where do both orders go?']]);
        $agentWireConfiguration = new AgentWireConfiguration();
        $agentWireConfiguration->wireApi = RunSettings::AGENT_WIRE_API_MESSAGES;
        $agentWireConfiguration->skillLoadingStrategy = ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND;
        $agentWireConfiguration->previousReasoningCarryOver = RunSettings::PREVIOUS_REASONING_CARRY_OVER_CAPPED;
        $egress->applyAgentWireConfiguration($agentWireConfiguration);
        return $egress;
    }
}
