<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleStatus;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The task runs `getTaskRuns` read: one page of the runs of every schedule in scope, how many match, and counts per run
 * state (and per start day on a long page). Rendered as Markdown for the model.
 */
class TaskRunsMcpResponseDto extends RestResponseDto implements LlmMarkdownRenderable
{
    /** @var string[] The order of the run-state counts: the end marks first, then the open states. */
    protected const array RUN_STATE_DISPLAY_ORDER = [
        AIConversationRunState::COMPLETED,
        AIConversationRunState::FAILED,
        AIConversationRunState::IDLE,
        AIConversationRunState::CANCELLED,
        AIConversationRunState::RUNNING,
        AIConversationRunState::AWAITING_DELEGATES,
        AIConversationRunState::QUEUED,
    ];

    /**
     * What a run state says about a run that is over without the completed or the failed mark. A scheduled run is
     * marked COMPLETED once its report reached its channel and FAILED once its retries are used up; IDLE means
     * neither happened (the report was never delivered, or the run predates the completed mark).
     */
    protected const array RUN_STATE_MEANINGS = [
        AIConversationRunState::IDLE => 'IDLE = the run is over but carries neither the completed nor the failed mark: never count or call it completed or failed — its outcome line says whether it did its work.',
        AIConversationRunState::CANCELLED => 'CANCELLED = the run was stopped before it ended: it is neither completed nor failed.',
    ];

    /** What any other state of a run that is over without the completed or the failed mark says. */
    protected const string OTHER_ENDED_RUN_STATE_MEANING = 'A state other than COMPLETED, FAILED or one in progress is neither completed nor failed — the outcome line says what happened.';

    /**
     * The sentence under an empty result whose filter keeps only runs in progress: no run under way says nothing about
     * which tasks are set up and active — a schedule is active between its runs.
     */
    public const string NO_RUN_IN_PROGRESS_IS_NOT_NO_ACTIVE_TASK_NOTE = 'No execution is under way right now; tasks that are set up and still active are schedules, listed by `getTaskSchedules` with `status eq \'' . AIConversationScheduleStatus::ACTIVE . '\'` — read it before telling the owner that nothing is active.';

    /** The row count a page must exceed before its rows are counted per start day. */
    protected const int START_DAY_COUNT_MINIMUM_ROWS = 10;

    /** @var TaskRunMcpRow[] The runs of the page, newest first unless the call orders otherwise. */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public array $runs = [];

    /** @var int|null How many runs match the query in total, independent of the page. */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?int $totalMatchingCount = null;

    /** @var int|null How many matching runs the page skipped (`skip`). */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?int $skippedRowCount = null;

    /** @var string|null The filter the call applied, in the filter grammar, moment literals in UTC; null = no filter. */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $appliedFilterExpression = null;

    /**
     * The header states how many runs match and which of them are shown — a page is never readable as the whole
     * period — and counts the listed rows per run state (and per start day on a long page spanning several days), so the
     * model reads its numbers instead of counting rows; then one line per run ({@see TaskRunMcpRow::toLlmMarkdown()}).
     */
    public function toLlmMarkdown(): string
    {
        $filterNote = $this->appliedFilterExpression !== null
            ? ' Filter applied: `' . $this->appliedFilterExpression . '` (moment literals in UTC).'
            : '';
        if ($this->runs === []) {
            if (($this->totalMatchingCount ?? 0) > 0) {
                return "No runs on this page — {$this->totalMatchingCount} runs match in total; lower `skip`." . $filterNote;
            }
            return 'No task runs match.' . $filterNote
                . (static::filterRestrictsRunStateToInProgress($this->appliedFilterExpression) ? ' ' . static::NO_RUN_IN_PROGRESS_IS_NOT_NO_ACTIVE_TASK_NOTE : '');
        }
        $shownCount = count($this->runs);
        $headline = "## Task runs ({$shownCount}";
        if ($this->totalMatchingCount !== null) {
            $firstShown = ($this->skippedRowCount ?? 0) + 1;
            $lastShown = ($this->skippedRowCount ?? 0) + $shownCount;
            $headline .= " of {$this->totalMatchingCount} matching, rows {$firstShown}–{$lastShown}";
            if ($lastShown < $this->totalMatchingCount) {
                $headline .= ' — MORE EXIST: page on with `skip: ' . $lastShown . '` or narrow the filter before you state a total';
            }
        }
        $headline .= ')';
        $lines = [$headline];
        $lines[] = $this->buildRunStateCountLine();
        $startDayCountLine = $this->buildStartDayCountLine();
        if ($startDayCountLine !== null) {
            $lines[] = $startDayCountLine;
        }
        $lines[] = 'Full report of one run: getTaskRunReport(conversationId).' . $filterNote;
        foreach ($this->runs as $runRow) {
            $lines[] = $runRow->toLlmMarkdown();
        }
        return implode("\n", $lines);
    }

