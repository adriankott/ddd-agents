<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\AI;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;

/**
 * The in-result corrective of a tool that takes ALL targets of a step in ONE call (`getWebPageAsMarkdown` with `urls`,
 * `searchTheWeb` with `queries`, an application's own batch tools): when a call is the SECOND (or later) single-target
 * call of the same tool in the CURRENT turn, its result leads with ONE line that names the batch argument for next
 * time. Never a refusal: a refusal costs another model call, and the result still arrives.
 *
 * Reads the turn from the conversation of the provenance (the live conversation's messages, else the executor's message
 * set). The calling row is part of that set — the loop persists a PENDING row before it executes the call, inline and in
 * a fork-join child alike — so "at least two single-target calls in this turn" means "this call is a repeat". Outside
 * the agent loop (no conversation, an evaluation call) there is no line.
 */
class ToolBatchAdoptionNote
{
    /** The ordinal words of the line ("second", "third", …); a later repeat is numbered. */
    protected const array ORDINAL_WORDS = [2 => 'second', 3 => 'third', 4 => 'fourth', 5 => 'fifth'];

    /**
     * The leading line for a repeated single-target call of `$toolName` in the current turn, or null when this call is
     * the first single-target call (or the call runs outside the agent loop).
     *
     * @param string $singleTargetArgumentName the argument that takes ONE target (`url`, `query`)
     * @param string $batchArgumentName the argument that takes ALL targets (`urls`, `queries`)
     * @param string $singleCallLabel what one single call is, for the line ("single-page read")
     * @param string $batchTargetsLabel what belongs into one batch, for the line ("all pages of a step")
     */
    public static function buildForRepeatedSingleTargetCall(
        AgentToolCallProvenance $callProvenance,
        string $toolName,
        string $singleTargetArgumentName,
        string $batchArgumentName,
        string $singleCallLabel,
        string $batchTargetsLabel,
    ): ?string {
        if ($callProvenance->isEvalHarnessCall) {
            return null;
        }
        $conversation = $callProvenance->conversation;
        $messages = $conversation !== null && isset($conversation->messages) ? $conversation->messages : $callProvenance->conversationMessages;
        if (!$messages instanceof AIConversationMessages) {
            return null;
        }
        $singleTargetCallCount = self::countSingleTargetCallsInCurrentTurn($messages, $toolName, $singleTargetArgumentName, $batchArgumentName);
        if ($singleTargetCallCount < 2) {
            return null;
        }
        return self::composeLine($singleTargetCallCount, $singleCallLabel, $batchTargetsLabel, $batchArgumentName);
    }

    /** "NOTE: second single-page read in this turn — next time put all pages of a step into ONE call's `urls`." */
    public static function composeLine(int $singleTargetCallCount, string $singleCallLabel, string $batchTargetsLabel, string $batchArgumentName): string
    {
        $ordinalPhrase = isset(self::ORDINAL_WORDS[$singleTargetCallCount])
            ? self::ORDINAL_WORDS[$singleTargetCallCount] . ' ' . $singleCallLabel
            : $singleCallLabel . ' number ' . $singleTargetCallCount;
        return 'NOTE: ' . $ordinalPhrase . ' in this turn — next time put ' . $batchTargetsLabel
            . ' into ONE call\'s `' . $batchArgumentName . '`.';
    }

    /**
     * The calls of `$toolName` in the CURRENT turn (from the newest turn initiator on, the loop's own boundary —
     * {@see AIConversationMessages::isTurnInitiatorMessage()}) that carried at most one target. A call the loop
     * refused as a duplicate or that a gate refused did no work and is not counted.
     */
    public static function countSingleTargetCallsInCurrentTurn(
        AIConversationMessages $messages,
        string $toolName,
        string $singleTargetArgumentName,
        string $batchArgumentName,
    ): int {
        $currentTurnMessages = $messages->buildCurrentTurn()->getRows();
        $singleTargetCallCount = 0;
        foreach ($currentTurnMessages as $message) {
            $toolCall = isset($message->toolCall) ? $message->toolCall : null;
            if ($toolCall === null || ($toolCall->name ?? null) !== $toolName
                || $toolCall->wasRefusedAsDuplicate || $toolCall->wasRefusedByGate
            ) {
                continue;
            }
            if (self::countDistinctTargets($toolCall->arguments, $singleTargetArgumentName, $batchArgumentName) <= 1) {
                $singleTargetCallCount++;
            }
        }
        return $singleTargetCallCount;
    }

    /**
     * The distinct, non-empty targets one call carried across its single and its batch argument (the tools merge
     * both into one list, so `url` + a different single entry in `urls` is a batch of two).
     *
     * @param array<string, mixed> $arguments
     */
    public static function countDistinctTargets(array $arguments, string $singleTargetArgumentName, string $batchArgumentName): int
    {
        $targets = [];
        $batchTargets = $arguments[$batchArgumentName] ?? [];
        foreach (array_merge([$arguments[$singleTargetArgumentName] ?? null], is_array($batchTargets) ? $batchTargets : [$batchTargets]) as $target) {
            if (!is_scalar($target)) {
                continue;
            }
            $normalizedTarget = mb_strtolower(trim((string)$target));
            if ($normalizedTarget !== '') {
                $targets[$normalizedTarget] = true;
            }
        }
        return count($targets);
    }
}
