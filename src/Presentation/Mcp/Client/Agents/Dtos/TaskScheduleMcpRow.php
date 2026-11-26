<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentClock;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleStatus;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;

/**
 * One task schedule as `getTaskSchedules` shows it to the agent: the schedule's own facts and its newest run. Built
 * with {@see fromSchedule()}; the newest run is filled by the tool in one batched query.
 */
class TaskScheduleMcpRow extends ValueObject
{
    /** @var int The schedule's id. */
    public int $id = 0;

    /** @var int|null The space the schedule works for; null = the account as a whole. */
    public ?int $agentSpaceId = null;

    /** @var string|null The 3-6 word label of the task list; null = none was given. */
    public ?string $title = null;

    /** @var string The task every firing runs. */
    public string $task = '';

    /** @var string|null When the schedule counts as done; null = it runs until expiry or cancel. */
    public ?string $doneCriteria = null;

    /** @var string The cron expression of a RECURRING schedule (e.g. "0 7 * * 1"); empty on a one-time task. */
    public string $schedule = '';

    /** @var DateTime|null The single fire moment of a ONE-TIME task; null = recurring. */
    public ?DateTime $oneTimeAt = null;

    /** @var string The IANA zone the cron digits mean (UTC for a schedule without a zone). */
    public string $effectiveTimezone = 'UTC';

    /** @var string ACTIVE | PAUSED | EXPIRED | DONE | CANCELLED | ARCHIVED. */
    public string $status = '';

    /** @var string|null Set when the platform paused or cancelled the schedule for the state of the space or the account; null = a normal status. */
    public ?string $stateReason = null;

    /** @var DateTime|null The next firing. */
    public ?DateTime $nextRunAt = null;

    /** @var DateTime|null The last firing. */
    public ?DateTime $lastFiredAt = null;

    /** @var int Firings claimed so far (the schedule's quota use, not the number of executions). */
    public int $iterationsUsed = 0;

    /** @var int|null Maximum firings before the schedule expires; null = unlimited. */
    public ?int $maxIterations = null;

    /** @var string Where the run report is delivered: EMAIL or CHAT. */
    public string $reportChannel = '';

    /** @var string|null The purpose the runs start with when it is not the default `Schedule.Run`; null = the default. */
    public ?string $runPurposeName = null;

    /** @var int How many consecutive runs failed (0 = healthy). */
    public int $failureStreak = 0;

    /** @var DateTime|null When a run last failed; null = never. */
    public ?DateTime $lastFailedAt = null;

    /** @var int|null The conversation the schedule was created in. */
    public ?int $createdByConversationId = null;

    /** @var int|null The newest run's conversation; null = never ran. */
    public ?int $lastRunConversationId = null;

    /** @var string|null The newest run's run state; null = never ran. */
    public ?string $lastRunState = null;

    /** @var string|null The newest run's outcome, shortened. */
    public ?string $lastRunOutcome = null;

    /**
     * The row of `$schedule`, without its newest run.
     *
     * @param AIConversationSchedule $schedule
     * @return static
     */
    public static function fromSchedule(AIConversationSchedule $schedule): static
    {
        $scheduleRow = new static();
        $scheduleRow->id = (int)$schedule->id;
        $scheduleRow->agentSpaceId = $schedule->agentSpaceId ?? null;
        $scheduleRow->title = $schedule->title ?? null;
        $scheduleRow->task = (string)($schedule->task ?? '');
        $scheduleRow->doneCriteria = $schedule->doneCriteria ?? null;
        $scheduleRow->schedule = (string)($schedule->schedule ?? '');
        $scheduleRow->oneTimeAt = $schedule->oneTimeAt ?? null;
        $scheduleRow->effectiveTimezone = $schedule->getCronTimezoneIdentifier();
        $scheduleRow->status = $schedule->status;
        $scheduleRow->stateReason = $schedule->stateReason ?? null;
        $scheduleRow->nextRunAt = $schedule->nextRunAt ?? null;
        $scheduleRow->lastFiredAt = $schedule->lastFiredAt ?? null;
        $scheduleRow->iterationsUsed = (int)$schedule->iterationsUsed;
        $scheduleRow->maxIterations = $schedule->maxIterations ?? null;
        $scheduleRow->reportChannel = (string)$schedule->reportChannel;
        $runPurposeName = $schedule->getRunPurposeName();
        $scheduleRow->runPurposeName = $runPurposeName !== AIConversationPurpose::SCHEDULE_RUN ? $runPurposeName : null;
        $scheduleRow->failureStreak = (int)$schedule->failureStreak;
        $scheduleRow->lastFailedAt = $schedule->lastFailedAt ?? null;
        $scheduleRow->createdByConversationId = $schedule->createdByConversationId ?? null;
        return $scheduleRow;
    }

