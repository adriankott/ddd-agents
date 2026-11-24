<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Wire;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Models\AIModel;

/**
 * Which part of the history one model call on the Anthropic Messages API sends back exactly as the earlier calls sent
 * and received it, and which steps go with their thinking blocks. Pure, no repository contact; built by the egress
 * over the rows of the call's context.
 *
 * Why: a thinking block is bound to the conversation prefix that produced it — the system prompt, the tool set and
 * every message before the block. A request that changed anything before a block loses that block and every later
 * one (the provider drops them and reports it). The harness changes history on purpose: it replaces the notes at the
 * end of each call, clears old tool results, shortens the arguments of some calls. So the history has two parts:
 *
 * - Below {@see $frozenFromMessageIndex} the egress renders as on every other wire API — every such change applies,
 *   no thinking block rides.
 * - At or above it a step is FROZEN: the assistant message is the content the provider returned, the tool results
 *   ride in full, an owner message keeps the reply-language cue it was sent with, and the notes of each call stay
 *   where they were sent (hidden from the model by `clear_at`, present for the binding). Nothing before a thinking
 *   block of this part changes between two calls.
 *
 * Thinking blocks ride from {@see $thinkingFromMessageIndex} on (never below the frozen part). Removing the blocks of
 * a LEADING run of steps keeps the later blocks valid (the provider chains each block to the one before it, so only a
 * removal from the middle breaks), which is what a moved thinking floor does.
 *
 * The floors per carry-over mode:
 * - `off`: no frozen part, no thinking block ({@see $frozenFromMessageIndex} null).
 * - `currentTurn`: both floors are the newest turn initiator — at a turn start every earlier step returns to the
 *   plain rendering, which is where the harness clears and shortens anyway.
 * - `all`: the frozen floor is the one the newest earlier call recorded
 *   ({@see RequestSnapshot::$messagesWireFrozenFromMessageIndex}), else the newest turn initiator (the first call, or
 *   the recording rows are behind a compaction cover); the thinking floor equals it.
 * - `capped`: the frozen floor as under `all`; the thinking floor is the cut of {@see AgentPreviousReasoningCut} (the
 *   newest steps whose thinking fits under the cap).
 * In every mode the steps whose thinking blocks the provider dropped
 * ({@see RequestSnapshot::$messagesWireDroppedThinkingStepIndexes}) go without them from the next call on
 * ({@see $droppedThinkingStepMessageIndexes}): that is the request the provider read and cached, so the cache keeps
 * its hits, the thinking blocks before the changed place stay valid and the answer's own block stays bound (probed:
 * the next call reads the whole cache and reports no drop; lifting the floor over all earlier steps instead rewrote
 * the history's cache). A call that reported a drop without the steps (recorded before the list existed, or a path
 * that names no step) lifts the thinking floor to its own step.
 *
 * @phpstan-consistent-constructor
 */
class AgentMessagesWireReplay
{
    /** @var int|null The steps and messages whose row index is at or above this are frozen; null = no frozen part. */
    public ?int $frozenFromMessageIndex = null;

    /** @var int The frozen steps whose marker row's index is at or above this ride with their thinking blocks. */
    public int $thinkingFromMessageIndex = PHP_INT_MAX;

    /** @var int|null The row index of the newest call whose answer reported dropped thinking blocks; null = none in the context. */
    public ?int $droppedThinkingReportedAtMessageIndex = null;

    /** @var int[] The row indexes of the steps whose thinking blocks the provider dropped on an earlier call: they ride without them. */
    public array $droppedThinkingStepMessageIndexes = [];

    /** @var AgentPreviousReasoningCut|null The cut under `capped`; null under every other mode. */
    public ?AgentPreviousReasoningCut $previousReasoningCut = null;

