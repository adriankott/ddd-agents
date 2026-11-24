<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval\Probes;

use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Evals\Probes\ReasoningReplayProbeResult;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Services\Eval\Probes\AgentReasoningReplayProbeService;
use DDD\Domain\AI\Services\Eval\Probes\AgentReasoningReplayProbeTransportService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The reasoning-replay probe without a network: the bodies it composes per scenario and the verdict it draws from
 * scripted OpenRouter answers.
 */
class AgentReasoningReplayProbeServiceTest extends UnitTestCase
{
    protected const string OPUS = 'PROBE.OPUS';
    protected const string OTHER = 'PROBE.OTHER';
    protected const string GPT = 'PROBE.GPT';

    public function testACleanReplaySendsTheReasoningBackVerbatimAndPasses(): void
    {
        $probe = $this->buildProbe([self::chatToolAnswer(), self::chatTextAnswer(1200, 1000)]);

        $result = $probe->runScenario(self::OPUS, AgentReasoningReplayProbeService::SCENARIO_CLEAN, RunSettings::AGENT_WIRE_API_CHAT_COMPLETIONS, false, null, 'medium', 60);

        self::assertSame(ReasoningReplayProbeResult::VERDICT_PASS, $result->verdict, $result->verdictReason);
        $secondBody = $probe->scriptedTransport->sentBodies[1];
        $assistantEntry = $secondBody['messages'][2];
        self::assertSame('assistant', $assistantEntry['role']);
        self::assertSame('sig-1', $assistantEntry['reasoning_details'][0]['signature'], 'replayed verbatim, signature included');
        self::assertSame('call_1', $secondBody['messages'][3]['tool_call_id']);
        self::assertSame('ephemeral', $secondBody['messages'][0]['content'][0]['cache_control']['type'], 'Anthropic cache marker on the system text');
        self::assertSame(['only' => ['anthropic']], $secondBody['provider'], 'the catalog provider filters ride along');
        self::assertArrayNotHasKey('thinking', $secondBody);
        $calls = $result->calls->getElements();
        self::assertTrue($calls[0]->hasSignedReasoning);
        self::assertSame(1, $calls[1]->replayedReasoningItemCount);
        self::assertSame(0.833, $calls[1]->getCacheShare());
    }

    public function testARefusedReplayAfterAnEditIsAFindingWithTheUpstreamText(): void
    {
        $probe = $this->buildProbe([self::chatToolAnswer(), self::refusal('thinking block prefix mismatch')]);

        $result = $probe->runScenario(self::OPUS, AgentReasoningReplayProbeService::SCENARIO_EDIT_HISTORY, RunSettings::AGENT_WIRE_API_CHAT_COMPLETIONS, true, null, 'medium', 60);

        self::assertSame(ReasoningReplayProbeResult::VERDICT_FINDING, $result->verdict);
        self::assertStringContainsString('thinking block prefix mismatch', $result->verdictReason);
        $secondBody = $probe->scriptedTransport->sentBodies[1];
        self::assertStringEndsWith(AgentReasoningReplayProbeService::HISTORY_EDIT_SUFFIX, $secondBody['messages'][1]['content'], 'the earlier message was re-rendered');
        self::assertSame('drop_block', $secondBody['thinking']['block_binding']['prefix_mismatch_behavior']);
        self::assertArrayNotHasKey('thinking', $probe->scriptedTransport->sentBodies[0], 'call 1 replays nothing');
    }

    public function testEditToolsAndEditSystemChangeThePrefix(): void
    {
        $toolsProbe = $this->buildProbe([self::chatToolAnswer(), self::chatTextAnswer(100, 0)]);
        $toolsProbe->runScenario(self::OPUS, AgentReasoningReplayProbeService::SCENARIO_EDIT_TOOLS, RunSettings::AGENT_WIRE_API_CHAT_COMPLETIONS, false, null, 'medium', 60);
        self::assertCount(2, $toolsProbe->scriptedTransport->sentBodies[0]['tools']);
        self::assertCount(3, $toolsProbe->scriptedTransport->sentBodies[1]['tools']);

        $systemProbe = $this->buildProbe([self::chatToolAnswer(), self::chatTextAnswer(100, 0)]);
        $systemProbe->runScenario(self::OPUS, AgentReasoningReplayProbeService::SCENARIO_EDIT_SYSTEM, RunSettings::AGENT_WIRE_API_CHAT_COMPLETIONS, false, null, 'medium', 60);
        self::assertStringEndsWith(AgentReasoningReplayProbeService::SYSTEM_EDIT_SUFFIX, $systemProbe->scriptedTransport->sentBodies[1]['messages'][0]['content'][0]['text']);
    }