    /**
     * The listed rows counted per run state, in {@see RUN_STATE_DISPLAY_ORDER} (other states after them,
     * alphabetically), with the rows still in progress named per state; followed by what a state that is neither
     * completed nor failed nor in progress means, once per such state among the rows.
     */
    protected function buildRunStateCountLine(): string
    {
        $rowCountByRunState = [];
        $inProgressCountByRunState = [];
        $endedWithoutMarkRunStates = [];
        foreach ($this->runs as $runRow) {
            $rowCountByRunState[$runRow->runState] = ($rowCountByRunState[$runRow->runState] ?? 0) + 1;
            if ($runRow->isInProgress === true) {
                $inProgressCountByRunState[$runRow->runState] = ($inProgressCountByRunState[$runRow->runState] ?? 0) + 1;
            }
            if ($runRow->endedWithoutCompletedOrFailedMark()) {
                $endedWithoutMarkRunStates[$runRow->runState] = true;
            }
        }
        $countParts = [];
        foreach ($this->findRunStatesInDisplayOrder(array_keys($rowCountByRunState)) as $runState) {
            $countPart = ($runState !== '' ? $runState : 'UNKNOWN') . ' ' . $rowCountByRunState[$runState];
            if (isset($inProgressCountByRunState[$runState])) {
                $countPart .= " ({$inProgressCountByRunState[$runState]} still in progress)";
            }
            $countParts[] = $countPart;
        }
        $line = 'Run states of the rows listed: ' . implode(' · ', $countParts) . '.';
        foreach ($this->findRunStatesInDisplayOrder(array_keys($endedWithoutMarkRunStates)) as $runState) {
            $line .= ' ' . (static::RUN_STATE_MEANINGS[$runState] ?? static::OTHER_ENDED_RUN_STATE_MEANING);
        }
        return $line;
    }

    /**
     * The listed rows counted per calendar day of their start moment, newest day first — only when the rows span
     * more than one day and there are more than {@see START_DAY_COUNT_MINIMUM_ROWS} of them; null otherwise.
     */
    protected function buildStartDayCountLine(): ?string
    {
        if (count($this->runs) <= static::START_DAY_COUNT_MINIMUM_ROWS) {
            return null;
        }
        $rowCountByStartDay = [];
        foreach ($this->runs as $runRow) {
            $startDay = $runRow->getStartDay();
            if ($startDay !== null) {
                $rowCountByStartDay[$startDay] = ($rowCountByStartDay[$startDay] ?? 0) + 1;
            }
        }
        if (count($rowCountByStartDay) < 2) {
            return null;
        }
        krsort($rowCountByStartDay, SORT_STRING);
        $countParts = [];
        foreach ($rowCountByStartDay as $startDay => $rowCount) {
            $countParts[] = $startDay . ' ' . $rowCount;
        }
        return 'Rows per start day of the rows listed: ' . implode(' · ', $countParts) . '.';
    }

    /**
     * Whether an applied filter (the canonical expression, {@see $appliedFilterExpression}) keeps only runs in progress:
     * it carries at least one `runState` condition, and every one of them either names only states of an execution
     * under way ({@see AIConversationRunState::NON_TERMINAL}, with `eq` / `in`) or excludes every state of a run at rest
     * or ended ({@see AIConversationRunState::AT_REST_OR_ENDED}, with `ne` / `ni`). Pure.
     */
    public static function filterRestrictsRunStateToInProgress(?string $appliedFilterExpression): bool
    {
        if ($appliedFilterExpression === null
            || !preg_match_all('/\brunState\s+(eq|in|ne|ni)\s+(\[[^\]]*\]|\'[^\']*\'|"[^"]*")/', $appliedFilterExpression, $runStateConditionMatches, PREG_SET_ORDER)
        ) {
            return false;
        }
        foreach ($runStateConditionMatches as $runStateConditionMatch) {
            if (!static::runStateConditionKeepsOnlyRunsInProgress($runStateConditionMatch[1], static::findRunStateNamesInFilterLiteral($runStateConditionMatch[2]))) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether one `runState` condition keeps only runs in progress: `eq` / `in` naming states under way only, or
     * `ne` / `ni` excluding every state at rest or ended. Pure.
     *
     * @param string[] $runStateNames The states the condition names
     */
    protected static function runStateConditionKeepsOnlyRunsInProgress(string $filterOperator, array $runStateNames): bool
    {
        if ($runStateNames === []) {
            return false;
        }
        if ($filterOperator === 'eq' || $filterOperator === 'in') {
            return array_diff($runStateNames, AIConversationRunState::NON_TERMINAL) === [];
        }
        return array_diff(AIConversationRunState::AT_REST_OR_ENDED, $runStateNames) === [];
    }

    /**
     * @return string[] The state names a filter literal carries (`'RUNNING'` or `["RUNNING","QUEUED"]`), upper-cased. Pure.
     */
    protected static function findRunStateNamesInFilterLiteral(string $filterLiteral): array
    {
        preg_match_all('/[\'"]([A-Za-z_]+)[\'"]/', $filterLiteral, $runStateNameMatches);
        return array_map('strtoupper', $runStateNameMatches[1]);
    }

    /**
     * @param string[] $runStates
     * @return string[] The run states in {@see RUN_STATE_DISPLAY_ORDER}, the states it does not name after them, alphabetically
     */
    protected function findRunStatesInDisplayOrder(array $runStates): array
    {
        $orderedRunStates = array_values(array_intersect(static::RUN_STATE_DISPLAY_ORDER, $runStates));
        $otherRunStates = array_values(array_diff($runStates, static::RUN_STATE_DISPLAY_ORDER));
        sort($otherRunStates, SORT_STRING);
        return array_merge($orderedRunStates, $otherRunStates);
    }
}
