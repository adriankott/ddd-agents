<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRunReport;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * One failed case of a stored evaluation run as the failure analysis reads it: its row (with the message trace, read
 * from the case file when the summary holds none) and the tool calls of that trace, and the class of its failure
 * ({@see classify()}):
 *
 *  - {@see CLASS_ERROR}: the run threw (an exception, no parseable output of a child);
 *  - {@see CLASS_BAD_ARGS}: the expected tool WAS called, but its status or an argument check failed;
 *  - {@see CLASS_WRONG_SKILL}: the expected skill was never loaded;
 *  - {@see CLASS_STALL}: the turn ended on its step budget (the run report of the loop names it) without a data tool
 *    call that finished — checked again after the data-tool classes below;
 *  - {@see CLASS_TOOL_DATA_BUG}: a data tool was called and every such call failed (the tool layer, not the model);
 *  - {@see CLASS_FIXTURE_TOO_STRICT}: a data tool finished, just not one the fixture lists;
 *  - {@see CLASS_ANSWERED_WITHOUT_TOOL}: no data tool was called, the agent gave a final text (an answer or a question);
 *  - {@see CLASS_UNCLASSIFIED}: none of these.
 *
 * A data tool is any tool but the loop's own (loading skills, delegating).
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalAnalyzeCase extends ValueObject
{
    public const string CLASS_ERROR = 'ERROR';
    public const string CLASS_BAD_ARGS = 'BAD_ARGS';
    public const string CLASS_WRONG_SKILL = 'WRONG_SKILL';
    public const string CLASS_STALL = 'STALL';
    public const string CLASS_TOOL_DATA_BUG = 'TOOL_DATA_BUG';
    public const string CLASS_FIXTURE_TOO_STRICT = 'FIXTURE_TOO_STRICT';
    public const string CLASS_ANSWERED_WITHOUT_TOOL = 'ANSWERED_WITHOUT_TOOL';
    public const string CLASS_UNCLASSIFIED = 'UNCLASSIFIED';

    /** @var string The class a passed case is counted under */
    public const string CLASS_PASS = 'PASS';

    /** @var string The text of the final answer of a turn that ended on its step budget (the loop's run report names it) */
    public const string STEP_BUDGET_STOP_TEXT = AgentRunReport::STOP_REASON_STEP_BUDGET_USED_UP;

    /** @var string The failure text of a case whose expected skill was never loaded */
    public const string SKILL_NOT_LOADED_FAILURE = 'skill not loaded';

    /** @var string[] The loop's own tools: loading skills and delegating (with the name of older traces) */
    public const array LOOP_TOOL_NAMES = ['load_skills', 'delegate', 'start_thread'];

    /** @var string The terminal status of a call that finished */
    public const string STATUS_COMPLETED = 'COMPLETED';

    /** @var string The terminal status of a call that failed */
    public const string STATUS_FAILED = 'FAILED';

    /** @var AgentEvalCaseRow The case row, with its message trace when the run kept one */
    public AgentEvalCaseRow $caseRow;

    /** @var AgentEvalAnalyzeToolCalls The tool calls of the row's message trace */
    public AgentEvalAnalyzeToolCalls $toolCalls;

    /** The case of the row `$agentEvalCaseRow` (the full row: its trace is what the classes read). */
    public static function fromCaseRow(AgentEvalCaseRow $agentEvalCaseRow): static
    {
        $agentEvalAnalyzeCase = static::newInstance();
        $agentEvalAnalyzeCase->caseRow = $agentEvalCaseRow;
        $agentEvalAnalyzeCase->toolCalls = AgentEvalAnalyzeToolCalls::fromMessagesTrace($agentEvalCaseRow->getValue('messages'));
        return $agentEvalAnalyzeCase;
    }

    /** The class of the failure (one of the `CLASS_*` constants but {@see CLASS_PASS}). */
    public function classify(): string
    {
        if ($this->caseRow->hasError()) {
            return static::CLASS_ERROR;
        }
        $finalText = $this->getFinalText();
        $endedOnStepBudget = str_contains($finalText, static::STEP_BUDGET_STOP_TEXT);
        $dataToolCalls = $this->toolCalls->buildCallsExcept(static::LOOP_TOOL_NAMES);
        $anyDataCallCompleted = $dataToolCalls->hasCallWithStatus(static::STATUS_COMPLETED);
        $anyDataCallFailed = $dataToolCalls->hasCallWithStatus(static::STATUS_FAILED);
        $matchedToolName = (string)($this->caseRow->getValue('matchedTool') ?? '');

        // the expected tool was called: the failure is about its status or its arguments, not the choice of the tool
        if ($matchedToolName !== '') {
            return static::CLASS_BAD_ARGS;
        }
        if ($this->hasFailureContaining(static::SKILL_NOT_LOADED_FAILURE)) {
            return static::CLASS_WRONG_SKILL;
        }
        if ($endedOnStepBudget && $dataToolCalls->count() === 0) {
            return static::CLASS_STALL;
        }
        if ($anyDataCallFailed && !$anyDataCallCompleted) {
            return static::CLASS_TOOL_DATA_BUG;
        }
        if ($anyDataCallCompleted) {
            return static::CLASS_FIXTURE_TOO_STRICT;
        }
        if ($endedOnStepBudget) {
            return static::CLASS_STALL;
        }
        if ($dataToolCalls->count() === 0 && $finalText !== '') {
            return static::CLASS_ANSWERED_WITHOUT_TOOL;
        }
        return static::CLASS_UNCLASSIFIED;
    }

    /** `finalText` of the row; empty when it holds none. */
    public function getFinalText(): string
    {
        return (string)($this->caseRow->getValue('finalText') ?? '');
    }

    /**
     * `failures` of the row, each as text; none when the row holds no list.
     *
     * @return string[]
     */
    public function getFailures(): array
    {
        $failures = $this->caseRow->getValue('failures');
        return is_array($failures) ? array_map('strval', array_values($failures)) : [];
    }

    /** Whether one of the row's failures contains `$needle`. */
    public function hasFailureContaining(string $needle): bool
    {
        foreach ($this->getFailures() as $failure) {
            if (str_contains($failure, $needle)) {
                return true;
            }
        }
        return false;
    }
}