    /**
     * The schedule as a compact Markdown block: id, kind, status, title, the timing (the one-time moment, or the cron
     * with the zone its digits mean), next and last firing, firings claimed, report channel, failure streak, done
     * criteria, the purpose of the runs when it is not the default, the creating conversation, the newest run (the `getTaskRunReport` handle) and the task text — in full
     * only while the schedule can still act (an update needs it verbatim).
     *
     * @return string
     */
    public function toLlmMarkdown(): string
    {
        $kind = $this->oneTimeAt !== null ? 'one-time' : 'recurring';
        $status = $this->status . ($this->stateReason !== null ? " ({$this->stateReason})" : '');
        $headline = trim((string)($this->title ?? ''));
        $lines = ["### #{$this->id} · {$kind} · {$status}" . ($headline !== '' ? " · {$headline}" : '')];
        $facts = [];
        if ($this->oneTimeAt !== null) {
            $facts[] = 'fires at ' . AgentClock::formatMoment($this->oneTimeAt);
        } elseif ($this->schedule !== '') {
            $facts[] = "cron `{$this->schedule}` (digits in {$this->effectiveTimezone})";
        }
        // a paused or cancelled row keeps its nextRunAt: only an ACTIVE schedule has a next firing
        if ($this->status === AIConversationScheduleStatus::ACTIVE && $this->nextRunAt !== null) {
            $facts[] = 'next firing ' . AgentClock::formatMoment($this->nextRunAt);
        } elseif ($this->status === AIConversationScheduleStatus::PAUSED) {
            $facts[] = 'PAUSED — does not fire until resumeTaskSchedule';
        }
        if ($this->lastFiredAt !== null) {
            $facts[] = 'last fired ' . AgentClock::formatMoment($this->lastFiredAt);
        }
        $facts[] = 'firings claimed ' . $this->iterationsUsed . ($this->maxIterations !== null ? "/{$this->maxIterations}" : '');
        $facts[] = 'report via ' . $this->reportChannel;
        if ($this->runPurposeName !== null) {
            $facts[] = 'runs as ' . $this->runPurposeName;
        }
        if ($this->failureStreak > 0) {
            $facts[] = "FAILED {$this->failureStreak}× in a row"
                . ($this->lastFailedAt !== null ? ' (last ' . AgentClock::formatMoment($this->lastFailedAt) . ')' : '');
        }
        if ($this->doneCriteria !== null && trim($this->doneCriteria) !== '') {
            $facts[] = 'done when: ' . trim($this->doneCriteria);
        }
        if ($this->createdByConversationId !== null) {
            $facts[] = "created in chat #{$this->createdByConversationId}";
        }
        $lines[] = '- ' . implode(' · ', $facts);
        if ($this->lastRunConversationId !== null) {
            $lines[] = "- newest run: conversation #{$this->lastRunConversationId} · " . ($this->lastRunState ?? '?')
                . ($this->lastRunOutcome !== null && trim($this->lastRunOutcome) !== '' ? ' · ' . trim($this->lastRunOutcome) : ' · no outcome recorded')
                . ' (full report: getTaskRunReport)';
        } else {
            $lines[] = '- never ran';
        }
        $taskOnOneLine = str_replace(["\r\n", "\n"], ' ', trim($this->task));
        if (in_array($this->status, [AIConversationScheduleStatus::ACTIVE, AIConversationScheduleStatus::PAUSED], true)) {
            $lines[] = '- task: ' . $taskOnOneLine;
        } elseif ($headline === '') {
            $lines[] = '- task: ' . (mb_strlen($taskOnOneLine) > AIConversationSchedule::OWNER_FACING_LABEL_MAX_CHARS
                ? mb_substr($taskOnOneLine, 0, AIConversationSchedule::OWNER_FACING_LABEL_MAX_CHARS - 3) . '…'
                : $taskOnOneLine);
        }
        return implode("\n", $lines);
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)$this->id);
    }
}
