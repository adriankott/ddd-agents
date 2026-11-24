<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Conversations\Wire;

use stdClass;

/**
 * Composes the body of an agent model call on the Anthropic Messages API (`POST /messages`) from the API-neutral
 * {@see AgentWireRequestParameters}. The egress composes the conversation once in the Chat-Completions form; this
 * class converts that list:
 *
 * - the leading `system` entry → the top-level `system` text blocks (a `cache_control` marker stays on its block);
 * - a `user` entry → a `user` message of `text` / `image` blocks;
 * - an `assistant` entry → an `assistant` message: the content blocks the provider returned for that step, verbatim
 *   ({@see KEY_ASSISTANT_CONTENT_JSON}: thinking blocks with their signature, text, `tool_use`), or — for a step
 *   without a stored answer — a `text` block and one `tool_use` block per call;
 * - `tool` entries → `tool_result` blocks; the results of one step share ONE `user` message;
 * - a `developer` entry (the instructions a skill load appends) and a later `system` entry → a `role: "system"`
 *   message at that place; an entry marked {@see KEY_CLEAR_AT} carries `clear_at` (a note of one call that stays in the
 *   history and is hidden from the model from the next user message on);
 * - an `additional_tools` entry (the tools a skill load appends) → `tool_addition` blocks in a `role: "system"`
 *   message; the tools themselves are declared top-level with `defer_loading: true`, which does not change the tool
 *   set a thinking block is bound to.
 *
 * Neighbouring entries of the same role become one message. The request always asks the provider to DROP a thinking
 * block whose conversation prefix or model changed ({@see THINKING}) instead of refusing the request; the answer names
 * every dropped block ({@see AgentMessagesResponse::getInputTransformations()}). No temperature is sent (the current
 * Claude models refuse a non-default sampling parameter). Pure: no repository, no configuration.
 */
class AgentMessagesRequestComposer
{
    /** The API version header value of the Messages API. */
    public const string ANTHROPIC_VERSION = '2023-06-01';

    /** The beta of the thinking binding controls: lets the request set the mismatch behaviour and adds `input_transformations` to the answer. */
    public const string BETA_THINKING_BINDING_CONTROLS = 'thinking-binding-controls-2026-08-01';

    /** The beta of `tool_addition` / `tool_removal` blocks in a `role: "system"` message. */
    public const string BETA_MID_CONVERSATION_TOOL_CHANGES = 'mid-conversation-tool-changes-2026-07-01';

    /** The beta of `clear_at` on a `role: "system"` message. */
    public const string BETA_SYSTEM_MESSAGE_CLEAR_AT = 'mid-conversation-system-clear-at-2026-08-21';

    /** @var string[] The betas every request of this wire names. */
    public const array BETAS = [self::BETA_THINKING_BINDING_CONTROLS, self::BETA_MID_CONVERSATION_TOOL_CHANGES, self::BETA_SYSTEM_MESSAGE_CLEAR_AT];

    /** Adaptive thinking with the drop behaviour: a block bound to another prefix or model is dropped and reported. */
    public const array THINKING = ['type' => 'adaptive', 'block_binding' => ['prefix_mismatch_behavior' => 'drop_block']];

    /** The key of an assistant entry that carries the step's content blocks as the provider returned them (a JSON list). */
    public const string KEY_ASSISTANT_CONTENT_JSON = 'messages_content_json';

    /** The key of an assistant entry whose stored content goes back WITHOUT its thinking blocks (true). */
    public const string KEY_ASSISTANT_CONTENT_WITHOUT_THINKING = 'messages_content_without_thinking';

    /** The key of an entry that goes out as a `role: "system"` message whatever its Chat role is (true). */
    public const string KEY_AS_SYSTEM_MESSAGE = 'messages_as_system_message';

    /** The key of the `clear_at` value of an entry that goes out as a `role: "system"` message. */
    public const string KEY_CLEAR_AT = 'messages_clear_at';

    /** `clear_at`: the message is hidden from the model from the next user message on; it stays in the history. */
    public const string CLEAR_AT_NEXT_USER_MESSAGE = 'next_user_message';

    /** The thinking block types a stored answer carries. */
    public const array THINKING_BLOCK_TYPES = ['thinking', 'redacted_thinking'];

