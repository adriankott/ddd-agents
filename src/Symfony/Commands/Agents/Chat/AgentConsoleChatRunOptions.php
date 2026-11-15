<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\Chat;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * How the console chat drives a turn: whether the conversation is stored, the model, the worker path, and the side
 * work (owner-message embedding, memory curation, inline compaction) that runs in the background in production and
 * inline in the console.
 *
 * A plain object of one chat run; no container service.
 *
 * @phpstan-consistent-constructor
 */
#[Exclude]
class AgentConsoleChatRunOptions
{
    /**
     * @param bool $ephemeral nothing is stored; the conversation lives in this process only
     * @param string|null $aiModelName the model of the agent calls; null = the conversation's default
     * @param bool $async the turn is dispatched to the worker path instead of driven inline
     * @param bool $compactionEnabled the conversation is compacted inline after a turn once it crosses its threshold
     * @param bool $sideWorkEnabled the owner-message embedding and the memory curation run inline after a turn
     */
    public function __construct(
        public readonly bool $ephemeral = false,
        public readonly ?string $aiModelName = null,
        public readonly bool $async = false,
        public readonly bool $compactionEnabled = true,
        public readonly bool $sideWorkEnabled = true,
    ) {
    }

    /**
     * A copy that drives on the worker path or inline.
     *
     * @param bool $async
     * @return static
     */
    public function withAsync(bool $async): static
    {
        return new static($this->ephemeral, $this->aiModelName, $async, $this->compactionEnabled, $this->sideWorkEnabled);
    }
}
