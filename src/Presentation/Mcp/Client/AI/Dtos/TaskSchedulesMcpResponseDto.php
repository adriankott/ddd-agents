<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\AI\Dtos;

use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The task schedules `getTaskSchedules` read: one page, how many match, and the budget note. Rendered as Markdown for the model. */
class TaskSchedulesMcpResponseDto extends RestResponseDto implements LlmMarkdownRenderable
{
    /** @var TaskScheduleMcpRow[] The schedules of the page, newest first unless the call orders otherwise. */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public array $schedules = [];

    /** @var string|null The projected monthly load of the active recurring schedules and the state of the schedule budget, for the user. */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $recurringBudgetNote = null;

    /** @var string|null How to show the task list on screen, when the application offers a widget for it. */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $taskListWidgetNote = null;

    /** @var int|null How many schedules match the query in total, independent of the page. */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?int $totalMatchingCount = null;

    /** @var int|null How many matching schedules the page skipped (`skip`). */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?int $skippedRowCount = null;

    /** @var string|null The filter the call applied, in the filter grammar, moment literals in UTC; null = no filter. */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $appliedFilterExpression = null;

    /** @var bool|null Whether ARCHIVED schedules were part of the query (`includeArchived`). */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?bool $archivedSchedulesIncluded = null;

    /**
     * The header (shown and matching rows, the status roll-up of the shown rows), one block per schedule
     * ({@see TaskScheduleMcpRow::toLlmMarkdown()}), then the budget and the widget note.
     *
     * @return string
     */
    public function toLlmMarkdown(): string
    {
        $filterNote = $this->appliedFilterExpression !== null
            ? ' Filter applied: `' . $this->appliedFilterExpression . '` (moment literals in UTC).'
            : '';
        $archiveNote = $this->archivedSchedulesIncluded === true
            ? ''
            : ' ARCHIVED schedules are not part of this read (includeArchived: true adds them).';
        $trailingNotes = [];
        foreach ([$this->recurringBudgetNote, $this->taskListWidgetNote] as $trailingNote) {
            if ($trailingNote !== null && trim($trailingNote) !== '') {
                $trailingNotes[] = trim($trailingNote);
            }
        }
        if ($this->schedules === []) {
            $markdown = (($this->totalMatchingCount ?? 0) > 0
                    ? "No schedules on this page — {$this->totalMatchingCount} match in total; lower `skip`."
                    : 'No task schedules match.') . $filterNote . $archiveNote;
            return $trailingNotes === [] ? $markdown : $markdown . "\n\n" . implode("\n\n", $trailingNotes);
        }
        $countPerStatus = [];
        foreach ($this->schedules as $scheduleRow) {
            $countPerStatus[$scheduleRow->status] = ($countPerStatus[$scheduleRow->status] ?? 0) + 1;
        }
        $rollUpParts = [];
        foreach ($countPerStatus as $status => $count) {
            $rollUpParts[] = "{$count} {$status}";
        }
        $shownCount = count($this->schedules);
        $headline = "## Task schedules ({$shownCount}";
        if ($this->totalMatchingCount !== null) {
            $firstShown = ($this->skippedRowCount ?? 0) + 1;
            $lastShown = ($this->skippedRowCount ?? 0) + $shownCount;
            $headline .= " of {$this->totalMatchingCount} matching, rows {$firstShown}–{$lastShown}";
            if ($lastShown < $this->totalMatchingCount) {
                $headline .= ' — MORE EXIST: page on with `skip: ' . $lastShown . '` or narrow the filter';
            }
        }
        $headline .= '; shown rows by status: ' . implode(', ', $rollUpParts) . ')';
        $lines = [$headline];
        $lines[] = 'Only ACTIVE schedules still fire (PAUSED ones resume later); DONE / CANCELLED / EXPIRED / ARCHIVED are history.'
            . ' What actually RAN, and how often, is getTaskRuns.' . $filterNote . $archiveNote;
        foreach ($this->schedules as $scheduleRow) {
            $lines[] = '';
            $lines[] = $scheduleRow->toLlmMarkdown();
        }
        foreach ($trailingNotes as $trailingNote) {
            $lines[] = '';
            $lines[] = $trailingNote;
        }
        return implode("\n", $lines);
    }
}
