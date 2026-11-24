<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Conversations\Wire;

/**
 * A conversation in the form of the Anthropic Messages API, built by
 * {@see AgentMessagesRequestComposer::convertChatCompletionsMessages()}: the top-level system blocks, the messages and
 * the tools the conversation appends by reference.
 */
class AgentMessagesConversation
{
    /** @var array<int, array<string, mixed>> The top-level `system` text blocks. */
    public array $systemBlocks = [];

    /** @var array<int, array<string, mixed>> The `messages` list: `user`, `assistant` and `system` messages of content blocks. */
    public array $messages = [];

    /** @var array<int, array<string, mixed>> The Chat-form schemas of the tools the conversation adds by a `tool_addition` block. */
    public array $appendedChatCompletionsTools = [];

    /**
     * @var array<int, int> The row index of the step marker per position in {@see $messages}, for every assistant
     *      message that carries the stored answer of its step — where a `messages.<position>…` path of the answer's
     *      `input_transformations` points.
     */
    public array $stepMarkerMessageIndexesByMessagePosition = [];

    /**
     * Adds a message of `$contentBlocks`; nothing for an empty block list (the API refuses an empty message). A message
     * of the same role as the newest one extends it — a step's tool results share one `user` message, a tool addition
     * and the instructions after it one `system` message — except across a different `clear_at`: a note of one call
     * stays a message of its own.
     *
     * @param array<int, mixed> $contentBlocks
     */
    public function addMessage(string $role, array $contentBlocks, ?string $clearAt = null): void
    {
        if ($contentBlocks === []) {
            return;
        }
        $newestPosition = array_key_last($this->messages);
        if ($newestPosition !== null
            && $this->messages[$newestPosition]['role'] === $role
            && ($this->messages[$newestPosition]['clear_at'] ?? null) === $clearAt
            && $role !== 'assistant'
        ) {
            $this->messages[$newestPosition]['content'] = [...$this->messages[$newestPosition]['content'], ...$contentBlocks];
            return;
        }
        $message = ['role' => $role];
        if ($clearAt !== null) {
            $message['clear_at'] = $clearAt;
        }
        $message['content'] = $contentBlocks;
        $this->messages[] = $message;
    }
}