    /**
     * @return array<string, mixed>
     */
    public static function composeBody(AgentWireRequestParameters $agentWireRequestParameters): array
    {
        $agentMessagesConversation = self::convertChatCompletionsMessages($agentWireRequestParameters->chatCompletionsMessages);
        $body = [
            'model' => $agentWireRequestParameters->modelId,
            'max_tokens' => $agentWireRequestParameters->maxOutputTokens ?? 16000,
            'thinking' => self::THINKING,
        ];
        if ($agentWireRequestParameters->reasoningEffort !== null) {
            $body['output_config'] = ['effort' => $agentWireRequestParameters->reasoningEffort];
        }
        if ($agentMessagesConversation->systemBlocks !== []) {
            $body['system'] = $agentMessagesConversation->systemBlocks;
        }
        $tools = [
            ...self::convertToolSchemas($agentWireRequestParameters->chatCompletionsTools),
            ...self::convertToolSchemas(self::withoutToolsNamed($agentMessagesConversation->appendedChatCompletionsTools, $agentWireRequestParameters->chatCompletionsTools), true),
        ];
        if ($tools !== []) {
            $body['tools'] = $tools;
            $toolChoice = self::convertToolChoice($agentWireRequestParameters->chatCompletionsToolChoice);
            if ($toolChoice !== null) {
                if (!$agentWireRequestParameters->sendsParallelToolCalls && ($toolChoice['type'] ?? null) !== 'none') {
                    $toolChoice['disable_parallel_tool_use'] = true;
                }
                $body['tool_choice'] = $toolChoice;
            }
        }
        $body['messages'] = $agentMessagesConversation->messages;
        if ($agentWireRequestParameters->providerPreference !== null) {
            $body['provider'] = $agentWireRequestParameters->providerPreference;
        }
        return $body;
    }

    /** @var string[] The keys the egress adds to a Chat-form entry for this composer only. */
    public const array ENTRY_KEYS_OF_THIS_WIRE = [self::KEY_ASSISTANT_CONTENT_JSON, self::KEY_ASSISTANT_CONTENT_WITHOUT_THINKING, self::KEY_AS_SYSTEM_MESSAGE, self::KEY_CLEAR_AT];

    /**
     * The Chat-form list without the keys of this wire — the form the views of a reconstructed call read.
     *
     * @param array<int, array<string, mixed>> $chatCompletionsMessages
     * @return array<int, array<string, mixed>>
     */
    public static function withoutEntryKeysOfThisWire(array $chatCompletionsMessages): array
    {
        return array_map(
            static fn (array $chatCompletionsMessage): array => array_diff_key($chatCompletionsMessage, array_flip(self::ENTRY_KEYS_OF_THIS_WIRE)),
            array_values($chatCompletionsMessages)
        );
    }

    /**
     * The request headers of this wire next to the authentication: the API version and the betas.
     *
     * @return array<string, string>
     */
    public static function buildHeaders(): array
    {
        return ['anthropic-version' => self::ANTHROPIC_VERSION, 'anthropic-beta' => implode(',', self::BETAS)];
    }

    /**
     * The Chat-form message list as the Messages conversation: the system blocks, the messages, and the tools the
     * list's `additional_tools` entries append.
     *
     * @param array<int, array<string, mixed>> $chatCompletionsMessages
     */
    public static function convertChatCompletionsMessages(array $chatCompletionsMessages): AgentMessagesConversation
    {
        $agentMessagesConversation = new AgentMessagesConversation();
        foreach (array_values($chatCompletionsMessages) as $position => $chatCompletionsMessage) {
            $role = (string)($chatCompletionsMessage['role'] ?? 'user');
            if (($chatCompletionsMessage['type'] ?? null) === 'additional_tools') {
                $toolAdditionBlocks = [];
                foreach ((array)($chatCompletionsMessage['tools'] ?? []) as $appendedTool) {
                    $appendedToolName = is_array($appendedTool) ? (string)($appendedTool['function']['name'] ?? $appendedTool['name'] ?? '') : '';
                    if ($appendedToolName === '') {
                        continue;
                    }
                    $agentMessagesConversation->appendedChatCompletionsTools[] = $appendedTool;
                    $toolAdditionBlocks[] = ['type' => 'tool_addition', 'tool' => ['type' => 'tool_reference', 'name' => $appendedToolName]];
                }
                $agentMessagesConversation->addMessage('system', $toolAdditionBlocks);
                continue;
            }
            if ($position === 0 && $role === 'system') {
                $agentMessagesConversation->systemBlocks = self::convertTextContent($chatCompletionsMessage['content'] ?? '');
                continue;
            }
            if ($role === 'system' || $role === 'developer' || ($chatCompletionsMessage[self::KEY_AS_SYSTEM_MESSAGE] ?? false) === true) {
                $clearAt = isset($chatCompletionsMessage[self::KEY_CLEAR_AT]) ? (string)$chatCompletionsMessage[self::KEY_CLEAR_AT] : null;
                $agentMessagesConversation->addMessage('system', self::convertTextContent($chatCompletionsMessage['content'] ?? ''), $clearAt);
                continue;
            }
            if ($role === 'assistant') {
                $agentMessagesConversation->addMessage('assistant', self::convertAssistantMessage($chatCompletionsMessage));
                continue;
            }
            if ($role === 'tool') {
                $agentMessagesConversation->addMessage('user', [self::convertToolResult($chatCompletionsMessage)]);
                continue;
            }
            $agentMessagesConversation->addMessage('user', self::convertUserContent($chatCompletionsMessage['content'] ?? ''));
        }
        return $agentMessagesConversation;
    }

