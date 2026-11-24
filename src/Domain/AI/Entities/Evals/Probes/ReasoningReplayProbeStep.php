<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\Probes;

use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation;
use DDD\Domain\AI\Repo\Argus\Conversations\Wire\AgentMessagesRequestComposer;
use DDD\Domain\AI\Repo\Argus\Conversations\Wire\AgentMessagesResponse;

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

    /** @var string|null The Messages API: the answer's content blocks as the provider returned them (a JSON list, sent back verbatim) */
    protected ?string $messagesContentJson = null;

    /** @var string|null The Messages API: the note the call ended on; the next calls keep it at its place, before this step's assistant entry */
    public ?string $noteText = null;

    /** The results of the order task's tools by tool name and the id the call names ({@see buildToolResultOfCall()}) */
    public const array ORDER_TASK_TOOL_RESULTS = [
        'getOrder' => ['4712' => '{"orderId":"4712","customerId":"C-88","items":["saddle"]}', '4713' => '{"orderId":"4713","customerId":"C-17","items":["bell"]}', '*' => '{"orderId":"4711","customerId":"C-17","items":["brake pads","chain"]}'],
        'getCustomer' => ['C-88' => '{"customerId":"C-88","name":"Jonas Weber","city":"50667 Köln"}', '*' => '{"customerId":"C-17","name":"Mara Lindt","city":"80331 München"}'],
        'getInvoice' => ['*' => '{"orderId":"4711","total":"84.90"}'],
    ];

    /**
     * Reads one answer: Chat Completions `choices[0].message` (`content`, `tool_calls`, `reasoning_details`), the
     * Responses `output` items (`reasoning`, `function_call`, `message`) or the Messages `content` blocks (`thinking`,
     * `text`, `tool_use`), plus usage, provider and `input_transformations`.
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
        foreach (is_array($answerBody['input_transformations'] ?? null) ? $answerBody['input_transformations'] : [] as $inputTransformation) {
            if (is_array($inputTransformation) && ($inputTransformation['type'] ?? null) === AgentMessagesResponse::TRANSFORMATION_THINKING_DROPPED) {
                $reasoningReplayProbeCall->droppedThinkingBlockCount++;
            }
        }
        if ($wireApi === RunSettings::AGENT_WIRE_API_MESSAGES) {
            $reasoningReplayProbeStep->readMessagesAnswer($reasoningReplayProbeHttpAnswer->rawBody, $answerBody);
        } elseif ($wireApi === RunSettings::AGENT_WIRE_API_RESPONSES) {
            $reasoningReplayProbeStep->readResponsesOutput((array)($answerBody['output'] ?? []));
            $reasoningReplayProbeStep->readUsage((array)($answerBody['usage'] ?? []));
        } else {
            $reasoningReplayProbeStep->readChatCompletionsMessage((array)($answerBody['choices'][0]['message'] ?? []));
            $reasoningReplayProbeStep->readUsage((array)($answerBody['usage'] ?? []));
        }
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
        if ($this->messagesContentJson !== null) {
            // the Messages API: the content blocks go back exactly as they came
            $assistantEntry[AgentMessagesRequestComposer::KEY_ASSISTANT_CONTENT_JSON] = $this->messagesContentJson;
            return $assistantEntry;
        }
        if ($this->reasoningItems !== []) {
            $assistantEntry['reasoning_details'] = $this->reasoningItems;
        }
        return $assistantEntry;
    }

    /**
     * The entries this step adds to the next call's history: on the Messages API the note its call ended on (a
     * `role: "system"` message with `clear_at`, kept at its place), then the assistant entry and the tool results.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildHistoryEntries(): array
    {
        $historyEntries = [];
        if ($this->noteText !== null) {
            $historyEntries[] = ArgusAIAgentConversation::buildMessagesWireNoteEntry([$this->noteText]);
        }
        $historyEntries[] = $this->buildAssistantEntry();
        foreach ($this->buildToolResultEntries() as $toolResultEntry) {
            $historyEntries[] = $toolResultEntry;
        }
        return $historyEntries;
    }

    /** Whether the answer carries a tool call (the step continues the loop). */
    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
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
            $toolResultEntries[] = [
                'role' => 'tool',
                'tool_call_id' => (string)($toolCall['id'] ?? ''),
                'content' => self::buildToolResultOfCall((string)($toolCall['function']['name'] ?? ''), (string)($toolCall['function']['arguments'] ?? '')),
            ];
        }
        return $toolResultEntries;
    }

    /**
     * The result of one probe tool call: a tool of the order task answers by the id its arguments name
     * ({@see ORDER_TASK_TOOL_RESULTS}), every other tool with {@see TOOL_RESULT}.
     */
    public static function buildToolResultOfCall(string $toolName, string $argumentsJson): string
    {
        $resultsOfTool = self::ORDER_TASK_TOOL_RESULTS[$toolName] ?? null;
        if ($resultsOfTool === null) {
            return self::TOOL_RESULT;
        }
        foreach ($resultsOfTool as $namedId => $result) {
            if ($namedId !== '*' && str_contains($argumentsJson, '"' . $namedId . '"')) {
                return $result;
            }
        }
        return $resultsOfTool['*'];
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
     * An answer of the Messages API: the content blocks kept verbatim for the replay, the thinking blocks as the
     * reasoning items, the `tool_use` blocks as calls, and Anthropic's three disjoint input counts.
     *
     * @param array<string, mixed> $answerBody
     */
    protected function readMessagesAnswer(string $rawBody, array $answerBody): void
    {
        $answerObject = json_decode($rawBody);
        if (AgentMessagesResponse::isMessagesObject($answerObject)) {
            $this->messagesContentJson = AgentMessagesResponse::fromResponseObject($answerObject)->getContentBlocksJson();
        }
        $texts = [];
        foreach ((array)($answerBody['content'] ?? []) as $contentBlock) {
            if (!is_array($contentBlock)) {
                continue;
            }
            $blockType = $contentBlock['type'] ?? null;
            if (in_array($blockType, AgentMessagesRequestComposer::THINKING_BLOCK_TYPES, true)) {
                $this->reasoningItems[] = $contentBlock;
            } elseif ($blockType === 'tool_use') {
                $this->toolCalls[] = [
                    'id' => (string)($contentBlock['id'] ?? ''),
                    'type' => 'function',
                    'function' => ['name' => (string)($contentBlock['name'] ?? ''), 'arguments' => (string)json_encode($contentBlock['input'] ?? new \stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
                ];
            } elseif ($blockType === 'text' && is_string($contentBlock['text'] ?? null)) {
                $texts[] = $contentBlock['text'];
            }
        }
        $this->assistantText = $texts !== [] ? implode("\n", $texts) : null;
        $usage = (array)($answerBody['usage'] ?? []);
        $cacheReadTokens = is_numeric($usage['cache_read_input_tokens'] ?? null) ? (int)$usage['cache_read_input_tokens'] : 0;
        $cacheWriteTokens = is_numeric($usage['cache_creation_input_tokens'] ?? null) ? (int)$usage['cache_creation_input_tokens'] : 0;
        $this->call->inputTokens = is_numeric($usage['input_tokens'] ?? null) ? (int)$usage['input_tokens'] + $cacheReadTokens + $cacheWriteTokens : null;
        $this->call->cachedInputTokens = $cacheReadTokens;
        $this->call->cacheWriteTokens = $cacheWriteTokens;
        $this->call->outputTokens = is_numeric($usage['output_tokens'] ?? null) ? (int)$usage['output_tokens'] : null;
        $this->call->reasoningTokens = is_numeric($usage['output_tokens_details']['thinking_tokens'] ?? null) ? (int)$usage['output_tokens_details']['thinking_tokens'] : null;
        $this->call->costInUsd = is_numeric($usage['cost'] ?? null) ? (float)$usage['cost'] : null;
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
