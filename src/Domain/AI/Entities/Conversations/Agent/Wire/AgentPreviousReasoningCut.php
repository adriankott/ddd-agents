<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Wire;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot;

/**
 * Where the carry-over `capped` cuts the previous steps' reasoning items for one model call. Pure, no repository contact; built by the egress over the rows of the call's context.
 *
 * The rule:
 * 1. The ANCHOR is the cut the newest earlier call recorded on its request snapshot
 *    ({@see RequestSnapshot::$previousReasoningCutFromMessageIndex}); none in the context (the first call, or the rows
 *    of the recording call are behind a compaction cover) = message index 0, every step.
 * 2. The steps that send items are the step-marker rows at or above the anchor whose snapshot holds reasoning items of
 *    this call's wire API. Their size is summed in reasoning tokens ({@see estimateReasoningTokensOfStep()}).
 * 3. The cut fires when the sum exceeds the larger of the cap (max) and, after an earlier cut, the reasoning that cut
 *    kept plus the headroom ({@see $cutAboveTokens}). Below it the anchor stays the cut — the same reasoning items as
 *    on the previous call.
 * 4. At a cut, whole steps are kept walking back from the newest one until at least `keptTokensAtCut` reasoning tokens
 *    (min) and at least `keptStepsAtCut` steps are kept; the cut moves to the marker index of the oldest kept step —
 *    every older step loses its items IN ONE BLOCK. When that keeps every step, nothing moves. The call records the
 *    new cut, so every later call renders the same cut until the next crossing (a sawtooth: the carried block grows
 *    from the kept steps up to the threshold, then drops back).
 *
 * Worked example (cap 4,000, min 1,500, 2 steps, headroom 1,000): steps of 600, 900, 700, 1,200, 800 → sum 4,200 >
 * 4,000 → walking back 800 + 1,200 = 2,000 ≥ 1,500 with 2 steps → the cut keeps the last two steps (2,000 tokens).
 * The next cut fires above max(4,000, 2,000 + 1,000) = 4,000; with a min of 3,500 and a headroom of 1,000 it would
 * fire above 4,500 instead, so a cut that kept a large block is not followed by another one on the next step.
 *
 * The defaults (min 0, 2 steps, headroom 0) are the rule before the three settings existed: keep the newest two steps,
 * cut above the cap.
 *
 * The cut is a message INDEX, never a wire position: rows written later (a signal, an owner message) never move it, so
 * it holds within one long turn and across turns alike, and on both wire APIs (the items ride the same assistant
 * entries). A call whose snapshot is lost (a discarded response under the system-prompt swap) leaves the anchor of the
 * call before it; the next call derives the same cut from that anchor, since the same rows hold the same items.
 * When the kept steps alone exceed the cap and no headroom is set, every further step moves the cut by one step (each
 * call a partial cache miss) — at the measured 300–650 reasoning tokens per step and the 4,000 default
 * ({@see AgentWireConfiguration::DEFAULT_PREVIOUS_REASONING_CAP_TOKENS}, `AI.contextRetention.previousReasoningCapTokens`)
 * that needs > 2,000 per step.
 *
 * @phpstan-consistent-constructor
 */
class AgentPreviousReasoningCut
{
    /**
     * The steps whose items survive a cut. Two: the step that issued the calls whose results the model reads now and
     * the one before it — the reasoning the model's current plan rests on. Keeping none is fast, but in a measured comparison
     * its pass rate fell to 10/19; keeping more shortens the time to the next crossing: at the
     * measured 300–650 reasoning tokens per step, two steps restart the block at ≈ 0.6–1.3k of the 4k cap, so a cut is
     * followed by about 5–10 steps with a stable prefix. The built-in default of `keptStepsAtCut`
     * ({@see AgentWireConfiguration::DEFAULT_PREVIOUS_REASONING_KEPT_STEPS_AT_CUT}).
     */
    public const int KEPT_NEWEST_STEPS_AT_A_CUT = 2;

    /**
     * The size estimate of a step's items when its snapshot names no reasoning-token count (a snapshot written before
     * the count was recorded): the JSON of the items has a fixed part of ≈ 1,600 bytes (id, type, the encryption
     * envelope) and ≈ 13 bytes per reasoning token — a linear fit over the 36 reasoning items of the replay probe
     * (37 tokens → 1,892 bytes, 258 tokens → 5,012 bytes).
     */
    public const int ESTIMATED_REASONING_ITEMS_JSON_OVERHEAD_BYTES = 1600;

