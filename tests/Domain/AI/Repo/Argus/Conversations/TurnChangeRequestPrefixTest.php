<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentContextSegment;
use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentContextSegmentType;
use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentPreviousReasoningCut;
use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentWireConfiguration;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\MCPPackages\ToolResult;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultImage;
use DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation;
use DDD\Domain\AI\Repo\Argus\Conversations\Wire\AgentMessagesRequestComposer;
use DDD\Domain\AI\Repo\Argus\Conversations\Wire\AgentResponsesRequestComposer;
use DDD\Domain\AI\Repo\Argus\Conversations\Wire\AgentWireRequestParameters;
use DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallImageStoreService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\TieredAgentToolCallImageStoreServiceDouble;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\ToolPictureFixtures;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use ReflectionClass;

/**
 * A turn change on both wire APIs: turn N looked at a picture and ran one more tool, the owner then wrote again. The
 * first call of turn N+1 runs in a new invocation (no transient carry of the picture) and repeats the last call of
 * turn N byte for byte up to the last tool result of turn N — the picture result included, its bytes re-read from the
 * picture store. Without the bytes the picture result carries the dropped marker: the history changes there, and on the
 * Messages API the thinking of every later step of turn N is bound to the changed prefix (the provider drops it).
 */
class TurnChangeRequestPrefixTest extends IntegrationTestCase
{
    protected TieredAgentToolCallImageStoreServiceDouble $storeDouble;

    protected function setUp(): void
    {
        parent::setUp();
        // a shared cache only: the next invocation (another process) finds the picture there
        $this->storeDouble = TieredAgentToolCallImageStoreServiceDouble::withTiers(false, true, null);
        $this->replaceService(AgentToolCallImageStoreService::class, $this->storeDouble);
    }

    public function testMessagesRequestsRepeatTheLastToolResultOfTheTurnAcrossTheTurnChange(): void
    {
        $this->assertThePrefixRepeatsAcrossTheTurnChange(RunSettings::AGENT_WIRE_API_MESSAGES, ArgusAIAgentConversation::PROMPT_CACHE_MARKERS_ANTHROPIC_CACHE_CONTROL, 'messages');
    }

    public function testResponsesRequestsRepeatTheLastToolResultOfTheTurnAcrossTheTurnChange(): void
    {
        $this->assertThePrefixRepeatsAcrossTheTurnChange(RunSettings::AGENT_WIRE_API_RESPONSES, ArgusAIAgentConversation::PROMPT_CACHE_MARKERS_EXPLICIT_BREAKPOINTS, 'input');
    }

    protected function assertThePrefixRepeatsAcrossTheTurnChange(string $wireApi, string $promptCacheMarkers, string $historyKey): void
    {
        $toolResult = ToolPictureFixtures::buildToolResultWithPicture(null, 'logo.png, 1200 x 210');
        $conversation = new AIConversation();
        $conversation->id = 4711;
        $this->storeDouble->storeInlineToolResultImages($toolResult, $conversation);
        $pictureCall = static::buildCompletedToolCall('call_picture', 'viewWorkspaceImages', $toolResult);
        $shellCall = static::buildCompletedToolCall('call_shell', 'shell', new ToolResult('#0B0948 62%, #06D6E3 38%'));

        // turn N, its last call: the picture rides from the transient carry of this invocation
        $pictureEntryOfTurnN = $pictureCall->toOpenAiToolResultArray(true, $toolResult->images);
        // turn N+1, its first call: a new invocation, the picture re-read through the executor (the picture store)
        /** @var MCPToolExecutorService $mCPToolExecutorService */
        $mCPToolExecutorService = DDDService::instance()->getService(MCPToolExecutorService::class);
        $reResolvedImage = $mCPToolExecutorService->resolveToolCallImage($pictureCall->imageReferences[0], []);
        self::assertInstanceOf(ToolResultImage::class, $reResolvedImage);
        $pictureEntryOfTurnNPlusOne = $pictureCall->toOpenAiToolResultArray(true, [$reResolvedImage]);
        $shellEntry = $shellCall->toOpenAiToolResultArray();

        $lastCallOfTurnN = $this->composeBody($wireApi, $promptCacheMarkers, $pictureEntryOfTurnN, $shellEntry, false);
        $firstCallOfTurnNPlusOne = $this->composeBody($wireApi, $promptCacheMarkers, $pictureEntryOfTurnNPlusOne, $shellEntry, true);

        $lastToolResultPosition = static::findLastToolResultPosition($lastCallOfTurnN[$historyKey]);
        self::assertNotNull($lastToolResultPosition);
        self::assertStringContainsString('#0B0948', json_encode($lastCallOfTurnN[$historyKey][$lastToolResultPosition]));
        self::assertSame(
            json_encode(static::withoutMarkers(array_slice($lastCallOfTurnN[$historyKey], 0, $lastToolResultPosition + 1))),
            json_encode(static::withoutMarkers(array_slice($firstCallOfTurnNPlusOne[$historyKey], 0, $lastToolResultPosition + 1))),
            'the last tool result of turn N and everything before it repeat byte for byte'
        );
        if ($wireApi === RunSettings::AGENT_WIRE_API_MESSAGES) {
            // the note of the call that answered turn N stays at its place, before the answer it was sent with
            self::assertSame(
                json_encode(static::withoutMarkers($lastCallOfTurnN[$historyKey][$lastToolResultPosition + 1])),
                json_encode(static::withoutMarkers($firstCallOfTurnNPlusOne[$historyKey][$lastToolResultPosition + 1]))
            );
        }

        // the bytes gone (no carry, no picture store): the picture result is the first change of the history
        $pictureEntryWithoutTheBytes = $pictureCall->toOpenAiToolResultArray(true, [], false, false, true);
        $firstCallWithoutTheBytes = $this->composeBody($wireApi, $promptCacheMarkers, $pictureEntryWithoutTheBytes, $shellEntry, true);
        $pictureResultPosition = static::findFirstToolResultPosition($lastCallOfTurnN[$historyKey]);
        self::assertNotSame(
            json_encode(static::withoutMarkers($lastCallOfTurnN[$historyKey][$pictureResultPosition])),
            json_encode(static::withoutMarkers($firstCallWithoutTheBytes[$historyKey][$pictureResultPosition]))
        );
    }