    public function testCallOneWithoutReasoningOrRefusedCleanReplayFails(): void
    {
        $noReasoningProbe = $this->buildProbe([self::chatToolAnswer(false)]);
        $noReasoningResult = $noReasoningProbe->runScenario(self::OPUS, AgentReasoningReplayProbeService::SCENARIO_CLEAN, RunSettings::AGENT_WIRE_API_CHAT_COMPLETIONS, false, null, 'medium', 60);
        self::assertSame(ReasoningReplayProbeResult::VERDICT_FAIL, $noReasoningResult->verdict);
        self::assertCount(1, $noReasoningProbe->scriptedTransport->sentBodies, 'no replay without reasoning');

        $refusedProbe = $this->buildProbe([self::chatToolAnswer(), self::refusal('nope')]);
        $refusedResult = $refusedProbe->runScenario(self::OPUS, AgentReasoningReplayProbeService::SCENARIO_CLEAN, RunSettings::AGENT_WIRE_API_CHAT_COMPLETIONS, false, null, 'medium', 60);
        self::assertSame(ReasoningReplayProbeResult::VERDICT_FAIL, $refusedResult->verdict);

        $transportProbe = $this->buildProbe([ScriptedReasoningReplayProbeTransportService::answer(null, '', 'Operation timed out')]);
        $transportResult = $transportProbe->runScenario(self::OPUS, AgentReasoningReplayProbeService::SCENARIO_CLEAN, RunSettings::AGENT_WIRE_API_CHAT_COMPLETIONS, false, null, 'medium', 60);
        self::assertSame(ReasoningReplayProbeResult::VERDICT_FAIL, $transportResult->verdict);
        self::assertStringContainsString('transport: Operation timed out', $transportResult->verdictReason);
    }

    public function testModelSwitchSendsCallTwoToTheOtherModel(): void
    {
        $probe = $this->buildProbe([self::chatToolAnswer(), self::chatTextAnswer(100, 0)]);

        $result = $probe->runScenario(self::OPUS, AgentReasoningReplayProbeService::SCENARIO_MODEL_SWITCH, RunSettings::AGENT_WIRE_API_CHAT_COMPLETIONS, false, self::OTHER, 'medium', 60);

        self::assertSame('anthropic/probe-opus', $probe->scriptedTransport->sentBodies[0]['model']);
        self::assertSame('vendor/probe-other', $probe->scriptedTransport->sentBodies[1]['model']);
        self::assertSame(self::OTHER, $result->switchModelName);
        $this->expectException(BadRequestException::class);
        $probe->runScenario(self::OPUS, AgentReasoningReplayProbeService::SCENARIO_MODEL_SWITCH, RunSettings::AGENT_WIRE_API_CHAT_COMPLETIONS, false, null, 'medium', 60);
    }

    public function testForcedToolChoiceSendsThePinThenAutoWithTheToolExpectedLine(): void
    {
        $probe = $this->buildProbe([
            self::refusal('tool_choice: type "tool" and "any" are not supported for this model.'),
            self::chatToolAnswer(),
        ]);

        $result = $probe->runScenario(self::OPUS, AgentReasoningReplayProbeService::SCENARIO_FORCED_TOOL_CHOICE, RunSettings::AGENT_WIRE_API_CHAT_COMPLETIONS, false, null, 'medium', 60);

        self::assertSame(ReasoningReplayProbeResult::VERDICT_FINDING, $result->verdict);
        self::assertStringContainsString('the model called getWeather', $result->verdictReason);
        self::assertSame(['type' => 'function', 'function' => ['name' => 'getWeather']], $probe->scriptedTransport->sentBodies[0]['tool_choice']);
        $noteBody = $probe->scriptedTransport->sentBodies[1];
        self::assertSame('auto', $noteBody['tool_choice']);
        self::assertStringContainsString('[SIGNAL:tool-expected]', (string)json_encode(end($noteBody['messages'])));
    }