    /** See {@see ESTIMATED_REASONING_ITEMS_JSON_OVERHEAD_BYTES}. */
    public const int ESTIMATED_REASONING_ITEMS_JSON_BYTES_PER_TOKEN = 13;

    /** @var int The steps whose marker row's index is at or above this send their items; every older step sends none. */
    public int $cutFromMessageIndex = 0;

    /** @var int|null The cut the newest earlier call recorded (the anchor); null = none in the context. */
    public ?int $anchorCutFromMessageIndex = null;

    /** @var bool Whether this call moved the cut (the sum since the anchor crossed the cap). */
    public bool $movedOnThisCall = false;

    /** @var int The reasoning tokens of the items this call sends back (the steps at or above the cut). */
    public int $sentReasoningTokens = 0;

    /** @var int The cap the decision compared with. */
    public int $capTokens = 0;

    /** @var int The carried sum above which this call cuts: the cap, or after an earlier cut the reasoning it kept plus the headroom when that is larger. */
    public int $cutAboveTokens = 0;

    /** @var int|null The reasoning the cut at the anchor kept, as far as its steps are in the context; null = no earlier cut found. */
    public ?int $keptTokensAtAnchorCut = null;

    /**
     * The cut of a call on `$wireApi` over the rows of its context (oldest first), with the cap `$capTokens`.
     * `$lowestCutFromMessageIndex`: no step below it sends items whatever the anchor says — the Messages API passes
     * the start of its frozen part and the step of a reported drop ({@see AgentMessagesWireReplay}), so the sum counts
     * only what the call can send. `$stepMarkerMessageIndexesWithoutItems`: steps that send no items whatever the cut
     * says (the Messages API passes the steps whose thinking blocks the provider dropped); they are not counted.
     * `$keptTokensAtCut`, `$keptStepsAtCut`, `$headroomTokensAfterCut`: the min, the kept steps and the headroom of the
     * rule above.
     *
     * @param AIConversationMessage[] $messages
     * @param int[] $stepMarkerMessageIndexesWithoutItems
     */
    public static function decideFor(
        array $messages,
        string $wireApi,
        int $capTokens,
        ?string $modelNameOfCall = null,
        int $lowestCutFromMessageIndex = 0,
        array $stepMarkerMessageIndexesWithoutItems = [],
        int $keptTokensAtCut = AgentWireConfiguration::DEFAULT_PREVIOUS_REASONING_KEPT_TOKENS_AT_CUT,
        int $keptStepsAtCut = self::KEPT_NEWEST_STEPS_AT_A_CUT,
        int $headroomTokensAfterCut = AgentWireConfiguration::DEFAULT_PREVIOUS_REASONING_HEADROOM_TOKENS_AFTER_CUT
    ): static {
        $agentPreviousReasoningCut = new static();
        $agentPreviousReasoningCut->capTokens = $capTokens;
        $agentPreviousReasoningCut->anchorCutFromMessageIndex = self::findRecordedCutOfTheNewestEarlierCall($messages);
        $anchorCutFromMessageIndex = max($agentPreviousReasoningCut->anchorCutFromMessageIndex ?? 0, $lowestCutFromMessageIndex);
        /** @var int[] $reasoningTokensByStepMarkerIndex the steps at or above the anchor, oldest first */
        $reasoningTokensByStepMarkerIndex = self::sumReasoningTokensByStepMarkerIndex($messages, $wireApi, $modelNameOfCall, $anchorCutFromMessageIndex, $stepMarkerMessageIndexesWithoutItems);
        $agentPreviousReasoningCut->cutFromMessageIndex = $anchorCutFromMessageIndex;
        $agentPreviousReasoningCut->sentReasoningTokens = array_sum($reasoningTokensByStepMarkerIndex);
        $agentPreviousReasoningCut->cutAboveTokens = $capTokens;
        if ($headroomTokensAfterCut > 0 && ($agentPreviousReasoningCut->anchorCutFromMessageIndex ?? 0) > 0) {
            $agentPreviousReasoningCut->keptTokensAtAnchorCut = self::findReasoningTokensKeptAtTheCut(
                $messages,
                $agentPreviousReasoningCut->anchorCutFromMessageIndex,
                $reasoningTokensByStepMarkerIndex
            );
            if ($agentPreviousReasoningCut->keptTokensAtAnchorCut !== null) {
                $agentPreviousReasoningCut->cutAboveTokens = max($capTokens, $agentPreviousReasoningCut->keptTokensAtAnchorCut + $headroomTokensAfterCut);
            }
        }
        if ($agentPreviousReasoningCut->sentReasoningTokens <= $agentPreviousReasoningCut->cutAboveTokens) {
            return $agentPreviousReasoningCut;
        }
        // walk back from the newest step: whole steps until the min AND the step count are reached
        $keptReasoningTokensByStepMarkerIndex = [];
        $keptReasoningTokens = 0;
        foreach (array_reverse($reasoningTokensByStepMarkerIndex, true) as $stepMarkerIndex => $stepReasoningTokens) {
            if (count($keptReasoningTokensByStepMarkerIndex) >= max(1, $keptStepsAtCut) && $keptReasoningTokens >= $keptTokensAtCut) {
                break;
            }
            $keptReasoningTokensByStepMarkerIndex[$stepMarkerIndex] = $stepReasoningTokens;
            $keptReasoningTokens += $stepReasoningTokens;
        }
        if (count($keptReasoningTokensByStepMarkerIndex) >= count($reasoningTokensByStepMarkerIndex)) {
            // the min keeps every step: nothing to cut
            return $agentPreviousReasoningCut;
        }
        $agentPreviousReasoningCut->cutFromMessageIndex = (int)array_key_last($keptReasoningTokensByStepMarkerIndex);
        $agentPreviousReasoningCut->sentReasoningTokens = $keptReasoningTokens;
        $agentPreviousReasoningCut->movedOnThisCall = true;
        return $agentPreviousReasoningCut;
    }

