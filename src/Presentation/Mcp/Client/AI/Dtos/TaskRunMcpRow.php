<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\AI\Dtos;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentClock;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/** One run of a task schedule as `getTaskRuns` shows it: a run is a conversation of its own. */
class TaskRunMcpRow extends ValueObject
{
    /** @var int The run conversation's id — the `getTaskRunReport` handle. */
    public int $conversationId = 0;

    /** @var int|null The schedule this run belongs to. */
    public ?int $scheduleId = null;

    /** @var string|null The schedule's title (or its task, shortened); null when the schedule no longer exists. */
    public ?string $scheduleLabel = null;

    /** @var DateTime|null When the run started. */
    public ?DateTime $startedAt = null;

    /** @var string The run state (COMPLETED, FAILED, IDLE, RUNNING, …). */
    public string $runState = '';

    /** @var bool Whether the run is still in progress. */
    public bool $isInProgress = false;

    /** @var string|null The run's summary title. */
    public ?string $title = null;

    /** @var string|null The run's summary outcome ("what came of it"). */
    public ?string $outcome = null;

    /**
     * The row of `$runConversation`. Its summary is read only when it is loaded: an unset summary would start its
     * generation, a model call a list read never spends.
     *
     * @param AIConversation $runConversation
     * @param string|null $scheduleLabel
     * @return static
     */
    public static function fromRunConversation(AIConversation $runConversation, ?string $scheduleLabel): static
    {
        $runRow = new static();
        $runRow->conversationId = (int)$runConversation->id;
        $runRow->scheduleId = isset($runConversation->scheduleId) ? (int)$runConversation->scheduleId : null;
        $runRow->scheduleLabel = $scheduleLabel;
        $runRow->startedAt = $runConversation->getCreatedTime();
        $runRow->runState = $runConversation->runState;
        $runRow->isInProgress = $runConversation->isRunInProgress();
        if (isset($runConversation->summary)) {
            $runRow->title = $runConversation->summary->title ?? null;
            $runRow->outcome = $runConversation->summary->outcome ?? null;
        }
        return $runRow;
    }

    /**
     * The run as one Markdown line: conversation id, schedule, start, state, title, and the outcome below it.
     *
     * @return string
     */
    public function toLlmMarkdown(): string
    {
        $parts = ["conversation #{$this->conversationId}"];
        if ($this->scheduleId !== null) {
            $parts[] = "schedule #{$this->scheduleId}"
                . ($this->scheduleLabel !== null && trim($this->scheduleLabel) !== '' ? ' "' . trim($this->scheduleLabel) . '"' : '');
        }
        if ($this->startedAt !== null) {
            $parts[] = 'started ' . AgentClock::formatMoment($this->startedAt);
        }
        $parts[] = $this->runState . ($this->isInProgress ? ' (still in progress)' : '');
        if ($this->title !== null && trim($this->title) !== '') {
            $parts[] = trim($this->title);
        }
        $line = '- ' . implode(' · ', $parts);
        if ($this->outcome !== null && trim($this->outcome) !== '') {
            $line .= "\n  outcome: " . trim($this->outcome);
        }
        return $line;
    }

    /**
     * The calendar day the run started on, on the clock of the rendering; null without a start moment.
     *
     * @return string|null 'Y-m-d'
     */
    public function getStartDay(): ?string
    {
        return $this->startedAt !== null ? AgentClock::formatDay($this->startedAt) : null;
    }

    /**
     * Whether the run is over without the completed or the failed mark: neither COMPLETED nor FAILED, and not in progress.
     *
     * @return bool
     */
    public function endedWithoutCompletedOrFailedMark(): bool
    {
        if ($this->isInProgress) {
            return false;
        }
        return !in_array($this->runState, [AIConversationRunState::COMPLETED, AIConversationRunState::FAILED], true)
            && !AIConversationRunState::of($this->runState)->isNonTerminal();
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)$this->conversationId);
    }
}
