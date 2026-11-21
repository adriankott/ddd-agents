<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\AI\Dtos;

use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemories;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemory;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The answer of the memory tools: the memories (one line each for the model, `#<id>` first) and a note. */
class AgentMemoriesMcpResponseDto extends RestResponseDto implements LlmMarkdownRenderable
{
    public const string NOTE_NOTHING_TO_FORGET = 'Nothing to forget — no such memory, or it was forgotten already.';

    /** @var AgentMemories|null The memories */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?AgentMemories $memories = null;

    /** @var string|null What happened, for the model */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $note = null;

    /**
     * The answer of a write: the one memory and the note.
     *
     * @param AgentMemory $agentMemory
     * @param string $note
     * @return static
     */
    public static function forOneMemory(AgentMemory $agentMemory, string $note): static
    {
        $agentMemoriesMcpResponseDto = new static();
        $agentMemoriesMcpResponseDto->memories = AgentMemories::newInstance();
        $agentMemoriesMcpResponseDto->memories->add($agentMemory);
        $agentMemoriesMcpResponseDto->note = $note;
        return $agentMemoriesMcpResponseDto;
    }

    /**
     * The answer of a read.
     *
     * @param AgentMemories $agentMemories
     * @return static
     */
    public static function forMemories(AgentMemories $agentMemories): static
    {
        $agentMemoriesMcpResponseDto = new static();
        $agentMemoriesMcpResponseDto->memories = $agentMemories;
        return $agentMemoriesMcpResponseDto;
    }

    /**
     * The answer of `forget` when there was nothing to forget.
     *
     * @return static
     */
    public static function forNothingToForget(): static
    {
        $agentMemoriesMcpResponseDto = new static();
        $agentMemoriesMcpResponseDto->memories = AgentMemories::newInstance();
        $agentMemoriesMcpResponseDto->note = self::NOTE_NOTHING_TO_FORGET;
        return $agentMemoriesMcpResponseDto;
    }

    public function toLlmMarkdown(): string
    {
        $memoryLines = $this->memories?->toLlmMarkdown() ?? '(no memories)';
        return ($this->note !== null && $this->note !== '') ? $this->note . "\n" . $memoryLines : $memoryLines;
    }
}