    /**
     * The decision for a call with `$agentWireConfiguration` over the rows of its context (oldest first).
     *
     * @param AIConversationMessage[] $messages
     */
    public static function decideFor(array $messages, AgentWireConfiguration $agentWireConfiguration, ?string $modelNameOfCall = null): static
    {
        $agentMessagesWireReplay = new static();
        if (!$agentWireConfiguration->usesMessagesApi() || !$agentWireConfiguration->carriesPreviousReasoning()) {
            return $agentMessagesWireReplay;
        }
        $messages = array_values($messages);
        $currentTurnInitiatorMessageIndex = self::findNewestTurnInitiatorMessageIndex($messages);
        if ($agentWireConfiguration->carriesOnlyTheCurrentTurnsReasoning()) {
            $agentMessagesWireReplay->frozenFromMessageIndex = $currentTurnInitiatorMessageIndex;
        } else {
            $agentMessagesWireReplay->frozenFromMessageIndex = self::findRecordedFrozenFloorOfTheNewestEarlierCall($messages) ?? $currentTurnInitiatorMessageIndex;
        }
        $agentMessagesWireReplay->thinkingFromMessageIndex = $agentMessagesWireReplay->frozenFromMessageIndex;
        $agentMessagesWireReplay->droppedThinkingReportedAtMessageIndex = self::findMessageIndexOfTheNewestCallThatReportedDroppedThinking($messages);
        $agentMessagesWireReplay->droppedThinkingStepMessageIndexes = self::collectStepMarkerMessageIndexesOfDroppedThinking($messages);
        $droppedThinkingWithoutStepsReportedAtMessageIndex = self::findMessageIndexOfTheNewestCallThatReportedDroppedThinking($messages, true);
        if ($droppedThinkingWithoutStepsReportedAtMessageIndex !== null) {
            $agentMessagesWireReplay->thinkingFromMessageIndex = max(
                $agentMessagesWireReplay->thinkingFromMessageIndex,
                $droppedThinkingWithoutStepsReportedAtMessageIndex
            );
        }
        if ($agentWireConfiguration->carriesCappedPreviousReasoning()) {
            $agentMessagesWireReplay->previousReasoningCut = AgentPreviousReasoningCut::decideFor(
                $messages,
                AIModel::AGENT_WIRE_API_MESSAGES,
                $agentWireConfiguration->previousReasoningCapTokens,
                $modelNameOfCall,
                $agentMessagesWireReplay->thinkingFromMessageIndex,
                $agentMessagesWireReplay->droppedThinkingStepMessageIndexes
            );
            $agentMessagesWireReplay->thinkingFromMessageIndex = $agentMessagesWireReplay->previousReasoningCut->cutFromMessageIndex;
        }
        return $agentMessagesWireReplay;
    }

    /**
     * The floors a recorded call rendered with ({@see RequestSnapshot::$messagesWireFrozenFromMessageIndex},
     * {@see RequestSnapshot::$messagesWireThinkingFromMessageIndex}) — what the forensic reconstruction of that call
     * renders with; a call recorded without a frozen part has none. `$droppedThinkingStepMessageIndexes`: the steps
     * the calls before it reported as dropped ({@see collectStepMarkerMessageIndexesOfDroppedThinking()} over the rows
     * of its context).
     *
     * @param int[] $droppedThinkingStepMessageIndexes
     */
    public static function fromRecordedFloors(?int $frozenFromMessageIndex, ?int $thinkingFromMessageIndex, array $droppedThinkingStepMessageIndexes = []): static
    {
        $agentMessagesWireReplay = new static();
        $agentMessagesWireReplay->droppedThinkingStepMessageIndexes = array_values($droppedThinkingStepMessageIndexes);
        $agentMessagesWireReplay->frozenFromMessageIndex = $frozenFromMessageIndex;
        $agentMessagesWireReplay->thinkingFromMessageIndex = $frozenFromMessageIndex === null ? PHP_INT_MAX : ($thinkingFromMessageIndex ?? $frozenFromMessageIndex);
        return $agentMessagesWireReplay;
    }

    /** Whether the row with `$messageIndex` belongs to the frozen part. */
    public function rendersFrozen(?int $messageIndex): bool
    {
        return $this->frozenFromMessageIndex !== null && $messageIndex !== null && $messageIndex >= $this->frozenFromMessageIndex;
    }

    /** Whether the frozen step whose marker row has `$stepMarkerMessageIndex` rides with its thinking blocks. */
    public function sendsTheThinkingOfTheStepAt(?int $stepMarkerMessageIndex): bool
    {
        return $this->rendersFrozen($stepMarkerMessageIndex) && $stepMarkerMessageIndex >= $this->thinkingFromMessageIndex
            && !in_array($stepMarkerMessageIndex, $this->droppedThinkingStepMessageIndexes, true);
    }

