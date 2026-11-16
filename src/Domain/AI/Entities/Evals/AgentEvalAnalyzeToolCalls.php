<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The tool calls of a stored case row's message trace ({@see AgentEvalAnalyzeToolCall}), in the order of the trace.
 *
 * @property AgentEvalAnalyzeToolCall[] $elements
 * @method AgentEvalAnalyzeToolCall[] getElements()
 * @method AgentEvalAnalyzeToolCall|null first()
 * @method AgentEvalAnalyzeToolCall|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AgentEvalAnalyzeToolCalls extends ObjectSet
{
    /**
     * The tool calls of a decoded message trace (`messages` of a case row: `elements` of decoded messages); none for
     * a row without a trace.
     *
     * @param mixed $messagesTrace the decoded `messages` of the row
     * @return static
     */
    public static function fromMessagesTrace(mixed $messagesTrace): static
    {
        $agentEvalAnalyzeToolCalls = static::newInstance();
        $traceMessages = is_array($messagesTrace) ? ($messagesTrace['elements'] ?? null) : null;
        foreach (is_array($traceMessages) ? $traceMessages : [] as $traceMessage) {
            if (is_array($traceMessage) && ($traceMessage['messageType'] ?? null) === AgentEvalAnalyzeToolCall::MESSAGE_TYPE_TOOL_CALL) {
                $agentEvalAnalyzeToolCall = AgentEvalAnalyzeToolCall::fromTraceMessage($traceMessage);
                $agentEvalAnalyzeToolCalls->add($agentEvalAnalyzeToolCall);
            }
        }
        return $agentEvalAnalyzeToolCalls;
    }

    /**
     * The calls of tools other than `$excludedToolNames` (the loop's own tools), in their order.
     *
     * @param string[] $excludedToolNames
     * @return static
     */
    public function buildCallsExcept(array $excludedToolNames): static
    {
        $remainingToolCalls = static::newInstance();
        foreach ($this->getElements() as $agentEvalAnalyzeToolCall) {
            if (!in_array($agentEvalAnalyzeToolCall->toolName, $excludedToolNames, true)) {
                $remainingToolCalls->add($agentEvalAnalyzeToolCall);
            }
        }
        return $remainingToolCalls;
    }

    /** Whether one of the calls ended with the status `$status`. */
    public function hasCallWithStatus(string $status): bool
    {
        foreach ($this->getElements() as $agentEvalAnalyzeToolCall) {
            if ($agentEvalAnalyzeToolCall->hasStatus($status)) {
                return true;
            }
        }
        return false;
    }
}