    public function testAppendOnlyOnResponsesAppendsToolsAndCarriesTheReasoningOfBothCalls(): void
    {
        $probe = $this->buildProbe([self::responsesToolAnswer('getWeather', 'rs_1'), self::responsesToolAnswer('getAirQuality', 'rs_2'), self::responsesTextAnswer()]);

        $result = $probe->runScenario(self::OPUS, AgentReasoningReplayProbeService::SCENARIO_APPEND_ONLY, RunSettings::AGENT_WIRE_API_RESPONSES, false, null, 'medium', 60);

        self::assertSame(ReasoningReplayProbeResult::VERDICT_PASS, $result->verdict, $result->verdictReason);
        self::assertTrue($result->bypassesResponsesRejection, 'a non-OpenAI model on Responses is the bypass the output names');
        self::assertSame(AgentReasoningReplayProbeTransportService::RESPONSES_URL, $probe->scriptedTransport->calledUrls[0]);
        $secondBody = $probe->scriptedTransport->sentBodies[1];
        self::assertCount(2, $secondBody['tools'], 'the top-level tools stay unchanged');
        $inputTypes = array_map(static fn (array $inputItem): string => (string)($inputItem['type'] ?? $inputItem['role'] ?? ''), $secondBody['input']);
        self::assertContains('additional_tools', $inputTypes);
        self::assertContains('reasoning', $inputTypes);
        $thirdBody = $probe->scriptedTransport->sentBodies[2];
        $reasoningIds = [];
        foreach ($thirdBody['input'] as $inputItem) {
            if (($inputItem['type'] ?? null) === 'reasoning') {
                $reasoningIds[] = $inputItem['id'];
            }
        }
        self::assertSame(['rs_1', 'rs_2'], $reasoningIds);
        self::assertSame(2, $result->calls->getElements()[2]->replayedReasoningItemCount);
    }

    public function testAppendOnlyNeedsTheResponsesWireAndAnUnknownModelIsRefused(): void
    {
        $probe = $this->buildProbe([]);
        try {
            $probe->runScenario(self::OPUS, AgentReasoningReplayProbeService::SCENARIO_APPEND_ONLY, RunSettings::AGENT_WIRE_API_CHAT_COMPLETIONS, false, null, 'medium', 60);
            self::fail('append-only on Chat Completions must be refused');
        } catch (BadRequestException) {
        }
        $this->expectException(BadRequestException::class);
        $probe->runScenario('PROBE.UNKNOWN', AgentReasoningReplayProbeService::SCENARIO_CLEAN, RunSettings::AGENT_WIRE_API_CHAT_COMPLETIONS, false, null, 'medium', 60);
    }

    public function testAnOpenAIModelOnResponsesIsNoBypass(): void
    {
        $probe = $this->buildProbe([self::responsesToolAnswer('getWeather', 'rs_1'), self::responsesTextAnswer()]);

        $result = $probe->runScenario(self::GPT, AgentReasoningReplayProbeService::SCENARIO_CLEAN, RunSettings::AGENT_WIRE_API_RESPONSES, false, null, 'medium', 60);

        self::assertFalse($result->bypassesResponsesRejection);
        self::assertSame(ReasoningReplayProbeResult::VERDICT_PASS, $result->verdict, $result->verdictReason);
    }

    /**
     * @param \DDD\Domain\AI\Entities\Evals\Probes\ReasoningReplayProbeHttpAnswer[] $scriptedAnswers
     */
    protected function buildProbe(array $scriptedAnswers): ScriptedAgentReasoningReplayProbeService
    {
        $probe = new ScriptedAgentReasoningReplayProbeService();
        $probe->scriptedTransport = new ScriptedReasoningReplayProbeTransportService();
        $probe->scriptedTransport->scriptedAnswers = $scriptedAnswers;
        $probe->modelsByName = [
            self::OPUS => self::buildModel(self::OPUS, 'anthropic/probe-opus', AIModel::VENDOR_ANTHROPIC, true),
            self::OTHER => self::buildModel(self::OTHER, 'vendor/probe-other', AIModel::VENDOR_ANTHROPIC, false),
            self::GPT => self::buildModel(self::GPT, 'openai/probe-gpt', AIModel::VENDOR_OPENAI, false),
        ];
        return $probe;
    }

