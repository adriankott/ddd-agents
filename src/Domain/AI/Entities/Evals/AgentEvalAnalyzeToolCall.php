<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One tool call of the message trace a stored case row carries, as the failure analysis reads it: the tool's name, its
 * arguments as text and its terminal status. A trace message keeps the call in its `toolCall` object (`name`,
 * `arguments`, `status`); a trace written before that carried `toolName`, `toolArguments` and `status` on the message
 * itself, which are read first.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalAnalyzeToolCall extends ValueObject
{
    /** @var string The `messageType` of a trace message that is a tool call */
    public const string MESSAGE_TYPE_TOOL_CALL = 'TOOL_CALL';

    /** @var string The name of the tool; empty when the trace names none */
    public string $toolName = '';

    /** @var string The arguments as text (a JSON object as compact JSON); empty when the trace holds none */
    public string $toolArguments = '';

    /** @var string The terminal status (`COMPLETED`, `FAILED`, …); empty when the trace holds none */
    public string $status = '';

    /**
     * The tool call of one decoded trace message (`messageType: TOOL_CALL`).
     *
     * @param array $traceMessage the decoded message of the trace
     * @return static
     */
    public static function fromTraceMessage(array $traceMessage): static
    {
        $toolCall = is_array($traceMessage['toolCall'] ?? null) ? $traceMessage['toolCall'] : [];
        $agentEvalAnalyzeToolCall = static::newInstance();
        $agentEvalAnalyzeToolCall->toolName = static::describeTraceValue($traceMessage['toolName'] ?? $toolCall['name'] ?? null);
        $agentEvalAnalyzeToolCall->toolArguments = static::describeTraceValue($traceMessage['toolArguments'] ?? $toolCall['arguments'] ?? null);
        $agentEvalAnalyzeToolCall->status = static::describeTraceValue($traceMessage['status'] ?? $toolCall['status'] ?? null);
        return $agentEvalAnalyzeToolCall;
    }

    /** A value of the trace as text: a scalar as it is, a list or an object as compact JSON, null as empty. */
    public static function describeTraceValue(mixed $traceValue): string
    {
        if ($traceValue === null) {
            return '';
        }
        if (is_array($traceValue)) {
            return (string)json_encode($traceValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return is_scalar($traceValue) ? (string)$traceValue : '';
    }

    /** Whether the call ended with the status `$status`. */
    public function hasStatus(string $status): bool
    {
        return $this->status === $status;
    }
}
