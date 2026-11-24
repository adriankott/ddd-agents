<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentContextSegment;
use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentContextSegmentType;
use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentPreviousReasoningCut;
use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentWireConfiguration;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation;
use DDD\Domain\AI\Repo\Argus\Conversations\Wire\AgentMessagesRequestComposer;
use DDD\Domain\AI\Repo\Argus\Conversations\Wire\AgentResponsesRequestComposer;
use DDD\Domain\AI\Repo\Argus\Conversations\Wire\AgentWireRequestParameters;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * Three calls around a move of the reasoning cut (the cut kept from step 3, the cut call moves it to step 5), composed
 * into the request bodies of both wire APIs: with the cache markers taken out, the cut call repeats the call before it
 * byte for byte up to the earlier cut, and the call after it repeats the cut call byte for byte up to the cut call's
 * last marker — the system parts keep their split, only their markers are left out.
 */
class PreviousReasoningCutRequestPrefixTest extends UnitTestCase
{
    public function testResponsesRequestsRepeatTheirPrefixAroundACut(): void
    {
        $this->assertPrefixesRepeatAroundACut(RunSettings::AGENT_WIRE_API_RESPONSES, ArgusAIAgentConversation::PROMPT_CACHE_MARKERS_EXPLICIT_BREAKPOINTS, 'input');
    }

    public function testMessagesRequestsRepeatTheirPrefixAroundACut(): void
    {
        $this->assertPrefixesRepeatAroundACut(RunSettings::AGENT_WIRE_API_MESSAGES, ArgusAIAgentConversation::PROMPT_CACHE_MARKERS_ANTHROPIC_CACHE_CONTROL, 'messages');
    }

    protected function assertPrefixesRepeatAroundACut(string $wireApi, string $promptCacheMarkers, string $historyKey): void
    {
        // before: steps 1–5, items from step 3; cut call: steps 1–6, the cut moves to step 5; after: steps 1–7, from 5
        $bodyBeforeTheCut = $this->composeBody($wireApi, $promptCacheMarkers, 5, 3, null);
        $bodyOfTheCutCall = $this->composeBody($wireApi, $promptCacheMarkers, 6, 5, 3);
        $bodyAfterTheCut = $this->composeBody($wireApi, $promptCacheMarkers, 7, 5, null);

        self::assertLessThanOrEqual(4, static::countMarkers($bodyOfTheCutCall));
        $systemOf = static fn (array $body): string => json_encode(static::withoutMarkers($wireApi === RunSettings::AGENT_WIRE_API_MESSAGES ? $body['system'] : $body['input'][0]));
        self::assertSame($systemOf($bodyBeforeTheCut), $systemOf($bodyOfTheCutCall), 'the system parts keep their split and bytes on the cut call');
        self::assertSame($systemOf($bodyOfTheCutCall), $systemOf($bodyAfterTheCut));

        // the cut call up to its last marker = the call after it
        $lastMarkerPosition = static::findLastMarkedHistoryPosition($bodyOfTheCutCall[$historyKey]);
        self::assertNotNull($lastMarkerPosition);
        self::assertSame(
            json_encode(static::withoutMarkers(array_slice($bodyOfTheCutCall[$historyKey], 0, $lastMarkerPosition + 1))),
            json_encode(static::withoutMarkers(array_slice($bodyAfterTheCut[$historyKey], 0, $lastMarkerPosition + 1)))
        );

        // the call before the cut up to the earlier cut = the cut call, and the cut call marks the entry right there
        $firstMarkerPosition = static::findFirstMarkedHistoryPosition($bodyOfTheCutCall[$historyKey], $wireApi === RunSettings::AGENT_WIRE_API_RESPONSES ? 1 : 0);
        self::assertNotNull($firstMarkerPosition);
        self::assertSame(
            json_encode(static::withoutMarkers(array_slice($bodyBeforeTheCut[$historyKey], 0, $firstMarkerPosition + 1))),
            json_encode(static::withoutMarkers(array_slice($bodyOfTheCutCall[$historyKey], 0, $firstMarkerPosition + 1)))
        );
        self::assertStringContainsString('result 2', json_encode($bodyOfTheCutCall[$historyKey][$firstMarkerPosition]), 'the marker before the earlier cut sits on the result of step 2');
    }

