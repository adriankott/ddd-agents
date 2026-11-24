<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\Probes;

use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;

/**
 * One answered probe call read back: its call record, and the assistant entry the next call sends (text, tool calls
 * and the reasoning items exactly as they came, for a verbatim replay) plus fixed tool results.
 */
class ReasoningReplayProbeStep
{
    /** The fixed result every probe tool returns */
    public const string TOOL_RESULT = '{"temperatureC":7,"conditions":"cloudy","airQualityIndex":21,"localTime":"14:05"}';

    public ReasoningReplayProbeCall $call;

    /** @var string|null The assistant's visible text */
    protected ?string $assistantText = null;

    /** @var array<int, array<string, mixed>> The tool calls in the Chat-Completions form */
    protected array $toolCalls = [];

    /** @var array<int, array<string, mixed>> The reasoning items as the API returned them (replayed verbatim) */
    protected array $reasoningItems = [];

    /**
     * Reads one answer: Chat Completions `choices[0].message` (`content`, `tool_calls`, `reasoning_details`) or the
     * Responses `output` items (`reasoning`, `function_call`, `message`), plus usage, provider and
     * `input_transformations`.
     */
    public static function fromAnswer(string $wireApi, string $label, string $modelName, ReasoningReplayProbeHttpAnswer $reasoningReplayProbeHttpAnswer): static
    {
        $reasoningReplayProbeStep = new static();
        $reasoningReplayProbeCall = new ReasoningReplayProbeCall();
        $reasoningReplayProbeCall->label = $label;
        $reasoningReplayProbeCall->modelName = $modelName;
        $reasoningReplayProbeCall->httpStatus = $reasoningReplayProbeHttpAnswer->statusCode;
        $reasoningReplayProbeCall->durationMs = $reasoningReplayProbeHttpAnswer->durationMs;
        $reasoningReplayProbeStep->call = $reasoningReplayProbeCall;
        if ($reasoningReplayProbeHttpAnswer->transportError !== null) {
            $reasoningReplayProbeCall->errorText = 'transport: ' . $reasoningReplayProbeHttpAnswer->transportError;
            return $reasoningReplayProbeStep;
        }
        $answerBody = json_decode($reasoningReplayProbeHttpAnswer->rawBody, true);
        if (!is_array($answerBody)) {
            $reasoningReplayProbeCall->errorText = 'no JSON body: ' . mb_substr($reasoningReplayProbeHttpAnswer->rawBody, 0, 300);
            return $reasoningReplayProbeStep;
        }
        $errorText = self::readErrorText($answerBody);
        if ($errorText !== null || ($reasoningReplayProbeHttpAnswer->statusCode ?? 0) >= 400) {
            $reasoningReplayProbeCall->errorText = $errorText ?? ('HTTP ' . $reasoningReplayProbeHttpAnswer->statusCode . ': ' . mb_substr($reasoningReplayProbeHttpAnswer->rawBody, 0, 300));
            return $reasoningReplayProbeStep;
        }
        $reasoningReplayProbeCall->isAccepted = true;
        $reasoningReplayProbeCall->provider = is_string($answerBody['provider'] ?? null) ? $answerBody['provider'] : null;
        if (array_key_exists('input_transformations', $answerBody)) {
            $reasoningReplayProbeCall->inputTransformationsJson = (string)json_encode($answerBody['input_transformations'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if ($wireApi === RunSettings::AGENT_WIRE_API_RESPONSES) {
            $reasoningReplayProbeStep->readResponsesOutput((array)($answerBody['output'] ?? []));
        } else {
            $reasoningReplayProbeStep->readChatCompletionsMessage((array)($answerBody['choices'][0]['message'] ?? []));
        }
        $reasoningReplayProbeStep->readUsage((array)($answerBody['usage'] ?? []));
        $reasoningReplayProbeCall->reasoningItemCount = count($reasoningReplayProbeStep->reasoningItems);
        foreach ($reasoningReplayProbeStep->reasoningItems as $reasoningItem) {
            $reasoningReplayProbeCall->reasoningItemTypes[] = (string)($reasoningItem['type'] ?? '?');
            if (self::isSignedReasoningItem($reasoningItem)) {
                $reasoningReplayProbeCall->hasSignedReasoning = true;
            }
        }
        foreach ($reasoningReplayProbeStep->toolCalls as $toolCall) {
            $reasoningReplayProbeCall->calledToolNames[] = (string)($toolCall['function']['name'] ?? '');
        }
        return $reasoningReplayProbeStep;
    }

    public function countReasoningItems(): int
    {
        return count($this->reasoningItems);
    }

    /**
     * The assistant entry of this step for the next call, its reasoning items as `reasoning_details` unchanged.
     *
     * @return array<string, mixed>
     */
    public function buildAssistantEntry(): array
    {
        $assistantEntry = ['role' => 'assistant', 'content' => $this->assistantText];
        if ($this->toolCalls !== []) {
            $assistantEntry['tool_calls'] = $this->toolCalls;
        }
        if ($this->reasoningItems !== []) {
            $assistantEntry['reasoning_details'] = $this->reasoningItems;
        }
        return $assistantEntry;
    }

    /**
     * One `tool` entry per tool call, each with the fixed result.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildToolResultEntries(): array
    {
        $toolResultEntries = [];
        foreach ($this->toolCalls as $toolCall) {
            $toolResultEntries[] = ['role' => 'tool', 'tool_call_id' => (string)($toolCall['id'] ?? ''), 'content' => self::TOOL_RESULT];
        }
        return $toolResultEntries;
    }

    /**
     * A reasoning item a replay must send back unchanged: it carries a signature or encrypted content.
     *
     * @param array<string, mixed> $reasoningItem
     */
    public static function isSignedReasoningItem(array $reasoningItem): bool
    {
        foreach (['signature', 'encrypted_content', 'data'] as $signedKey) {
            if (is_string($reasoningItem[$signedKey] ?? null) && $reasoningItem[$signedKey] !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * The provider's error text of a refused call: `error.message`, with the upstream's raw text when OpenRouter
     * passes it on.
     *
     * @param array<string, mixed> $answerBody
     */
    protected static function readErrorText(array $answerBody): ?string
    {
        $error = $answerBody['error'] ?? null;
        if (!is_array($error)) {
            return is_string($error) && $error !== '' ? mb_substr($error, 0, 600) : null;
        }
        $errorText = (string)($error['message'] ?? 'error');
        $rawUpstreamText = $error['metadata']['raw'] ?? null;
        if (is_string($rawUpstreamText) && $rawUpstreamText !== '') {
            $errorText .= ' | upstream: ' . $rawUpstreamText;
        }
        return mb_substr($errorText, 0, 600);
    }

    /**
     * @param array<string, mixed> $assistantMessage
     */
    protected function readChatCompletionsMessage(array $assistantMessage): void
    {
        $this->assistantText = is_string($assistantMessage['content'] ?? null) ? $assistantMessage['content'] : null;
        foreach ((array)($assistantMessage['tool_calls'] ?? []) as $toolCall) {
            if (is_array($toolCall)) {
                $this->toolCalls[] = $toolCall;
            }
        }
        foreach ((array)($assistantMessage['reasoning_details'] ?? []) as $reasoningItem) {
            if (is_array($reasoningItem)) {
                $this->reasoningItems[] = $reasoningItem;
            }
        }
    }

    /**
     * @param array<int, mixed> $outputItems
     */
    protected function readResponsesOutput(array $outputItems): void
    {
        $texts = [];
        foreach ($outputItems as $outputItem) {
            if (!is_array($outputItem)) {
                continue;
            }
            $outputItemType = $outputItem['type'] ?? null;
            if ($outputItemType === 'reasoning') {
                $this->reasoningItems[] = $outputItem;
            } elseif ($outputItemType === 'function_call') {
                $this->toolCalls[] = [
                    'id' => (string)($outputItem['call_id'] ?? $outputItem['id'] ?? ''),
                    'type' => 'function',
                    'function' => ['name' => (string)($outputItem['name'] ?? ''), 'arguments' => (string)($outputItem['arguments'] ?? '{}')],
                ];
            } elseif ($outputItemType === 'message') {
                foreach ((array)($outputItem['content'] ?? []) as $contentPart) {
                    if (is_array($contentPart) && is_string($contentPart['text'] ?? null)) {
                        $texts[] = $contentPart['text'];
                    }
                }
            }
        }
        $this->assistantText = $texts !== [] ? implode("\n", $texts) : null;
    }

    /**
     * Chat (`prompt_tokens`, `prompt_tokens_details`) and Responses (`input_tokens`, `input_tokens_details`) usage.
     *
     * @param array<string, mixed> $usage
     */
    protected function readUsage(array $usage): void
    {
        $inputTokens = $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? null;
        $outputTokens = $usage['completion_tokens'] ?? $usage['output_tokens'] ?? null;
        $inputTokenDetails = (array)($usage['prompt_tokens_details'] ?? $usage['input_tokens_details'] ?? []);
        $this->call->inputTokens = is_numeric($inputTokens) ? (int)$inputTokens : null;
        $this->call->outputTokens = is_numeric($outputTokens) ? (int)$outputTokens : null;
        $this->call->cachedInputTokens = is_numeric($inputTokenDetails['cached_tokens'] ?? null) ? (int)$inputTokenDetails['cached_tokens'] : null;
        $this->call->cacheWriteTokens = is_numeric($inputTokenDetails['cache_write_tokens'] ?? null) ? (int)$inputTokenDetails['cache_write_tokens'] : null;
        $this->call->costInUsd = is_numeric($usage['cost'] ?? null) ? (float)$usage['cost'] : null;
    }
}