    /**
     * The content blocks of an assistant entry: the stored answer of its step verbatim (without its thinking blocks
     * when the entry says so), else a `text` block for a non-empty text and a `tool_use` block per call.
     *
     * @param array<string, mixed> $assistantMessage
     * @return array<int, mixed>
     */
    public static function convertAssistantMessage(array $assistantMessage): array
    {
        $storedContentBlocks = self::decodeStoredContentBlocks($assistantMessage[self::KEY_ASSISTANT_CONTENT_JSON] ?? null);
        if ($storedContentBlocks !== null) {
            if (($assistantMessage[self::KEY_ASSISTANT_CONTENT_WITHOUT_THINKING] ?? false) === true) {
                $storedContentBlocks = self::withoutThinkingBlocks($storedContentBlocks);
            }
            // a cache marker on the entry's text moves to the newest stored block that can carry one
            $cacheControl = self::findCacheControlOfContent($assistantMessage['content'] ?? null);
            if ($cacheControl !== null) {
                for ($blockPosition = count($storedContentBlocks) - 1; $blockPosition >= 0; $blockPosition--) {
                    if (!in_array($storedContentBlocks[$blockPosition]->type ?? null, self::THINKING_BLOCK_TYPES, true)) {
                        $markedBlock = clone $storedContentBlocks[$blockPosition];
                        $markedBlock->cache_control = $cacheControl;
                        $storedContentBlocks[$blockPosition] = $markedBlock;
                        break;
                    }
                }
            }
            return $storedContentBlocks;
        }
        $contentBlocks = self::convertTextContent($assistantMessage['content'] ?? '');
        foreach ((array)($assistantMessage['tool_calls'] ?? []) as $chatToolCall) {
            if (!is_array($chatToolCall)) {
                continue;
            }
            $toolInput = json_decode((string)($chatToolCall['function']['arguments'] ?? ''));
            $contentBlocks[] = [
                'type' => 'tool_use',
                'id' => (string)($chatToolCall['id'] ?? ''),
                'name' => (string)($chatToolCall['function']['name'] ?? ''),
                'input' => $toolInput instanceof stdClass ? $toolInput : new stdClass(),
            ];
        }
        return $contentBlocks;
    }

    /**
     * The cache marker a Chat content carries on one of its parts; null for a string content or parts without one.
     *
     * @return array<string, mixed>|null
     */
    public static function findCacheControlOfContent(mixed $content): ?array
    {
        foreach (is_array($content) ? $content : [] as $contentPart) {
            if (is_array($contentPart) && is_array($contentPart['cache_control'] ?? null)) {
                return $contentPart['cache_control'];
            }
        }
        return null;
    }

