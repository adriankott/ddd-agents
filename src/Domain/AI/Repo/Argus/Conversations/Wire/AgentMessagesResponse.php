<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Conversations\Wire;

use DDD\Domain\AI\Entities\ModelCalls\AIModelCallUsage;

/**
 * Reads an answer of the Anthropic Messages API (`type: "message"` with a list of content blocks): its text, its
 * usage and its calls (in the shapes the shared language-model reader books and decodes), the content blocks as they
 * came (the next request sends them back verbatim), the thinking they carry, the blocks the provider dropped from the
 * REQUEST (`input_transformations`) and why an answer carries nothing to act on. Pure.
 *
 * @phpstan-consistent-constructor
 */
class AgentMessagesResponse
{
    /** An entry of `input_transformations` for a thinking block the provider removed from the request. */
    public const string TRANSFORMATION_THINKING_DROPPED = 'thinking_dropped';

    /** The block's conversation prefix (system, tools, earlier messages) changed since the block was produced. */
    public const string REASON_PREFIX_BINDING_MISMATCH = 'prefix_binding_mismatch';

    /** The block was produced by a model whose thinking this call's model cannot read. */
    public const string REASON_MODEL_BINDING_MISMATCH = 'model_binding_mismatch';

    public object $responseObject;

    public static function isMessagesObject(mixed $responseObject): bool
    {
        return is_object($responseObject) && ($responseObject->type ?? null) === 'message' && isset($responseObject->content) && is_array($responseObject->content);
    }

    public static function fromResponseObject(object $responseObject): static
    {
        $agentMessagesResponse = new static();
        $agentMessagesResponse->responseObject = $responseObject;
        return $agentMessagesResponse;
    }

