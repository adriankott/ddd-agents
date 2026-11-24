<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentContextSegment;
use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentContextSegmentType;
use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentPreviousReasoningCut;
use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentWireConfiguration;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation;
use DDD\Domain\AI\Repo\Argus\Conversations\Wire\AgentMessagesRequestComposer;
use DDD\Domain\AI\Repo\Argus\Conversations\Wire\AgentResponsesRequestComposer;
use DDD\Domain\AI\Repo\Argus\Conversations\Wire\AgentWireRequestParameters;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\ToolPictureFixtures;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;

/**
 * The image retention across a turn change on both wire APIs: turn N looked at a picture; at the first call of turn
 * N+1 the picture leaves the context (placeholder line). The history repeats byte for byte up to that result, the
 * call marks the cache on the last text entry BEFORE it (instead of the previous call's entry, which lies behind the
 * change), and the next call of turn N+1 repeats the first one up to its tail — the cut moves only at a turn change.
 */
class ImageRetentionCutRequestPrefixTest extends UnitTestCase
{
    protected const int PICTURE_RESULT_POSITION = 2;

    public function testMessagesRequestsKeepThePrefixBeforeTheRetiredPictureAndMarkItsEnd(): void
    {
        $this->assertTheCutKeepsThePrefix(RunSettings::AGENT_WIRE_API_MESSAGES, ArgusAIAgentConversation::PROMPT_CACHE_MARKERS_ANTHROPIC_CACHE_CONTROL, 'messages');
    }

    public function testResponsesRequestsKeepThePrefixBeforeTheRetiredPictureAndMarkItsEnd(): void
    {
        $this->assertTheCutKeepsThePrefix(RunSettings::AGENT_WIRE_API_RESPONSES, ArgusAIAgentConversation::PROMPT_CACHE_MARKERS_EXPLICIT_BREAKPOINTS, 'input');
    }

    protected function assertTheCutKeepsThePrefix(string $wireApi, string $promptCacheMarkers, string $historyKey): void
    {
        $toolResult = ToolPictureFixtures::buildToolResultWithPicture(null, 'logo.png, 1200 x 210');
        $toolResult->images[0]->sourceStore = 'spool';
        $toolResult->images[0]->sourceImageId = '31/' . $toolResult->images[0]->sha256;
        $pictureCall = new ToolCall();
        $pictureCall->toolCallId = 'call_picture';
        $pictureCall->name = 'viewWorkspaceImages';
        $pictureCall->result = $toolResult->content;
        $pictureCall->status = AIConversationMessage::STATUS_COMPLETED;
        $pictureCall->applyResultImages($toolResult);

        $pictureEntryWithBytes = $pictureCall->toOpenAiToolResultArray(true, $toolResult->images);
        $pictureEntryRetired = $pictureCall->toOpenAiToolResultArray(true, [], false, false, false, false, true);
        self::assertStringContainsString(
            'viewEarlierToolImage with imageId \"' . substr($toolResult->images[0]->sha256, 0, 12) . '\"',
            json_encode($pictureEntryRetired)
        );
        self::assertStringNotContainsString('base64', json_encode($pictureEntryRetired));

        $lastCallOfTurnN = $this->composeBody($wireApi, $promptCacheMarkers, $pictureEntryWithBytes, 0, null);
        $firstCallOfTurnNPlusOne = $this->composeBody($wireApi, $promptCacheMarkers, $pictureEntryRetired, 1, self::PICTURE_RESULT_POSITION);
        $secondCallOfTurnNPlusOne = $this->composeBody($wireApi, $promptCacheMarkers, $pictureEntryRetired, 2, null);

        $pictureResultPosition = static::findFirstToolResultPosition($firstCallOfTurnNPlusOne[$historyKey]);
        self::assertNotNull($pictureResultPosition);
        self::assertSame(
            json_encode(static::withoutMarkers(array_slice($lastCallOfTurnN[$historyKey], 0, $pictureResultPosition))),
            json_encode(static::withoutMarkers(array_slice($firstCallOfTurnNPlusOne[$historyKey], 0, $pictureResultPosition))),
            'everything before the retired picture repeats byte for byte'
        );
        // the cut call marks the last text entry before the change; without the cut it carries one marker less there
        // (the previous call's marker, behind the change, would be read in vain)
        $withoutTheCut = $this->composeBody($wireApi, $promptCacheMarkers, $pictureEntryRetired, 1, null);
        self::assertSame(
            static::countMarkersBefore($withoutTheCut[$historyKey], $pictureResultPosition) + 1,
            static::countMarkersBefore($firstCallOfTurnNPlusOne[$historyKey], $pictureResultPosition),
            'one marker right before the change'
        );

        // the next call of the turn repeats the first one up to its tail: the retired result does not change again
        $tailPosition = count($firstCallOfTurnNPlusOne[$historyKey]) - 1;
        self::assertSame(
            json_encode(static::withoutMarkers(array_slice($firstCallOfTurnNPlusOne[$historyKey], 0, $tailPosition))),
            json_encode(static::withoutMarkers(array_slice($secondCallOfTurnNPlusOne[$historyKey], 0, $tailPosition)))
        );
    }