    /**
     * The request body of the last call of turn N (`$afterTheTurnChange` false: the steps of turn N and this call's
     * volatile tail) or of the first call of turn N+1 (the answer of turn N with the note it was sent with, the new
     * owner message and this call's tail).
     *
     * @param array<string, mixed> $pictureEntry
     * @param array<string, mixed> $shellEntry
     * @return array<string, mixed>
     */
    protected function composeBody(string $wireApi, string $promptCacheMarkers, array $pictureEntry, array $shellEntry, bool $afterTheTurnChange): array
    {
        $usesMessagesApi = $wireApi === RunSettings::AGENT_WIRE_API_MESSAGES;
        $wireMessages = [['role' => 'user', 'content' => 'which colours are in the logo?']];
        $wireMessages[] = static::buildAssistantEntry($wireApi, 1, 'call_picture', 'viewWorkspaceImages');
        $wireMessages[] = $pictureEntry;
        $wireMessages[] = static::buildAssistantEntry($wireApi, 3, 'call_shell', 'shell');
        $wireMessages[] = $shellEntry;
        if ($afterTheTurnChange) {
            if ($usesMessagesApi) {
                $wireMessages[] = ArgusAIAgentConversation::buildMessagesWireNoteEntry(['volatile tail of the answering call']);
            }
            $answerEntry = ['role' => 'assistant', 'content' => 'Two colours: #0B0948 and #06D6E3.'];
            if ($usesMessagesApi) {
                $answerEntry[AgentMessagesRequestComposer::KEY_ASSISTANT_CONTENT_JSON] = '[{"type":"thinking","thinking":"answer","signature":"sig-answer"},{"type":"text","text":"Two colours: #0B0948 and #06D6E3."}]';
                $answerEntry[AgentMessagesRequestComposer::KEY_STEP_MARKER_MESSAGE_INDEX] = 5;
            }
            $wireMessages[] = $answerEntry;
            $wireMessages[] = ['role' => 'user', 'content' => 'and the background?'];
        }
        $stableWireMessageCount = count($wireMessages);
        $volatileTail = $afterTheTurnChange ? 'volatile tail of the first call of the next turn' : 'volatile tail of the answering call';
        $wireMessages[] = $usesMessagesApi
            ? ArgusAIAgentConversation::buildMessagesWireNoteEntry([$volatileTail])
            : ['role' => 'user', 'content' => $volatileTail];

        /** @var ArgusAIAgentConversation $argusAIAgentConversation */
        $argusAIAgentConversation = (new ReflectionClass(ArgusAIAgentConversation::class))->newInstanceWithoutConstructor();
        $agentWireConfiguration = new AgentWireConfiguration();
        $agentWireConfiguration->wireApi = $wireApi;
        $agentWireConfiguration->skillLoadingStrategy = ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND;
        $agentWireConfiguration->previousReasoningCarryOver = RunSettings::PREVIOUS_REASONING_CARRY_OVER_CAPPED;
        $argusAIAgentConversation->agentWireConfiguration = $agentWireConfiguration;
        $argusAIAgentConversation->stableWireMessageCount = $stableWireMessageCount;
        $argusAIAgentConversation->lastSystemSegments = [
            static::segment(AgentContextSegmentType::BASE_PROMPT, 'persona and rules '),
            static::segment(AgentContextSegmentType::LOADED_SKILLS, 'loaded skills'),
        ];
        $argusAIAgentConversation->lastPreviousReasoningCut = new AgentPreviousReasoningCut();

        $agentWireRequestParameters = new AgentWireRequestParameters();
        $agentWireRequestParameters->modelId = 'probe/model';
        $agentWireRequestParameters->chatCompletionsMessages = $argusAIAgentConversation->addSystemMessageAndPromptCacheMarkers($wireMessages, $promptCacheMarkers);
        $agentWireRequestParameters->chatCompletionsTools = [
            ['type' => 'function', 'function' => ['name' => 'viewWorkspaceImages', 'parameters' => ['type' => 'object']]],
            ['type' => 'function', 'function' => ['name' => 'shell', 'parameters' => ['type' => 'object']]],
        ];
        return $usesMessagesApi
            ? AgentMessagesRequestComposer::composeBody($agentWireRequestParameters)
            : AgentResponsesRequestComposer::composeBody($agentWireRequestParameters);
    }