    /**
     * The content blocks a stored answer holds, decoded with objects kept as objects (an empty tool input stays `{}`);
     * null when the value is no JSON list of blocks or holds no block that can go back.
     *
     * @return array<int, stdClass>|null
     */
    public static function decodeStoredContentBlocks(mixed $storedContentJson): ?array
    {
        if (!is_string($storedContentJson) || $storedContentJson === '') {
            return null;
        }
        $decodedContent = json_decode($storedContentJson);
        if (!is_array($decodedContent)) {
            return null;
        }
        $contentBlocks = [];
        foreach ($decodedContent as $contentBlock) {
            if (!$contentBlock instanceof stdClass || !is_string($contentBlock->type ?? null)) {
                continue;
            }
            // an empty text block is refused by the API
            if ($contentBlock->type === 'text' && (string)($contentBlock->text ?? '') === '') {
                continue;
            }
            $contentBlocks[] = $contentBlock;
        }
        return $contentBlocks !== [] ? $contentBlocks : null;
    }

    /**
     * The ids of the `tool_use` blocks a stored answer holds, in order; empty for a value that is no block list.
     *
     * @return string[]
     */
    public static function readToolUseIdsOfStoredContent(mixed $storedContentJson): array
    {
        $toolUseIds = [];
        foreach (self::decodeStoredContentBlocks($storedContentJson) ?? [] as $contentBlock) {
            if (($contentBlock->type ?? null) === 'tool_use') {
                $toolUseIds[] = (string)($contentBlock->id ?? '');
            }
        }
        return $toolUseIds;
    }

    /**
     * @param array<int, stdClass> $contentBlocks
     * @return array<int, stdClass>
     */
    public static function withoutThinkingBlocks(array $contentBlocks): array
    {
        return array_values(array_filter(
            $contentBlocks,
            static fn (stdClass $contentBlock): bool => !in_array($contentBlock->type ?? null, self::THINKING_BLOCK_TYPES, true)
        ));
    }

    /**
     * A tool entry as a `tool_result` block: a text result as a string, a result with a picture as `text` / `image`
     * blocks. A cache marker of a part moves to the block (a nested block carries none).
     *
     * @param array<string, mixed> $toolMessage
     * @return array<string, mixed>
     */
    public static function convertToolResult(array $toolMessage): array
    {
        $toolResultBlock = ['type' => 'tool_result', 'tool_use_id' => (string)($toolMessage['tool_call_id'] ?? '')];
        $content = $toolMessage['content'] ?? '';
        if (is_string($content)) {
            if ($content !== '') {
                $toolResultBlock['content'] = $content;
            }
            return $toolResultBlock;
        }
        $resultBlocks = [];
        foreach (self::convertUserContent($content) as $resultBlock) {
            if (isset($resultBlock['cache_control'])) {
                $toolResultBlock['cache_control'] = $resultBlock['cache_control'];
                unset($resultBlock['cache_control']);
            }
            $resultBlocks[] = $resultBlock;
        }
        if ($resultBlocks !== []) {
            $toolResultBlock['content'] = $resultBlocks;
        }
        return $toolResultBlock;
    }