    /** The request body of one call over `$stepCount` steps, the items from `$itemsFromStep`; a moved cut names its anchor. */
    protected function composeBody(string $wireApi, string $promptCacheMarkers, int $stepCount, int $itemsFromStep, ?int $anchorStepOfAMovedCut): array
    {
        $wireMessages = [['role' => 'user', 'content' => 'question']];
        $assistantPositionsByStep = [];
        for ($step = 1; $step <= $stepCount; $step++) {
            $assistantPositionsByStep[$step] = count($wireMessages);
            $wireMessages[] = static::buildAssistantEntry($wireApi, $step, $step >= $itemsFromStep);
            $wireMessages[] = ['role' => 'tool', 'tool_call_id' => 'call_' . $step, 'content' => 'result ' . $step];
        }
        $stableWireMessageCount = count($wireMessages);
        // the volatile tail: a user message on Responses, a note (`role: "system"`) on the Messages API
        $wireMessages[] = $wireApi === RunSettings::AGENT_WIRE_API_MESSAGES
            ? ArgusAIAgentConversation::buildMessagesWireNoteEntry(['volatile tail of call ' . $stepCount])
            : ['role' => 'user', 'content' => 'volatile tail of call ' . $stepCount];

        /** @var ArgusAIAgentConversation $argusAIAgentConversation */
        $argusAIAgentConversation = self::instantiateWithoutConstructor(ArgusAIAgentConversation::class);
        $agentWireConfiguration = new AgentWireConfiguration();
        $agentWireConfiguration->wireApi = $wireApi;
        $agentWireConfiguration->skillLoadingStrategy = ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND;
        $agentWireConfiguration->previousReasoningCarryOver = RunSettings::PREVIOUS_REASONING_CARRY_OVER_CAPPED;
        $argusAIAgentConversation->agentWireConfiguration = $agentWireConfiguration;
        $argusAIAgentConversation->stableWireMessageCount = $stableWireMessageCount;
        $argusAIAgentConversation->lastSystemSegments = [
            static::segment(AgentContextSegmentType::BASE_PROMPT, 'persona and rules '),
            static::segment(AgentContextSegmentType::SKILL_CATALOG, 'catalog '),
            static::segment(AgentContextSegmentType::LOADED_SKILLS, 'loaded skills'),
        ];
        $agentPreviousReasoningCut = new AgentPreviousReasoningCut();
        $agentPreviousReasoningCut->movedOnThisCall = $anchorStepOfAMovedCut !== null;
        $argusAIAgentConversation->lastPreviousReasoningCut = $agentPreviousReasoningCut;
        if ($anchorStepOfAMovedCut !== null) {
            $argusAIAgentConversation->previousReasoningAnchorCutWirePosition = $assistantPositionsByStep[$anchorStepOfAMovedCut];
            $argusAIAgentConversation->previousReasoningNewCutWirePosition = $assistantPositionsByStep[$itemsFromStep];
        }

        $agentWireRequestParameters = new AgentWireRequestParameters();
        $agentWireRequestParameters->modelId = 'probe/model';
        $agentWireRequestParameters->chatCompletionsMessages = $argusAIAgentConversation->addSystemMessageAndPromptCacheMarkers($wireMessages, $promptCacheMarkers);
        $agentWireRequestParameters->chatCompletionsTools = [['type' => 'function', 'function' => ['name' => 'getOrder', 'parameters' => ['type' => 'object']]]];
        return $wireApi === RunSettings::AGENT_WIRE_API_MESSAGES
            ? AgentMessagesRequestComposer::composeBody($agentWireRequestParameters)
            : AgentResponsesRequestComposer::composeBody($agentWireRequestParameters);
    }

    /** @return array<string, mixed> */
    protected static function buildAssistantEntry(string $wireApi, int $step, bool $sendsItsReasoning): array
    {
        $assistantEntry = [
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => [['id' => 'call_' . $step, 'type' => 'function', 'function' => ['name' => 'getOrder', 'arguments' => '{"orderId":"' . $step . '"}']]],
        ];
        if ($wireApi === RunSettings::AGENT_WIRE_API_MESSAGES) {
            $assistantEntry[AgentMessagesRequestComposer::KEY_ASSISTANT_CONTENT_JSON] = '[{"type":"thinking","thinking":"step ' . $step . '","signature":"sig-' . $step . '"},'
                . '{"type":"tool_use","id":"call_' . $step . '","name":"getOrder","input":{"orderId":"' . $step . '"}}]';
            $assistantEntry[AgentMessagesRequestComposer::KEY_STEP_MARKER_MESSAGE_INDEX] = $step;
            if (!$sendsItsReasoning) {
                $assistantEntry[AgentMessagesRequestComposer::KEY_ASSISTANT_CONTENT_WITHOUT_THINKING] = true;
            }
        } elseif ($sendsItsReasoning) {
            $assistantEntry['reasoning_details'] = [['type' => 'reasoning', 'id' => 'rs_' . $step, 'encrypted_content' => 'enc-' . $step, 'summary' => []]];
        }
        return $assistantEntry;
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

    protected static function countMarkers(array $body): int
    {
        return substr_count(json_encode($body), '"cache_control"') + substr_count(json_encode($body), '"prompt_cache_breakpoint"');
    }

    protected static function isMarked(mixed $historyEntry): bool
    {
        $encoded = json_encode($historyEntry);
        return str_contains($encoded, '"cache_control"') || str_contains($encoded, '"prompt_cache_breakpoint"');
    }

    protected static function findLastMarkedHistoryPosition(array $history): ?int
    {
        for ($position = count($history) - 1; $position >= 0; $position--) {
            if (static::isMarked($history[$position])) {
                return $position;
            }
        }
        return null;
    }

    /** The first marked history entry from `$fromPosition` on (the Responses input starts with the system item). */
    protected static function findFirstMarkedHistoryPosition(array $history, int $fromPosition): ?int
    {
        for ($position = $fromPosition; $position < count($history); $position++) {
            if (static::isMarked($history[$position])) {
                return $position;
            }
        }
        return null;
    }

    protected static function segment(AgentContextSegmentType $agentContextSegmentType, string $text): AgentContextSegment
    {
        $agentContextSegment = new AgentContextSegment();
        $agentContextSegment->type = $agentContextSegmentType;
        $agentContextSegment->text = $text;
        return $agentContextSegment;
    }
}