    /**
     * The request body of a call: the step of turn N with the picture, the answer, then (`$callOfTurnNPlusOne` ≥ 1) the
     * new owner message and, for the second call, one more step of turn N+1; each call ends on its volatile tail.
     *
     * @param array<string, mixed> $pictureEntry
     * @return array<string, mixed>
     */
    protected function composeBody(string $wireApi, string $promptCacheMarkers, array $pictureEntry, int $callOfTurnNPlusOne, ?int $imageRetentionCutWirePosition): array
    {
        $usesMessagesApi = $wireApi === RunSettings::AGENT_WIRE_API_MESSAGES;
        $wireMessages = [['role' => 'user', 'content' => 'which colours are in the logo?']];
        $wireMessages[] = static::buildAssistantEntry($wireApi, 1, 'call_picture', 'viewWorkspaceImages');
        $wireMessages[] = $pictureEntry;
        if ($callOfTurnNPlusOne >= 1) {
            $answerEntry = ['role' => 'assistant', 'content' => 'Two colours: #0B0948 and #06D6E3.'];
            if ($usesMessagesApi) {
                $answerEntry[AgentMessagesRequestComposer::KEY_ASSISTANT_CONTENT_JSON] = '[{"type":"thinking","thinking":"answer","signature":"sig-answer"},{"type":"text","text":"Two colours: #0B0948 and #06D6E3."}]';
                $answerEntry[AgentMessagesRequestComposer::KEY_STEP_MARKER_MESSAGE_INDEX] = 3;
            }
            $wireMessages[] = $answerEntry;
            $wireMessages[] = ['role' => 'user', 'content' => 'and which font?'];
        }
        if ($callOfTurnNPlusOne >= 2) {
            $wireMessages[] = static::buildAssistantEntry($wireApi, 5, 'call_shell', 'shell');
            $wireMessages[] = ['role' => 'tool', 'tool_call_id' => 'call_shell', 'content' => 'Inter, 600'];
        }
        $stableWireMessageCount = count($wireMessages);
        $volatileTail = 'volatile tail of call ' . $callOfTurnNPlusOne;
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
        $argusAIAgentConversation->imageRetentionCutWirePosition = $imageRetentionCutWirePosition;
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

    /** The cache markers of the history entries before a position. */
    protected static function countMarkersBefore(array $history, int $endPosition): int
    {
        $markerCount = 0;
        foreach (array_slice($history, 0, $endPosition) as $historyEntry) {
            $encoded = (string)json_encode($historyEntry);
            $markerCount += substr_count($encoded, '"cache_control"') + substr_count($encoded, '"prompt_cache_breakpoint"');
        }
        return $markerCount;
    }

    protected static function findFirstToolResultPosition(array $history): ?int
    {
        foreach ($history as $position => $historyEntry) {
            $encoded = (string)json_encode($historyEntry);
            if (str_contains($encoded, '"tool_result"') || str_contains($encoded, '"function_call_output"')) {
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
