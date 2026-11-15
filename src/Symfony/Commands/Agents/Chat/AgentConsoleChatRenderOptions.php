<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\Chat;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * What the console chat prints around a turn: how the printer renders the messages (untruncated payloads, full tool
 * results, collapsed delegations), whether the owner's message is echoed, and the extra blocks after a turn (timings,
 * the loaded capabilities, the context composition, the memory curation, the HTML transcript).
 *
 * A plain object of one chat run; no container service.
 *
 * @phpstan-consistent-constructor
 */
#[Exclude]
class AgentConsoleChatRenderOptions
{
    /**
     * @param bool $debug message payloads (tool calls and their arguments) untruncated
     * @param bool $full tool results untruncated
     * @param bool $collapseThreads a delegation shows its call and verdict, not the steps of the child conversation
     * @param bool $timings one wall-clock line per operation and per turn
     * @param bool $showCapabilities the eviction snapshot of the loaded capabilities and the load delta after a turn
     * @param bool $showContextComposition the segment breakdown of the model call that produced the reply after a turn
     * @param bool $showMemoryCuration what the memory curation after a turn decided, and its failure
     * @param string|null $htmlPath the file the HTML transcript is rewritten to after every turn; null = none
     * @param bool $echoUserMessage the printer prints the owner's message (false when the terminal echoed it already)
     */
    public function __construct(
        public readonly bool $debug = false,
        public readonly bool $full = false,
        public readonly bool $collapseThreads = false,
        public readonly bool $timings = false,
        public readonly bool $showCapabilities = false,
        public readonly bool $showContextComposition = false,
        public readonly bool $showMemoryCuration = false,
        public readonly ?string $htmlPath = null,
        public readonly bool $echoUserMessage = true,
    ) {
    }

    /**
     * A copy that echoes the owner's message or not.
     *
     * @param bool $echoUserMessage
     * @return static
     */
    public function withEchoOfUserMessage(bool $echoUserMessage): static
    {
        return new static(
            $this->debug,
            $this->full,
            $this->collapseThreads,
            $this->timings,
            $this->showCapabilities,
            $this->showContextComposition,
            $this->showMemoryCuration,
            $this->htmlPath,
            $echoUserMessage
        );
    }

    /**
     * A copy for the turns of a file or a recording: payloads and tool results truncated, delegations expanded, no HTML
     * transcript, the owner's message echoed; the blocks after a turn stay as passed.
     *
     * @return static
     */
    public function forSeveralTurns(): static
    {
        return new static(
            false,
            false,
            false,
            $this->timings,
            $this->showCapabilities,
            $this->showContextComposition,
            $this->showMemoryCuration,
            null,
            true
        );
    }
}