    /**
     * The reasoning tokens of the steps at or above `$fromMessageIndex` that send items of `$wireApi`, by marker index,
     * oldest first.
     *
     * @param AIConversationMessage[] $messages
     * @param int[] $stepMarkerMessageIndexesWithoutItems
     * @return int[]
     */
    protected static function sumReasoningTokensByStepMarkerIndex(
        array $messages,
        string $wireApi,
        ?string $modelNameOfCall,
        int $fromMessageIndex,
        array $stepMarkerMessageIndexesWithoutItems
    ): array {
        $reasoningTokensByStepMarkerIndex = [];
        foreach ($messages as $message) {
            $messageIndex = $message->indexInConversation ?? null;
            if ($messageIndex === null || $messageIndex < $fromMessageIndex || !self::isStepMarkerRowSentWithItsReasoning($message)
                || in_array($messageIndex, $stepMarkerMessageIndexesWithoutItems, true)
            ) {
                continue;
            }
            $stepReasoningTokens = self::estimateReasoningTokensOfStep($message->requestSnapshot, $wireApi, $modelNameOfCall);
            if ($stepReasoningTokens !== null) {
                $reasoningTokensByStepMarkerIndex[$messageIndex] = $stepReasoningTokens;
            }
        }
        return $reasoningTokensByStepMarkerIndex;
    }

    /**
     * The reasoning the cut at `$anchorCutFromMessageIndex` kept: the steps at or above the anchor that existed when the
     * call made that cut — the call is the OLDEST row of the context that records the anchor, and the steps it kept are
     * the ones before its row. Null when no row of the context records it (behind a compaction cover).
     *
     * @param AIConversationMessage[] $messages
     * @param int[] $reasoningTokensByStepMarkerIndex the steps at or above the anchor, by marker index
     */
    protected static function findReasoningTokensKeptAtTheCut(array $messages, int $anchorCutFromMessageIndex, array $reasoningTokensByStepMarkerIndex): ?int
    {
        foreach ($messages as $message) {
            $requestSnapshot = isset($message->requestSnapshot) ? $message->requestSnapshot : null;
            if ($requestSnapshot?->previousReasoningCutFromMessageIndex !== $anchorCutFromMessageIndex) {
                continue;
            }
            $cuttingCallMessageIndex = $message->indexInConversation ?? null;
            if ($cuttingCallMessageIndex === null) {
                return null;
            }
            $keptReasoningTokens = 0;
            foreach ($reasoningTokensByStepMarkerIndex as $stepMarkerIndex => $stepReasoningTokens) {
                if ($stepMarkerIndex < $cuttingCallMessageIndex) {
                    $keptReasoningTokens += $stepReasoningTokens;
                }
            }
            return $keptReasoningTokens;
        }
        return null;
    }

