<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Domain\AI\Entities\Conversations\Goals\AgentGoal;
use DDD\Domain\AI\Entities\Conversations\Goals\AgentGoals;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The answer of the goal tools: the goals (one line each for the model, `#<id>` first) and a note. A write that saved
 * nothing although a goal was asked for (the application refused it, or the register is not available) opens its note
 * with {@see ToolCall::RESULT_MARKER_DOWNGRADED_TO_PROPOSAL}: such a call never counts as the evidence of a plan item.
 * The agent's own proposal (PROPOSED) was saved as asked: its note is plain and carries no marker.
 */
class AgentGoalsMcpResponseDto extends RestResponseDto implements LlmMarkdownRenderable
{
    public const string NOTE_SAVED = 'Goal saved.';

    public const string NOTE_ALREADY_SAVED = 'Already in the register; nothing changed.';

    public const string NOTE_STATUS_UPDATED = 'Status updated.';

    public const string NOTE_STORED_AS_PROPOSAL = 'Stored as your PROPOSAL — ask the owner to confirm it, then set it ACTIVE with updateGoalStatus.';

    public const string NOTE_NOTHING_SAVED = 'nothing saved: %s';

    public const string NOTE_REGISTER_NOT_AVAILABLE = 'The goal register is not available in this installation yet: nothing was read or saved. Carry on without it; do not retry.';

    /** @var AgentGoals|null The goals */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?AgentGoals $goals = null;

    /** @var string|null What happened, for the model */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $note = null;

    /**
     * The answer of a read.
     *
     * @param AgentGoals $agentGoals
     * @return static
     */
    public static function forGoals(AgentGoals $agentGoals): static
    {
        $agentGoalsMcpResponseDto = new static();
        $agentGoalsMcpResponseDto->goals = $agentGoals;
        return $agentGoalsMcpResponseDto;
    }

    /**
     * The answer of a write: the one goal and the note.
     *
     * @param AgentGoal $agentGoal
     * @param string $note
     * @return static
     */
    public static function forOneGoal(AgentGoal $agentGoal, string $note): static
    {
        $agentGoalsMcpResponseDto = new static();
        $agentGoalsMcpResponseDto->goals = AgentGoals::newInstance();
        $agentGoalsMcpResponseDto->goals->add($agentGoal);
        $agentGoalsMcpResponseDto->note = $note;
        return $agentGoalsMcpResponseDto;
    }

    /**
     * The answer of `addGoal` after the write path: a PROPOSED goal is the agent's proposal (a plain note: ask the owner
     * to confirm it; no marker, the proposal was saved as asked), a goal an exact restatement found was already
     * there, anything else was saved.
     *
     * @param AgentGoal $persistedGoal
     * @param bool $wasAlreadySaved
     * @return static
     */
    public static function forPersistedGoal(AgentGoal $persistedGoal, bool $wasAlreadySaved): static
    {
        if ($persistedGoal->status === AgentGoal::STATUS_PROPOSED) {
            return static::forOneGoal($persistedGoal, self::NOTE_STORED_AS_PROPOSAL);
        }
        return static::forOneGoal($persistedGoal, $wasAlreadySaved ? self::NOTE_ALREADY_SAVED : self::NOTE_SAVED);
    }

    /**
     * The completed answer of `addGoal` when the application refused the goal before the write: nothing saved, the
     * marker opens the note, the application's reason follows.
     *
     * @param string $refusalReason
     * @return static
     */
    public static function forRefusedGoal(string $refusalReason): static
    {
        $agentGoalsMcpResponseDto = new static();
        $agentGoalsMcpResponseDto->goals = AgentGoals::newInstance();
        $agentGoalsMcpResponseDto->note = ToolCall::RESULT_MARKER_DOWNGRADED_TO_PROPOSAL . ' — ' . sprintf(self::NOTE_NOTHING_SAVED, $refusalReason);
        return $agentGoalsMcpResponseDto;
    }

    /**
     * The completed answer while the goal store cannot read or write (its table does not exist yet). A write's note
     * opens with the marker: nothing was saved.
     *
     * @param bool $isWrite
     * @return static
     */
    public static function forRegisterNotAvailable(bool $isWrite): static
    {
        $agentGoalsMcpResponseDto = new static();
        $agentGoalsMcpResponseDto->goals = AgentGoals::newInstance();
        $agentGoalsMcpResponseDto->note = $isWrite
            ? ToolCall::RESULT_MARKER_DOWNGRADED_TO_PROPOSAL . ' — ' . self::NOTE_REGISTER_NOT_AVAILABLE
            : self::NOTE_REGISTER_NOT_AVAILABLE;
        return $agentGoalsMcpResponseDto;
    }

    public function toLlmMarkdown(): string
    {
        $goalLines = $this->goals?->toLlmMarkdown() ?? AgentGoals::NO_GOALS_LINE;
        return ($this->note !== null && $this->note !== '') ? $this->note . "\n" . $goalLines : $goalLines;
    }
}