    protected static function buildModel(string $name, string $openRouterExternalId, string $vendor, bool $usesAnthropicCacheControl): AIModel
    {
        $aiModel = new AIModel();
        $aiModel->name = $name;
        $aiModel->openRouterExternalId = $openRouterExternalId;
        $aiModel->vendor = $vendor;
        $settings = new \DDD\Domain\AI\Entities\Models\Settings\AILanguageModelSetting();
        $aiModel->settings = $settings;
        if ($usesAnthropicCacheControl) {
            $settings->promptCacheMode = AIModel::PROMPT_CACHE_MODE_ANTHROPIC_CACHE_CONTROL;
            $aiModel->providerFilters = new \DDD\Domain\AI\Entities\Models\Settings\AIModelProviderFilters(only: ['anthropic']);
        }
        return $aiModel;
    }

    protected static function chatToolAnswer(bool $withReasoning = true): \DDD\Domain\AI\Entities\Evals\Probes\ReasoningReplayProbeHttpAnswer
    {
        $message = [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'getWeather', 'arguments' => '{"city":"Berlin"}']]],
        ];
        if ($withReasoning) {
            $message['reasoning_details'] = [['type' => 'reasoning.text', 'text' => 'I need the weather.', 'signature' => 'sig-1', 'format' => 'anthropic-claude-v1']];
        }
        return ScriptedReasoningReplayProbeTransportService::answer(200, (string)json_encode([
            'provider' => 'Anthropic',
            'choices' => [['message' => $message]],
            'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 80, 'prompt_tokens_details' => ['cached_tokens' => 0, 'cache_write_tokens' => 1100], 'cost' => 0.01],
        ]));
    }

    protected static function chatTextAnswer(int $promptTokens, int $cachedTokens): \DDD\Domain\AI\Entities\Evals\Probes\ReasoningReplayProbeHttpAnswer
    {
        return ScriptedReasoningReplayProbeTransportService::answer(200, (string)json_encode([
            'provider' => 'Anthropic',
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'It is 7 °C and cloudy in Berlin.']]],
            'usage' => ['prompt_tokens' => $promptTokens, 'completion_tokens' => 20, 'prompt_tokens_details' => ['cached_tokens' => $cachedTokens], 'cost' => 0.002],
        ]));
    }

    protected static function responsesToolAnswer(string $toolName, string $reasoningId): \DDD\Domain\AI\Entities\Evals\Probes\ReasoningReplayProbeHttpAnswer
    {
        return ScriptedReasoningReplayProbeTransportService::answer(200, (string)json_encode([
            'output' => [
                ['type' => 'reasoning', 'id' => $reasoningId, 'summary' => [], 'content' => [['type' => 'reasoning_text', 'text' => 'thinking']], 'signature' => 'sig-' . $reasoningId],
                ['type' => 'function_call', 'call_id' => 'call_' . $reasoningId, 'name' => $toolName, 'arguments' => '{"city":"Berlin"}'],
            ],
            'usage' => ['input_tokens' => 1500, 'output_tokens' => 60, 'input_tokens_details' => ['cached_tokens' => 1200]],
        ]));
    }

    protected static function responsesTextAnswer(): \DDD\Domain\AI\Entities\Evals\Probes\ReasoningReplayProbeHttpAnswer
    {
        return ScriptedReasoningReplayProbeTransportService::answer(200, (string)json_encode([
            'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Done.']]]],
            'usage' => ['input_tokens' => 1600, 'output_tokens' => 10, 'input_tokens_details' => ['cached_tokens' => 1500]],
        ]));
    }

    protected static function refusal(string $upstreamText): \DDD\Domain\AI\Entities\Evals\Probes\ReasoningReplayProbeHttpAnswer
    {
        return ScriptedReasoningReplayProbeTransportService::answer(400, (string)json_encode([
            'error' => ['code' => 400, 'message' => 'Provider returned error', 'metadata' => ['raw' => $upstreamText]],
        ]));
    }
}