    /**
     * A user content (a string or Chat parts) as `text` / `image` blocks; an empty text yields no block.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function convertUserContent(mixed $content): array
    {
        if (is_string($content)) {
            return $content !== '' ? [['type' => 'text', 'text' => $content]] : [];
        }
        $contentBlocks = [];
        foreach (is_array($content) ? $content : [] as $contentPart) {
            if (!is_array($contentPart)) {
                continue;
            }
            $partType = $contentPart['type'] ?? null;
            if ($partType === 'text' || $partType === 'input_text') {
                if ((string)($contentPart['text'] ?? '') === '') {
                    continue;
                }
                $contentBlock = ['type' => 'text', 'text' => (string)$contentPart['text']];
                if (isset($contentPart['cache_control'])) {
                    $contentBlock['cache_control'] = $contentPart['cache_control'];
                }
                $contentBlocks[] = $contentBlock;
                continue;
            }
            if ($partType === 'image_url' && isset($contentPart['image_url']['url'])) {
                $contentBlocks[] = self::convertImageUrl((string)$contentPart['image_url']['url']);
            }
        }
        return $contentBlocks;
    }

    /**
     * A text content (a string or Chat text parts) as `text` blocks; an empty text yields no block.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function convertTextContent(mixed $content): array
    {
        return array_values(array_filter(
            self::convertUserContent($content),
            static fn (array $contentBlock): bool => $contentBlock['type'] === 'text'
        ));
    }

    /**
     * An image URL as an `image` block: a `data:` URL as base64 with its media type, any other URL by reference.
     *
     * @return array<string, mixed>
     */
    public static function convertImageUrl(string $imageUrl): array
    {
        if (preg_match('~^data:([^;,]+);base64,(.*)$~s', $imageUrl, $dataUrlParts) === 1) {
            return ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $dataUrlParts[1], 'data' => $dataUrlParts[2]]];
        }
        return ['type' => 'image', 'source' => ['type' => 'url', 'url' => $imageUrl]];
    }

    /**
     * Chat-form tool schemas (`{type: function, function: {name, description, parameters}}`) as Messages tools
     * (`{name, description, input_schema}`); `$deferLoading` true declares each one with `defer_loading: true`.
     *
     * @param array<int, array<string, mixed>> $chatCompletionsTools
     * @return array<int, array<string, mixed>>
     */
    public static function convertToolSchemas(array $chatCompletionsTools, bool $deferLoading = false): array
    {
        $tools = [];
        foreach ($chatCompletionsTools as $chatCompletionsTool) {
            $function = is_array($chatCompletionsTool['function'] ?? null) ? $chatCompletionsTool['function'] : $chatCompletionsTool;
            $toolName = (string)($function['name'] ?? '');
            if ($toolName === '') {
                continue;
            }
            $tool = ['name' => $toolName];
            if (isset($function['description']) && (string)$function['description'] !== '') {
                $tool['description'] = (string)$function['description'];
            }
            $inputSchema = $function['parameters'] ?? null;
            $tool['input_schema'] = is_array($inputSchema) && $inputSchema !== [] ? self::withObjectProperties($inputSchema) : ['type' => 'object', 'properties' => new stdClass()];
            if ($deferLoading) {
                $tool['defer_loading'] = true;
            }
            $tools[] = $tool;
        }
        return $tools;
    }

    /**
     * An input schema whose empty `properties` list is the JSON object `{}` (PHP encodes an empty array as `[]`, which
     * is no valid schema).
     *
     * @param array<string, mixed> $inputSchema
     * @return array<string, mixed>
     */
    protected static function withObjectProperties(array $inputSchema): array
    {
        if (array_key_exists('properties', $inputSchema) && $inputSchema['properties'] === []) {
            $inputSchema['properties'] = new stdClass();
        }
        return $inputSchema;
    }

    /**
     * The appended tools that are not offered top-level already (a tool offered both ways is declared once, loaded).
     *
     * @param array<int, array<string, mixed>> $appendedChatCompletionsTools
     * @param array<int, array<string, mixed>> $chatCompletionsTools
     * @return array<int, array<string, mixed>>
     */
    protected static function withoutToolsNamed(array $appendedChatCompletionsTools, array $chatCompletionsTools): array
    {
        $declaredToolNames = [];
        foreach ($chatCompletionsTools as $chatCompletionsTool) {
            $declaredToolNames[(string)($chatCompletionsTool['function']['name'] ?? $chatCompletionsTool['name'] ?? '')] = true;
        }
        $remainingTools = [];
        foreach ($appendedChatCompletionsTools as $appendedTool) {
            $appendedToolName = (string)($appendedTool['function']['name'] ?? $appendedTool['name'] ?? '');
            if ($appendedToolName === '' || isset($declaredToolNames[$appendedToolName])) {
                continue;
            }
            $declaredToolNames[$appendedToolName] = true;
            $remainingTools[] = $appendedTool;
        }
        return $remainingTools;
    }

    /**
     * The Chat `tool_choice` as the Messages one: `auto`, `none`, `required` → `any`, a named pin → `tool`. Null for
     * no choice.
     *
     * @param string|array<string, mixed>|null $chatCompletionsToolChoice
     * @return array<string, mixed>|null
     */
    public static function convertToolChoice(string|array|null $chatCompletionsToolChoice): ?array
    {
        if ($chatCompletionsToolChoice === null) {
            return null;
        }
        if (is_array($chatCompletionsToolChoice)) {
            $pinnedToolName = $chatCompletionsToolChoice['function']['name'] ?? $chatCompletionsToolChoice['name'] ?? null;
            return $pinnedToolName !== null ? ['type' => 'tool', 'name' => (string)$pinnedToolName] : ['type' => 'auto'];
        }
        return match ($chatCompletionsToolChoice) {
            'required' => ['type' => 'any'],
            'none' => ['type' => 'none'],
            default => ['type' => 'auto'],
        };
    }
}
