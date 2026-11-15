<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Wire;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\LoadSkillsTool;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;

/**
 * What one model call of an append-only conversation appended to the history: the tools that joined
 * (the `additional_tools` item) and the instructions of the skills that loaded / the situation that changed (a
 * `developer` message). Rendered on the wire at the position the call appended it — right after the newest row the call
 * saw ({@see \DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot::$appendedAfterMessageIndex}) — on this call
 * and on every later call of the epoch, byte for byte. Pure.
 */
class AgentAppendedHistoryInsertion
{
    /** @var string The developer message text; '' when only tools joined. */
    public string $developerText = '';

    /** @var array<int, array<string, mixed>> The Chat-Completions schemas of the tools that joined; [] when none. */
    public array $chatCompletionsToolSchemas = [];

    /**
     * @var string[] The state notes (`[SIGNAL:<kind>] …`) delivered at this position — one user entry after
     *      the developer message; [] when none (then the entries are byte-identical to those without state notes).
     */
    public array $userTexts = [];

    /**
     * @var string[] The skills the platform loaded before the turn's first model call: rendered as a `load_skills` call
     *      and its result in front of the item — the shape of the model's own load, which it does not repeat; [] = none.
     */
    public array $preloadLoadCallSkillNames = [];

    /** The call id of that `load_skills` call: stable per position ({@see buildPreloadLoadCallId()}). */
    public string $preloadLoadCallId = '';

    /** The call id of a preload's `load_skills` call appended after the history row at `$appendedAfterMessageIndex`. */
    public static function buildPreloadLoadCallId(int $appendedAfterMessageIndex): string
    {
        return 'call_preload_' . $appendedAfterMessageIndex;
    }

    public function isEmpty(): bool
    {
        return $this->developerText === '' && $this->chatCompletionsToolSchemas === [] && $this->userTexts === []
            && $this->preloadLoadCallSkillNames === [];
    }

    /**
     * The wire entries in the Chat-form message list: a preload's `load_skills` call and its result (when the item
     * carries a preload), then the `additional_tools` item (it cannot carry a cache breakpoint), then the developer
     * message (the wire marker may land on it), then the state notes as one user entry. The Responses converter turns
     * them into a `function_call` and its `function_call_output`, the `additional_tools` input item, a `developer`
     * message and a `user` message.
     *
     * @return array<int, array<string, mixed>>
     */
    public function toWireEntries(): array
    {
        $wireEntries = [];
        if ($this->preloadLoadCallSkillNames !== [] && $this->preloadLoadCallId !== '') {
            $wireEntries[] = [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => $this->preloadLoadCallId,
                    'type' => 'function',
                    'function' => [
                        'name' => LoadSkillsTool::NAME,
                        'arguments' => (string)json_encode(['skill_names' => array_values($this->preloadLoadCallSkillNames)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ];
            $wireEntries[] = ['role' => 'tool', 'tool_call_id' => $this->preloadLoadCallId, 'content' => AIConversationMessage::LOAD_SKILLS_RESULT_LOADED];
        }
        if ($this->chatCompletionsToolSchemas !== []) {
            $wireEntries[] = ['type' => 'additional_tools', 'role' => 'developer', 'tools' => $this->chatCompletionsToolSchemas];
        }
        if ($this->developerText !== '') {
            $wireEntries[] = ['role' => 'developer', 'content' => $this->developerText];
        }
        if ($this->userTexts !== []) {
            $wireEntries[] = ['role' => 'user', 'content' => implode("\n\n", $this->userTexts)];
        }
        return $wireEntries;
    }
}