    /** @return array<string, mixed> */
    protected static function buildAssistantEntry(string $wireApi, int $stepMarkerMessageIndex, string $toolCallId, string $toolName): array
    {
        $assistantEntry = [
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => [['id' => $toolCallId, 'type' => 'function', 'function' => ['name' => $toolName, 'arguments' => '{}']]],
        ];
        if ($wireApi === RunSettings::AGENT_WIRE_API_MESSAGES) {
            $assistantEntry[AgentMessagesRequestComposer::KEY_ASSISTANT_CONTENT_JSON] = '[{"type":"thinking","thinking":"step ' . $stepMarkerMessageIndex . '","signature":"sig-' . $stepMarkerMessageIndex . '"},'
                . '{"type":"tool_use","id":"' . $toolCallId . '","name":"' . $toolName . '","input":{}}]';
            $assistantEntry[AgentMessagesRequestComposer::KEY_STEP_MARKER_MESSAGE_INDEX] = $stepMarkerMessageIndex;
        } else {
            $assistantEntry['reasoning_details'] = [['type' => 'reasoning', 'id' => 'rs_' . $stepMarkerMessageIndex, 'encrypted_content' => 'enc-' . $stepMarkerMessageIndex, 'summary' => []]];
        }
        return $assistantEntry;
    }

    protected static function buildCompletedToolCall(string $toolCallId, string $toolName, ToolResult $toolResult): ToolCall
    {
        $toolCall = new ToolCall();
        $toolCall->toolCallId = $toolCallId;
        $toolCall->name = $toolName;
        $toolCall->result = $toolResult->content;
        $toolCall->status = AIConversationMessage::STATUS_COMPLETED;
        $toolCall->applyResultImages($toolResult);
        return $toolCall;
    }

    /** Whether a history entry holds a tool result (a Messages `tool_result` block, a Responses `function_call_output`). */
    protected static function isToolResult(mixed $historyEntry): bool
    {
        $encoded = json_encode($historyEntry);
        return str_contains($encoded, '"tool_result"') || str_contains($encoded, '"function_call_output"');
    }

    protected static function findLastToolResultPosition(array $history): ?int
    {
        for ($position = count($history) - 1; $position >= 0; $position--) {
            if (static::isToolResult($history[$position])) {
                return $position;
            }
        }
        return null;
    }

    protected static function findFirstToolResultPosition(array $history): ?int
    {
        foreach ($history as $position => $historyEntry) {
            if (static::isToolResult($historyEntry)) {
                return $position;
            }
        }
        return null;
    }

    protected static function withoutMarkers(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = (array)$value;
        }
        if (!is_array($value)) {
            return $value;
        }
        unset($value['cache_control'], $value['prompt_cache_breakpoint']);
        return array_map(static fn (mixed $innerValue): mixed => static::withoutMarkers($innerValue), $value);
    }

    protected static function segment(AgentContextSegmentType $agentContextSegmentType, string $text): AgentContextSegment
    {
        $agentContextSegment = new AgentContextSegment();
        $agentContextSegment->type = $agentContextSegmentType;
        $agentContextSegment->text = $text;
        return $agentContextSegment;
    }
}