    /**
     * The row index of the newest turn initiator of the context; without one, the index of the oldest row (every row
     * belongs to the current turn); 0 for an empty context.
     *
     * @param AIConversationMessage[] $messages
     */
    public static function findNewestTurnInitiatorMessageIndex(array $messages): int
    {
        for ($position = count($messages) - 1; $position >= 0; $position--) {
            if (AIConversationMessages::isTurnInitiatorMessage($messages[$position]) && ($messages[$position]->indexInConversation ?? null) !== null) {
                return $messages[$position]->indexInConversation;
            }
        }
        foreach ($messages as $message) {
            if (($message->indexInConversation ?? null) !== null) {
                return $message->indexInConversation;
            }
        }
        return 0;
    }

    /**
     * The frozen floor the newest earlier call of the context recorded, scanning from the newest row; null when no
     * row of the context carries one.
     *
     * @param AIConversationMessage[] $messages
     */
    public static function findRecordedFrozenFloorOfTheNewestEarlierCall(array $messages): ?int
    {
        for ($position = count($messages) - 1; $position >= 0; $position--) {
            $requestSnapshot = isset($messages[$position]->requestSnapshot) ? $messages[$position]->requestSnapshot : null;
            if ($requestSnapshot?->messagesWireFrozenFromMessageIndex !== null) {
                return $requestSnapshot->messagesWireFrozenFromMessageIndex;
            }
        }
        return null;
    }

    /**
     * The row index of the newest step marker whose call reported dropped thinking blocks; null when none did.
     * `$onlyWithoutRecordedSteps`: only a call that recorded no steps for its dropped blocks
     * ({@see RequestSnapshot::$messagesWireDroppedThinkingStepIndexes} null) counts.
     *
     * @param AIConversationMessage[] $messages
     */
    public static function findMessageIndexOfTheNewestCallThatReportedDroppedThinking(array $messages, bool $onlyWithoutRecordedSteps = false): ?int
    {
        $messages = array_values($messages);
        for ($position = count($messages) - 1; $position >= 0; $position--) {
            $requestSnapshot = isset($messages[$position]->requestSnapshot) ? $messages[$position]->requestSnapshot : null;
            if ($requestSnapshot === null || !$requestSnapshot->reportsDroppedThinkingBlocks() || ($messages[$position]->indexInConversation ?? null) === null) {
                continue;
            }
            if ($onlyWithoutRecordedSteps && $requestSnapshot->messagesWireDroppedThinkingStepIndexes !== null) {
                continue;
            }
            return $messages[$position]->indexInConversation;
        }
        return null;
    }

    /**
     * The row indexes of the steps whose thinking blocks the calls of the context reported as dropped
     * ({@see RequestSnapshot::$messagesWireDroppedThinkingStepIndexes} of every row), ascending and without repeats.
     *
     * @param AIConversationMessage[] $messages
     * @return int[]
     */
    public static function collectStepMarkerMessageIndexesOfDroppedThinking(array $messages): array
    {
        $stepMarkerMessageIndexes = [];
        foreach ($messages as $message) {
            if (!isset($message->requestSnapshot)) {
                continue;
            }
            foreach ($message->requestSnapshot->messagesWireDroppedThinkingStepIndexes ?? [] as $stepMarkerMessageIndex) {
                $stepMarkerMessageIndexes[(int)$stepMarkerMessageIndex] = (int)$stepMarkerMessageIndex;
            }
        }
        ksort($stepMarkerMessageIndexes);
        return array_values($stepMarkerMessageIndexes);
    }

    /**
     * Whether `$requestSnapshot` holds a stored answer of the Messages API that a frozen step sends back: the content
     * blocks of the call, whatever model produced them (a block another model cannot read is dropped by the provider
     * and reported, and its step rides without thinking from then on).
     */
    public static function holdsStoredAnswerOfTheMessagesApi(?RequestSnapshot $requestSnapshot): bool
    {
        return $requestSnapshot !== null
            && $requestSnapshot->wireReasoningApi === RunSettings::AGENT_WIRE_API_MESSAGES
            && $requestSnapshot->wireReasoningItemsJson !== null
            && $requestSnapshot->wireReasoningItemsJson !== '';
    }
}
