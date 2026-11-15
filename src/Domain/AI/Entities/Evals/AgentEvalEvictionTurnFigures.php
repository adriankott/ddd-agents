<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\LoadToolTool;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The figures of ONE turn of an eviction trajectory (several owner turns on one continuing conversation), taken right
 * after the turn: model calls, `load_tool` hops, reads refused as duplicates, input and cached tokens, and the
 * seconds the caller measured around the turn. Read off the ROOT conversation's current turn. No I/O.
 *
 * Model call here: a row of the CURRENT TURN (from the newest turn initiator on, in set order) with input tokens above
 * 0 ({@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationTurnMessages::countModelCallSteps()}) — one of
 * the three model-call counts of the evaluation ({@see AgentEvalRunFigures}).
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalEvictionTurnFigures extends ValueObject
{
    /** @var int The model calls of the current turn: its rows with input tokens above 0 */
    public int $modelCalls = 0;

    /** @var int The `load_tool` calls of the current turn */
    public int $loadToolHops = 0;

    /** @var int The tool calls refused as a duplicate, on rows whose message index is at or after the newest turn initiator's */
    public int $refusedReads = 0;

    /** @var int The input tokens of the rows whose message index is at or after the newest turn initiator's */
    public int $inputTokens = 0;

    /** @var int The cached input tokens of the rows whose message index is at or after the newest turn initiator's */
    public int $cachedInputTokens = 0;

    /** @var float The seconds the caller measured around the turn, one decimal */
    public float $seconds = 0.0;

    /**
     * The figures of the conversation's current turn.
     *
     * @param float $turnSeconds the wall-clock seconds the caller measured around the turn
     */
    public static function fromCurrentTurnOfConversation(AIConversation $conversation, float $turnSeconds): static
    {
        $agentEvalEvictionTurnFigures = static::newInstance();
        $messages = $conversation->messages;
        $initiatorMessageIndex = $messages->getNewestInitiatorMessageIndex() ?? -1;
        foreach ($messages->getElements() as $message) {
            if (($message->indexInConversation ?? -1) < $initiatorMessageIndex) {
                continue;
            }
            $agentEvalEvictionTurnFigures->inputTokens += (int)($message->inputTokens ?? 0);
            $agentEvalEvictionTurnFigures->cachedInputTokens += (int)($message->cachedInputTokens ?? 0);
            $toolCall = isset($message->toolCall) ? $message->toolCall : null;
            if ($toolCall !== null && $toolCall->wasRefusedAsDuplicate) {
                $agentEvalEvictionTurnFigures->refusedReads++;
            }
        }
        $agentEvalEvictionTurnFigures->modelCalls = $messages->buildCurrentTurn()->countModelCallSteps();
        $agentEvalEvictionTurnFigures->loadToolHops = $messages->buildCurrentTurn()->countToolCallsNamed(LoadToolTool::NAME);
        $agentEvalEvictionTurnFigures->seconds = round($turnSeconds, 1);
        return $agentEvalEvictionTurnFigures;
    }

    /**
     * The figures with the keys a trajectory turn stores under `metrics`.
     *
     * @return array{modelCalls: int, loadToolHops: int, refusedReads: int, inputTokens: int, cachedInputTokens: int, seconds: float}
     */
    public function toArray(): array
    {
        return [
            'modelCalls' => $this->modelCalls,
            'loadToolHops' => $this->loadToolHops,
            'refusedReads' => $this->refusedReads,
            'inputTokens' => $this->inputTokens,
            'cachedInputTokens' => $this->cachedInputTokens,
            'seconds' => $this->seconds,
        ];
    }
}
