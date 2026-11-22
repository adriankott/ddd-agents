<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\AI;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;

/**
 * How often a tool was called in the CURRENT turn of the conversation, for a tool that enforces a per-turn limit
 * (`searchTheWeb` and `AI.webSearch.hits.maxSearchCallsPerTurn`). Reads the turn like {@see ToolBatchAdoptionNote}:
 * from the newest turn initiator on, the calling row included (the loop persists it PENDING before it executes the
 * call). A call refused as a duplicate, refused by a gate or failed (a refusal of the tool itself) did no work and is
 * not counted. Outside the agent loop (no conversation, an evaluation call) there is no count.
 */
class ToolCallsOfTheCurrentTurn
{
    /** The calls of `$toolName` in the current turn that did work, this call included; null outside the agent loop. */
    public static function countCallsThatDidWork(AgentToolCallProvenance $callProvenance, string $toolName): ?int
    {
        if ($callProvenance->isEvalHarnessCall) {
            return null;
        }
        $conversation = $callProvenance->conversation;
        $messages = $conversation !== null && isset($conversation->messages) ? $conversation->messages : $callProvenance->conversationMessages;
        if (!$messages instanceof AIConversationMessages) {
            return null;
        }
        return self::countCallsThatDidWorkInMessages($messages, $toolName);
    }

    /** The calls of `$toolName` in the current turn of `$messages` that did work. Pure. */
    public static function countCallsThatDidWorkInMessages(AIConversationMessages $messages, string $toolName): int
    {
        $callCount = 0;
        foreach ($messages->buildCurrentTurn()->getRows() as $message) {
            $toolCall = isset($message->toolCall) ? $message->toolCall : null;
            if ($toolCall === null || ($toolCall->name ?? null) !== $toolName
                || $toolCall->wasRefusedAsDuplicate || $toolCall->wasRefusedByGate || $toolCall->isError()
            ) {
                continue;
            }
            $callCount++;
        }
        return $callCount;
    }
}