    /** Whether the step whose marker row has `$stepMarkerMessageIndex` sends its items on this call. */
    public function sendsTheItemsOfTheStepAt(?int $stepMarkerMessageIndex): bool
    {
        return $stepMarkerMessageIndex !== null && $stepMarkerMessageIndex >= $this->cutFromMessageIndex;
    }

    /**
     * The cut the newest earlier call of the context recorded, scanning from the newest row: a step marker, or the loop
     * signal that carries the record of a discarded response; null when no row of the context carries one.
     *
     * @param AIConversationMessage[] $messages
     */
    public static function findRecordedCutOfTheNewestEarlierCall(array $messages): ?int
    {
        for ($position = count($messages) - 1; $position >= 0; $position--) {
            $requestSnapshot = isset($messages[$position]->requestSnapshot) ? $messages[$position]->requestSnapshot : null;
            if ($requestSnapshot?->previousReasoningCutFromMessageIndex !== null) {
                return $requestSnapshot->previousReasoningCutFromMessageIndex;
            }
        }
        return null;
    }

    /**
     * Whether `$message` is a step marker the egress sends with its reasoning items: the first tool-call row of a step or
     * an assistant row on the wire, stamped with the step's usage (`inputTokens > 0`) and carrying a snapshot — the rows
     * {@see \DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation::withReasoningItemsOfStep()} reads (a
     * signal and the rows the egress never sends carry no items there).
     */
    public static function isStepMarkerRowSentWithItsReasoning(AIConversationMessage $message): bool
    {
        if (($message->inputTokens ?? 0) <= 0 || !isset($message->requestSnapshot)) {
            return false;
        }
        $messageType = $message->messageType ?? null;
        if ($messageType === AIConversationMessage::MESSAGE_TOOL_CALL) {
            return true;
        }
        if (in_array($messageType, self::MESSAGE_TYPES_SENT_WITHOUT_REASONING_ITEMS, true)) {
            return false;
        }
        return strtolower($message->role ?? '') === 'assistant';
    }

    /**
     * The message types that never ride the wire as an assistant entry: the signal (a user entry) and the egress's
     * non-wire rows ({@see \DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation::NON_WIRE_MESSAGE_TYPES}).
     */
    public const array MESSAGE_TYPES_SENT_WITHOUT_REASONING_ITEMS = [
        AIConversationMessage::MESSAGE_SIGNAL,
        AIConversationMessage::MESSAGE_CHECKPOINT,
        AIConversationMessage::MESSAGE_ERROR,
        AIConversationMessage::MESSAGE_TOOL_MESSAGE,
    ];

    /**
     * The size of one step's reasoning items for a call on `$wireApi`, in reasoning tokens: the count the provider
     * reported for that call ({@see RequestSnapshot::$wireReasoningTokens}), else the estimate from the items' JSON
     * length ({@see ESTIMATED_REASONING_ITEMS_JSON_OVERHEAD_BYTES}); null when the step holds no items of that API.
     */
    public static function estimateReasoningTokensOfStep(RequestSnapshot $requestSnapshot, string $wireApi, ?string $modelNameOfCall = null): ?int
    {
        if (!$requestSnapshot->holdsWireReasoningItemsFor($wireApi, $modelNameOfCall)) {
            return null;
        }
        if ($requestSnapshot->wireReasoningTokens !== null) {
            return max(0, $requestSnapshot->wireReasoningTokens);
        }
        $itemsJsonBytes = strlen($requestSnapshot->wireReasoningItemsJson);
        return max(0, intdiv($itemsJsonBytes - self::ESTIMATED_REASONING_ITEMS_JSON_OVERHEAD_BYTES + self::ESTIMATED_REASONING_ITEMS_JSON_BYTES_PER_TOKEN - 1, self::ESTIMATED_REASONING_ITEMS_JSON_BYTES_PER_TOKEN));
    }
}
