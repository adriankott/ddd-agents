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

    public function testACleanReplayOnTheMessagesApiSendsTheStoredAnswerAndTheNoteOfTheEarlierCall(): void
    {
        $probe = $this->buildProbe([self::messagesToolAnswer('getOrder', '{"orderId":"4711"}', 1), self::messagesTextAnswer(7000, 6500)]);

        $result = $probe->runScenario(self::OPUS, AgentReasoningReplayProbeService::SCENARIO_CLEAN, RunSettings::AGENT_WIRE_API_MESSAGES, false, null, 'high', 60);

        self::assertSame(ReasoningReplayProbeResult::VERDICT_PASS, $result->verdict, $result->verdictReason);
        self::assertTrue($result->requestsDropBlock, 'every request of this wire asks for the drop');
        self::assertSame(AgentReasoningReplayProbeTransportService::MESSAGES_URL, $probe->scriptedTransport->calledUrls[0]);
        self::assertSame('2023-06-01', $probe->scriptedTransport->sentHeaders[0]['anthropic-version']);
        $firstBody = $probe->scriptedTransport->sentBodies[0];
        self::assertSame('drop_block', $firstBody['thinking']['block_binding']['prefix_mismatch_behavior']);
        self::assertSame(['only' => ['anthropic'], 'allow_fallbacks' => false], $firstBody['provider']);
        self::assertSame(['getOrder', 'getCustomer'], array_column($firstBody['tools'], 'name'));
        self::assertSame('system', $firstBody['messages'][1]['role'], 'the call ends on its note');
        self::assertSame('next_user_message', $firstBody['messages'][1]['clear_at']);
        $secondBody = json_decode((string)json_encode($probe->scriptedTransport->sentBodies[1]), true);
        self::assertSame(['user', 'system', 'assistant', 'user', 'system'], array_column($secondBody['messages'], 'role'), 'the earlier note stays where it was sent');
        self::assertSame($firstBody['messages'][1], $secondBody['messages'][1]);
        self::assertSame('sig-1', $secondBody['messages'][2]['content'][0]['signature'], 'the thinking block goes back verbatim');
        $toolResultBlock = $secondBody['messages'][3]['content'][0];
        self::assertSame('toolu_1', $toolResultBlock['tool_use_id']);
        self::assertStringContainsString('C-17', (string)json_encode($toolResultBlock), 'the order task answers by the id the call names');
        self::assertSame('ephemeral', $toolResultBlock['cache_control']['type'], 'the cache marker sits on the newest stable message');
        $calls = $result->calls->getElements();
        self::assertSame(7000, $calls[1]->inputTokens, 'the three disjoint input counts are summed');
        self::assertSame(6500, $calls[1]->cachedInputTokens);
        self::assertSame(40, $calls[0]->reasoningTokens);
    }

    public function testDroppedBlocksOnTheMessagesApiFailACleanReplayAndAreAFindingAfterAnEdit(): void
    {
        $dropped = [['type' => 'thinking_dropped', 'path' => 'messages.2.content.0', 'reason' => 'prefix_binding_mismatch']];
        $cleanProbe = $this->buildProbe([self::messagesToolAnswer('getOrder', '{"orderId":"4711"}', 1), self::messagesTextAnswer(7000, 0, $dropped)]);
        $cleanResult = $cleanProbe->runScenario(self::OPUS, AgentReasoningReplayProbeService::SCENARIO_CLEAN, RunSettings::AGENT_WIRE_API_MESSAGES, false, null, 'high', 60);
        self::assertSame(ReasoningReplayProbeResult::VERDICT_FAIL, $cleanResult->verdict);
        self::assertStringContainsString('1 of 1 replayed thinking block(s) dropped', $cleanResult->verdictReason);

        $editProbe = $this->buildProbe([self::messagesToolAnswer('getOrder', '{"orderId":"4711"}', 1), self::messagesTextAnswer(7000, 0, $dropped)]);
        $editResult = $editProbe->runScenario(self::OPUS, AgentReasoningReplayProbeService::SCENARIO_EDIT_TOOLS, RunSettings::AGENT_WIRE_API_MESSAGES, false, null, 'high', 60);
        self::assertSame(ReasoningReplayProbeResult::VERDICT_FINDING, $editResult->verdict);
        self::assertStringContainsString('prefix_binding_mismatch', $editResult->verdictReason);
        self::assertSame(['getOrder', 'getCustomer', 'getInvoice'], array_column($editProbe->scriptedTransport->sentBodies[1]['tools'], 'name'));
        self::assertSame(1, $editResult->calls->getElements()[1]->droppedThinkingBlockCount);
    }

    public function testAppendOnlyOnTheMessagesApiAddsTheToolAsAToolAdditionWithTheToolDeclaredDeferred(): void
    {
        $probe = $this->buildProbe([
            self::messagesToolAnswer('getOrder', '{"orderId":"4711"}', 1),
            self::messagesToolAnswer('getInvoice', '{"orderId":"4711"}', 2),
            self::messagesTextAnswer(7400, 7000),
        ]);

        $result = $probe->runScenario(self::OPUS, AgentReasoningReplayProbeService::SCENARIO_APPEND_ONLY, RunSettings::AGENT_WIRE_API_MESSAGES, false, null, 'high', 60);

        self::assertSame(ReasoningReplayProbeResult::VERDICT_PASS, $result->verdict, $result->verdictReason);
        self::assertStringContainsString('the appended tool was called', $result->verdictReason);
        $secondBody = $probe->scriptedTransport->sentBodies[1];
        self::assertSame(['getOrder', 'getCustomer', 'getInvoice'], array_column($secondBody['tools'], 'name'));
        self::assertTrue($secondBody['tools'][2]['defer_loading']);
        $skillLoadMessage = $secondBody['messages'][4];
        self::assertSame('system', $skillLoadMessage['role']);
        self::assertSame(['type' => 'tool_addition', 'tool' => ['type' => 'tool_reference', 'name' => 'getInvoice']], $skillLoadMessage['content'][0]);
        $thirdBodyJson = (string)json_encode($probe->scriptedTransport->sentBodies[2]['messages']);
        self::assertStringContainsString('sig-1', $thirdBodyJson);
        self::assertStringContainsString('sig-2', $thirdBodyJson);
        self::assertSame(2, $result->calls->getElements()[2]->replayedReasoningItemCount);
    }

    public function testTheCappedCutSendsTheOlderStepsWithoutThinkingAndKeepsTheNewestTwo(): void
    {
        $probe = $this->buildProbe([
            self::messagesToolAnswer('getOrder', '{"orderId":"4711"}', 1),
            self::messagesToolAnswer('getCustomer', '{"customerId":"C-17"}', 2),
            self::messagesToolAnswer('getOrder', '{"orderId":"4712"}', 3),
            self::messagesToolAnswer('getCustomer', '{"customerId":"C-88"}', 4),
            self::messagesTextAnswer(9000, 6200),
        ]);

        $result = $probe->runScenario(self::OPUS, AgentReasoningReplayProbeService::SCENARIO_CAPPED_CUT, RunSettings::AGENT_WIRE_API_MESSAGES, false, null, 'high', 60);

        self::assertSame(ReasoningReplayProbeResult::VERDICT_PASS, $result->verdict, $result->verdictReason);
        self::assertStringContainsString('2 thinking block(s) of 2 older step(s) stripped, 2 kept', $result->verdictReason);
        $fourthBodyJson = (string)json_encode($probe->scriptedTransport->sentBodies[3]['messages']);
        self::assertStringContainsString('sig-1', $fourthBodyJson, 'the steps themselves carry all thinking');
        $closingBodyJson = (string)json_encode($probe->scriptedTransport->sentBodies[4]['messages']);
        self::assertStringNotContainsString('sig-1', $closingBodyJson);
        self::assertStringNotContainsString('sig-2', $closingBodyJson);
        self::assertStringContainsString('sig-3', $closingBodyJson);
        self::assertStringContainsString('sig-4', $closingBodyJson);
        self::assertStringContainsString('toolu_1', $closingBodyJson, 'the stripped steps keep their calls');
        self::assertStringContainsString('Jonas Weber', $closingBodyJson);
        self::assertSame(2, $result->calls->getElements()[4]->replayedReasoningItemCount);
    }

    public function testTheCurrentTurnStripStartsTheSecondTurnWithoutTheFirstTurnsThinking(): void
    {
        $probe = $this->buildProbe([
            self::messagesToolAnswer('getOrder', '{"orderId":"4711"}', 1),
            self::messagesTextAnswer(7200, 6500),
            self::messagesToolAnswer('getOrder', '{"orderId":"4713"}', 3),
            self::messagesTextAnswer(7900, 7300),
        ]);

        $result = $probe->runScenario(self::OPUS, AgentReasoningReplayProbeService::SCENARIO_CURRENT_TURN_STRIP, RunSettings::AGENT_WIRE_API_MESSAGES, false, null, 'high', 60);

        self::assertSame(ReasoningReplayProbeResult::VERDICT_PASS, $result->verdict, $result->verdictReason);
        $turnStartBody = $probe->scriptedTransport->sentBodies[2];
        $turnStartJson = (string)json_encode($turnStartBody['messages']);
        self::assertStringNotContainsString('sig-1', $turnStartJson, 'the first turn goes back without its thinking');
        self::assertStringContainsString('toolu_1', $turnStartJson);
        self::assertStringContainsString(AgentReasoningReplayProbeService::ORDER_TASK_SECOND_TURN_QUESTION, $turnStartJson);
        $secondStepJson = (string)json_encode($probe->scriptedTransport->sentBodies[3]['messages']);
        self::assertStringContainsString('sig-3', $secondStepJson, 'the new turn carries its own thinking');
        self::assertStringNotContainsString('sig-1', $secondStepJson);
    }

    public function testTheForcedPinOnTheMessagesApiIsFollowedByAutoWithTheLineInTheCallsNote(): void
    {
        $probe = $this->buildProbe([
            self::refusal('tool_choice: type "tool" and "any" are not supported for this model.'),
            self::messagesToolAnswer('getOrder', '{"orderId":"4711"}', 1),
        ]);

        $result = $probe->runScenario(self::OPUS, AgentReasoningReplayProbeService::SCENARIO_FORCED_TOOL_CHOICE, RunSettings::AGENT_WIRE_API_MESSAGES, false, null, 'high', 60);

        self::assertSame(ReasoningReplayProbeResult::VERDICT_FINDING, $result->verdict);
        self::assertStringContainsString('the model called getOrder', $result->verdictReason);
        self::assertSame(['type' => 'tool', 'name' => 'getOrder', 'disable_parallel_tool_use' => true], $probe->scriptedTransport->sentBodies[0]['tool_choice']);
        $noteBody = $probe->scriptedTransport->sentBodies[1];
        self::assertSame(['type' => 'auto', 'disable_parallel_tool_use' => true], $noteBody['tool_choice'], 'the probe asks for one call per step');
        $noteMessage = end($noteBody['messages']);
        self::assertSame('system', $noteMessage['role']);
        self::assertStringContainsString('[SIGNAL:tool-expected]', (string)json_encode($noteMessage));
    }

    public function testTheMessagesApiNeedsAnAnthropicModelAndItsStrippingScenariosNeedThatApi(): void
    {
        $probe = $this->buildProbe([]);
        try {
            $probe->runScenario(self::GPT, AgentReasoningReplayProbeService::SCENARIO_CLEAN, RunSettings::AGENT_WIRE_API_MESSAGES, false, null, 'high', 60);
            self::fail('a model of another vendor on the Messages API must be refused');
        } catch (BadRequestException) {
        }
        $this->expectException(BadRequestException::class);
        $probe->runScenario(self::OPUS, AgentReasoningReplayProbeService::SCENARIO_CAPPED_CUT, RunSettings::AGENT_WIRE_API_CHAT_COMPLETIONS, false, null, 'high', 60);
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

    protected static function messagesToolAnswer(string $toolName, string $inputJson, int $stepNumber): \DDD\Domain\AI\Entities\Evals\Probes\ReasoningReplayProbeHttpAnswer
    {
        return ScriptedReasoningReplayProbeTransportService::answer(200, '{"type":"message","role":"assistant","provider":"Anthropic","stop_reason":"tool_use","content":['
            . '{"type":"thinking","thinking":"step ' . $stepNumber . '","signature":"sig-' . $stepNumber . '"},'
            . '{"type":"tool_use","id":"toolu_' . $stepNumber . '","name":"' . $toolName . '","input":' . $inputJson . '}],'
            . '"usage":{"input_tokens":200,"cache_read_input_tokens":0,"cache_creation_input_tokens":6500,"output_tokens":90,"output_tokens_details":{"thinking_tokens":40},"cost":0.03}}');
    }

    /**
     * @param array<int, array<string, string>> $inputTransformations
     */
    protected static function messagesTextAnswer(int $inputTokens, int $cacheReadTokens, array $inputTransformations = []): \DDD\Domain\AI\Entities\Evals\Probes\ReasoningReplayProbeHttpAnswer
    {
        $answer = [
            'type' => 'message',
            'role' => 'assistant',
            'provider' => 'Anthropic',
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'The customer lives in 80331 München.']],
            'usage' => ['input_tokens' => $inputTokens - $cacheReadTokens, 'cache_read_input_tokens' => $cacheReadTokens, 'cache_creation_input_tokens' => 0, 'output_tokens' => 30, 'cost' => 0.004],
        ];
        if ($inputTransformations !== []) {
            $answer['input_transformations'] = $inputTransformations;
        }
        return ScriptedReasoningReplayProbeTransportService::answer(200, (string)json_encode($answer, JSON_UNESCAPED_UNICODE));
    }

    protected static function refusal(string $upstreamText): \DDD\Domain\AI\Entities\Evals\Probes\ReasoningReplayProbeHttpAnswer
    {
        return ScriptedReasoningReplayProbeTransportService::answer(400, (string)json_encode([
            'error' => ['code' => 400, 'message' => 'Provider returned error', 'metadata' => ['raw' => $upstreamText]],
        ]));
    }
}