    /**
     * The answer's content blocks as a JSON list, objects kept as objects — what the step's marker row stores and the
     * next request sends back; null for an answer without a block.
     */
    public function getContentBlocksJson(): ?string
    {
        if ($this->responseObject->content === []) {
            return null;
        }
        $contentBlocksJson = json_encode(array_values($this->responseObject->content), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($contentBlocksJson) ? $contentBlocksJson : null;
    }

    /**
     * The answer's text: its `text` blocks in order, joined by a line break; null when it carries none (a step of
     * tool calls only, a refusal with empty content). Thinking blocks are never text.
     */
    public function getText(): ?string
    {
        $texts = [];
        foreach ($this->responseObject->content as $contentBlock) {
            if (is_object($contentBlock) && ($contentBlock->type ?? null) === 'text' && isset($contentBlock->text) && (string)$contentBlock->text !== '') {
                $texts[] = (string)$contentBlock->text;
            }
        }
        return $texts !== [] ? implode("\n", $texts) : null;
    }

    /**
     * The answer's token usage, unpriced, in the counts the cost booking expects. The provider names three disjoint
     * input counts: `input_tokens` (the uncached remainder), `cache_read_input_tokens` and
     * `cache_creation_input_tokens`; the booked input is their sum, like `prompt_tokens` on Chat Completions. The
     * thinking tokens (`output_tokens_details.thinking_tokens`) are part of `output_tokens`. Null without a usage block.
     */
    public function getUsage(): ?AIModelCallUsage
    {
        $usage = $this->responseObject->usage ?? null;
        if (!is_object($usage)) {
            return null;
        }
        $cachedInputTokens = (int)($usage->cache_read_input_tokens ?? 0);
        $cacheWriteTokens = (int)($usage->cache_creation_input_tokens ?? 0);
        return new AIModelCallUsage(
            inputTokens: (int)($usage->input_tokens ?? 0) + $cachedInputTokens + $cacheWriteTokens,
            outputTokens: (int)($usage->output_tokens ?? 0),
            cachedInputTokens: $cachedInputTokens,
            cacheWriteTokens: $cacheWriteTokens,
            reasoningOutputTokens: (int)($usage->output_tokens_details->thinking_tokens ?? 0),
        );
    }

    /**
     * The answer's `tool_use` blocks in the Chat-Completions shape (`id`, `type`, `function.name`,
     * `function.arguments` as a JSON string), so the loop decodes calls the same way on every wire API; null when the
     * answer carries none. An empty input is the JSON object `{}`.
     *
     * @return array<int, object>|null
     */
    public function getToolCallsInChatCompletionsShape(): ?array
    {
        $toolCalls = [];
        foreach ($this->responseObject->content as $contentBlock) {
            if (!is_object($contentBlock) || ($contentBlock->type ?? null) !== 'tool_use') {
                continue;
            }
            $input = $contentBlock->input ?? null;
            $argumentsJson = (is_object($input) || is_array($input)) && (array)$input !== []
                ? (string)json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : '{}';
            $toolCalls[] = (object)[
                'id' => (string)($contentBlock->id ?? ''),
                'type' => 'function',
                'function' => (object)['name' => (string)($contentBlock->name ?? ''), 'arguments' => $argumentsJson],
            ];
        }
        return $toolCalls !== [] ? $toolCalls : null;
    }

    /** How many thinking blocks (`thinking` / `redacted_thinking`) the answer carries. */
    public function countThinkingBlocks(): int
    {
        $thinkingBlockCount = 0;
        foreach ($this->responseObject->content as $contentBlock) {
            if (is_object($contentBlock) && in_array($contentBlock->type ?? null, AgentMessagesRequestComposer::THINKING_BLOCK_TYPES, true)) {
                $thinkingBlockCount++;
            }
        }
        return $thinkingBlockCount;
    }

    /** The thinking tokens the provider reports for the answer (`usage.output_tokens_details.thinking_tokens`); null when it names none. */
    public function getThinkingTokens(): ?int
    {
        $thinkingTokens = $this->responseObject->usage->output_tokens_details->thinking_tokens ?? null;
        return is_numeric($thinkingTokens) ? (int)$thinkingTokens : null;
    }

    /**
     * What the provider changed in the request before the model read it: one entry per affected block with its `type`
     * (`thinking_dropped`), the block's `path` in the request (`messages.4.content.0`) and the `reason`. Empty when
     * nothing was changed; the key is absent from such an answer.
     *
     * @return AgentMessagesInputTransformation[]
     */
    public function getInputTransformations(): array
    {
        $inputTransformations = [];
        foreach ((array)($this->responseObject->input_transformations ?? []) as $reportedTransformation) {
            if (!is_object($reportedTransformation)) {
                continue;
            }
            $agentMessagesInputTransformation = new AgentMessagesInputTransformation();
            $agentMessagesInputTransformation->type = (string)($reportedTransformation->type ?? '');
            $agentMessagesInputTransformation->path = (string)($reportedTransformation->path ?? '');
            $agentMessagesInputTransformation->reason = (string)($reportedTransformation->reason ?? '');
            $inputTransformations[] = $agentMessagesInputTransformation;
        }
        return $inputTransformations;
    }

    /**
     * Why the answer carries neither text nor a call: a refusal (`stop_reason: "refusal"`, with the category the
     * provider names) or an answer cut off at the output limit before any text or call; null for a usable answer.
     */
    public function findUnusableAnswerReason(): ?string
    {
        $stopReason = (string)($this->responseObject->stop_reason ?? '');
        if ($stopReason === 'refusal') {
            $refusalCategory = $this->responseObject->stop_details->category ?? null;
            return 'the model refused the request' . (is_string($refusalCategory) && $refusalCategory !== '' ? ' (' . $refusalCategory . ')' : '');
        }
        foreach ($this->responseObject->content as $contentBlock) {
            $blockType = is_object($contentBlock) ? ($contentBlock->type ?? null) : null;
            if ($blockType === 'tool_use' || ($blockType === 'text' && (string)($contentBlock->text ?? '') !== '')) {
                return null;
            }
        }
        if ($stopReason === 'max_tokens') {
            return 'the answer was cut off at the output limit before any text or call';
        }
        return null;
    }
}
