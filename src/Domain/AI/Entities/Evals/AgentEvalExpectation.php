<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentEvalResult;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SetTaskStatusTool;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlocks;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The `expect` block of one evaluation case and its checks against a driven conversation. No I/O: the checks read only
 * the conversation, the messages of its tree (delegation children included) and their aggregate accessors.
 *
 * Supported keys, one check method per group, joined by {@see evaluateConversation()} in this order:
 * - `skill` (one name) and `skills` (a list, all of them): every named skill must have been loaded; `forbiddenSkills`
 *   (a name or a list): none of them may have been loaded; `noSkill` (true): no skill may have been loaded at all
 *   ({@see buildSkillFailures()}, {@see buildForbiddenSkillFailures()});
 * - `tool` (a name or a list, any of them): a matching call must exist; the call that is checked is chosen by
 *   {@see findExpectedToolCallRow()}. `status` is the checked call's terminal status, default `COMPLETED`; without a
 *   stated status a call the evaluation run's own tool block refused counts as made ({@see buildExpectedToolFailures()});
 * - `argsContain` (argument name → substring or list of substrings): the value of THAT argument must contain them (a
 *   value that is no string is read as its unescaped JSON; a dotted name reaches into an object; a list without names
 *   checks the whole arguments); `argsAbsent` (the same shape): the arguments as unescaped JSON must not contain them
 *   ({@see buildArgumentFailures()});
 * - `resultContains` / `resultNotContains` (a substring or a list): the checked call's result as unescaped text — the
 *   words of the result and, for a structured result, its data as unescaped JSON, as the argument checks read
 *   arguments — must / must not contain them ({@see buildResultContainsFailures()}, {@see buildResultNotContainsFailures()});
 * - `requiredTools` (a name or a list, all of them): each must have a COMPLETED call in the tree; `forbiddenTools`
 *   (a name or a list): the case fails when any of them was called ({@see buildRequiredToolFailures()},
 *   {@see buildForbiddenToolFailures()});
 * - `taskPlan` (a map of plan assertions, see {@see buildTaskPlanFailures()});
 * - `emitsWidget` (a name or a list, all of them), `emitsAnyWidgetOf` (a list, one of them), `forbidsWidget` (true, a
 *   name or a list): the widget blocks of the final answer ({@see buildAnswerWidgetFailures()});
 * - `emitsProgressText` (bool): a text to the owner before the first tool call ({@see buildProgressTextFailures()});
 * - `emitsToolMessage` / `forbidsToolMessage` (a name or a list): widgets of tool messages anywhere in the tree
 *   ({@see buildToolMessageWidgetFailures()});
 * - `finalAnswerNotContains` (a phrase or a list, case-insensitive) ({@see buildFinalAnswerPhraseFailures()});
 * - `signalSources` ({requires, forbids} lists of loop signal sources) ({@see buildSignalSourceFailures()});
 * - `toolCallCountMax` / `toolCallCountMin` (tool name → bound on its calls in the tree, any status)
 *   ({@see buildToolCallCountFailures()}).
 *
 * Every other key of the block (a performance budget, a state check after the run, …) is kept unread in
 * {@see toArray()} for the code that reads it.
 *
 * The arguments the platform fills on a tool call when the model leaves them out are an input of the checks
 * (argument name → the value the run fills, e.g. the agent space of the run): `argsContain` reads a call that lacks
 * such an argument as if it carried the filled value, because the tool ran on it. A call that passes the argument
 * itself is checked on what it passed. `argsAbsent` always reads the arguments as the model sent them.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalExpectation extends ValueObject
{
    /** @var array<string, mixed> The decoded `expect` block as the case states it */
    public array $expectBlock = [];

    /**
     * The expectation of a case, built from its decoded `expect` block.
     *
     * @param array<string, mixed> $expect the decoded `expect` block
     */
    public static function fromExpectBlock(array $expect): static
    {
        $agentEvalExpectation = static::newInstance();
        $agentEvalExpectation->expectBlock = $expect;
        return $agentEvalExpectation;
    }

    /**
     * The `expect` block as the case states it, every key included.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->expectBlock;
    }

    /**
     * The skills the case expects loaded: the `skills` list, then the `skill` name when it is a string, each once. The
     * one reading of these two keys: the applicability check reads them here as well
     * ({@see AgentEvalCase::getExpectedSkillNames()}); a `skill` that is no string names no skill.
     *
     * @return string[]
     */
    public function getExpectedSkillNames(): array
    {
        $expectedSkillNames = [];
        if (is_array($this->expectBlock['skills'] ?? null)) {
            $expectedSkillNames = array_map('strval', $this->expectBlock['skills']);
        }
        if (isset($this->expectBlock['skill']) && is_string($this->expectBlock['skill'])) {
            $expectedSkillNames[] = $this->expectBlock['skill'];
        }
        return array_values(array_unique($expectedSkillNames));
    }

    /**
     * The tools of `tool`: a call of any one of them satisfies the case. Empty when the case expects no tool.
     *
     * @return string[]
     */
    public function getAnyOfToolNames(): array
    {
        return $this->getStringListOfKey('tool');
    }

    /**
     * The tools of `requiredTools`: each of them must have a completed call. Empty when the key is absent.
     *
     * @return string[]
     */
    public function getRequiredToolNames(): array
    {
        return $this->getStringListOfKey('requiredTools');
    }

    /**
     * Every unmet assertion of the block against the driven conversation, as one sentence each, in the order of the
     * key groups listed on the class. An empty list when the conversation meets the block.
     *
     * @param AIConversation $conversation the driven conversation
     * @param array<string, int|string> $toolArgumentsFilledByThePlatform argument name → the value the platform fills
     *        when the model leaves the argument out (see the class docblock)
     * @return string[]
     */
    public function evaluateConversation(AIConversation $conversation, array $toolArgumentsFilledByThePlatform = []): array
    {
        $messages = $conversation->getAllMessagesIncludingDelegations();
        $expectedToolCallRow = $this->findExpectedToolCallRow($messages, $toolArgumentsFilledByThePlatform);
        return array_merge(
            $this->buildSkillFailures($messages),
            $this->buildForbiddenSkillFailures($messages),
            $this->buildExpectedToolFailures($conversation, $messages, $expectedToolCallRow),
            $this->buildArgumentFailures($expectedToolCallRow, $toolArgumentsFilledByThePlatform),
            $this->buildResultContainsFailures($messages, $expectedToolCallRow),
            $this->buildRequiredToolFailures($messages),
            $this->buildForbiddenToolFailures($messages),
            $this->buildTaskPlanFailures($conversation, $messages),
            $this->buildAnswerWidgetFailures($conversation),
            $this->buildProgressTextFailures($messages),
            $this->buildResultNotContainsFailures($messages, $expectedToolCallRow),
            $this->buildToolMessageWidgetFailures($messages),
            $this->buildFinalAnswerPhraseFailures($conversation),
            $this->buildSignalSourceFailures($messages),
            $this->buildToolCallCountFailures($messages)
        );
    }

    /**
     * The deterministic gates of the block that hold for a judged run (several owner turns, then the judge): the
     * assertions about what happened anywhere in the conversation tree — `skill` / `skills`, `forbiddenSkills` /
     * `noSkill`, `tool` with `status`, `argsContain` / `argsAbsent`, `resultContains` / `resultNotContains`,
     * `requiredTools`, `forbiddenTools`. The same checks as {@see evaluateConversation()}, so a judged run and the single
     * shot fail a case for the same reason. Left out are the assertions about the shape of one turn, which more owner
     * turns change by design: the final answer's widgets and phrases, the progress text, the task plan's end state,
     * signal sources and tool call counts. A judge never overrules these gates
     * ({@see \DDD\Domain\AI\Services\Eval\AgentEvalJudgedRunService::applyDeterministicGatesToRunResult()}).
     *
     * @param AIConversation $conversation the driven conversation
     * @param array<string, int|string> $toolArgumentsFilledByThePlatform see {@see evaluateConversation()}
     * @return string[] one sentence per unmet gate; empty when the run meets them
     */
    public function evaluateConversationForJudgedRun(AIConversation $conversation, array $toolArgumentsFilledByThePlatform = []): array
    {
        $messages = $conversation->getAllMessagesIncludingDelegations();
        $expectedToolCallRow = $this->findExpectedToolCallRow($messages, $toolArgumentsFilledByThePlatform);
        return array_merge(
            $this->buildSkillFailures($messages),
            $this->buildForbiddenSkillFailures($messages),
            $this->buildExpectedToolFailures($conversation, $messages, $expectedToolCallRow),
            $this->buildArgumentFailures($expectedToolCallRow, $toolArgumentsFilledByThePlatform),
            $this->buildResultContainsFailures($messages, $expectedToolCallRow),
            $this->buildRequiredToolFailures($messages),
            $this->buildForbiddenToolFailures($messages),
            $this->buildResultNotContainsFailures($messages, $expectedToolCallRow)
        );
    }

    /**
     * The verdict of the driven conversation: passed when {@see evaluateConversation()} finds no failure, with the name
     * of the expected tool whose call was checked (null when the case expects no tool or none of them was called).
     *
     * @param array<string, int|string> $toolArgumentsFilledByThePlatform see {@see evaluateConversation()}
     */
    public function buildEvalResult(AIConversation $conversation, array $toolArgumentsFilledByThePlatform = []): AgentEvalResult
    {
        $failures = $this->evaluateConversation($conversation, $toolArgumentsFilledByThePlatform);
        $expectedToolCallRow = $this->findExpectedToolCallRow($conversation->getAllMessagesIncludingDelegations(), $toolArgumentsFilledByThePlatform);
        return new AgentEvalResult($failures === [], $failures, $expectedToolCallRow?->toolCall->name, $conversation);
    }

    /**
     * The call of one of the expected tools the case is checked on, else null when the case expects no tool or none of
     * them was called. Preference: a COMPLETED call whose arguments carry every `argsContain` substring (an agent that
     * creates a record with one call and sets the asked value with a second call is checked on the call that carries
     * the value), then any COMPLETED call (the contract is "at least one of these tools completed"), then the first
     * call, so a sole failure still reports its status.
     *
     * @param array<string, int|string> $toolArgumentsFilledByThePlatform see {@see evaluateConversation()}
     */
    public function findExpectedToolCallRow(AIConversationMessages $messages, array $toolArgumentsFilledByThePlatform = []): ?AIConversationMessage
    {
        $expectedToolNames = $this->getAnyOfToolNames();
        if ($expectedToolNames === []) {
            return null;
        }
        $firstCallRow = null;
        $firstCompletedCallRow = null;
        foreach ($messages->buildToolCallHistory()->getToolCallRows() as $toolCallRow) {
            if (!in_array((string)$toolCallRow->toolCall->name, $expectedToolNames, true)) {
                continue;
            }
            $firstCallRow ??= $toolCallRow;
            if ($toolCallRow->toolCall->status !== AIConversationMessage::STATUS_COMPLETED) {
                continue;
            }
            $firstCompletedCallRow ??= $toolCallRow;
            if ($this->buildArgsContainFailures($toolCallRow, $toolArgumentsFilledByThePlatform) === []) {
                return $toolCallRow;
            }
        }
        return $firstCompletedCallRow ?? $firstCallRow;
    }

    /**
     * `skill` and `skills`: one failure per expected skill that was not loaded anywhere in the tree.
     *
     * @return string[]
     */
    public function buildSkillFailures(AIConversationMessages $messages): array
    {
        $skillFailures = [];
        $loadedSkillNames = $messages->getLoadedSkillNames();
        foreach ($this->getExpectedSkillNames() as $expectedSkillName) {
            if (!in_array($expectedSkillName, $loadedSkillNames, true)) {
                $skillFailures[] = sprintf(
                    "skill not loaded: expected '%s', loaded [%s]",
                    $expectedSkillName,
                    implode(', ', $loadedSkillNames)
                );
            }
        }
        return $skillFailures;
    }

    /**
     * `forbiddenSkills` and `noSkill`: one failure per forbidden skill that was loaded anywhere in the tree, and one when
     * `noSkill` is true and any skill was loaded.
     *
     * @return string[]
     */
    public function buildForbiddenSkillFailures(AIConversationMessages $messages): array
    {
        $loadedSkillNames = $messages->getLoadedSkillNames();
        $forbiddenSkillFailures = [];
        foreach ($this->getStringListOfKey('forbiddenSkills') as $forbiddenSkillName) {
            if (in_array($forbiddenSkillName, $loadedSkillNames, true)) {
                $forbiddenSkillFailures[] = sprintf("forbidden skill loaded: '%s'", $forbiddenSkillName);
            }
        }
        if (($this->expectBlock['noSkill'] ?? false) === true && $loadedSkillNames !== []) {
            $forbiddenSkillFailures[] = sprintf('skill loaded although none was expected: [%s]', implode(', ', $loadedSkillNames));
        }
        return $forbiddenSkillFailures;
    }

    /**
     * `tool` and `status`: a failure when none of the expected tools was called, or when the checked call ended in
     * another status than the expected one. Without a stated `status`, a call the evaluation run's own tool block
     * refused is no failure of the agent: it made the call the case asks for and the run did not let it happen.
     *
     * @return string[]
     */
    public function buildExpectedToolFailures(
        AIConversation $conversation,
        AIConversationMessages $messages,
        ?AIConversationMessage $expectedToolCallRow
    ): array {
        $expectedToolNames = $this->getAnyOfToolNames();
        if ($expectedToolNames === []) {
            return [];
        }
        if ($expectedToolCallRow === null) {
            return [sprintf(
                'tool not called: expected one of [%s], called [%s]',
                implode(', ', $expectedToolNames),
                implode(', ', $messages->buildToolCallHistory()->getCalledToolNames())
            )];
        }
        $expectedStatus = isset($this->expectBlock['status']) ? (string)$this->expectBlock['status'] : AIConversationMessage::STATUS_COMPLETED;
        $evaluationRunRefusedTheCall = !isset($this->expectBlock['status'])
            && $this->evaluationRunRefusesTool($conversation, (string)$expectedToolCallRow->toolCall->name);
        if ($evaluationRunRefusedTheCall || $expectedToolCallRow->toolCall->status === $expectedStatus) {
            return [];
        }
        return [sprintf(
            "tool '%s' status: expected '%s', got '%s'",
            (string)$expectedToolCallRow->toolCall->name,
            $expectedStatus,
            $expectedToolCallRow->toolCall->status ?? 'null'
        )];
    }

    /**
     * `argsContain` and `argsAbsent` on the checked call: the substrings its arguments lack, then the ones they carry
     * although the case forbids them. Empty when no expected tool was called.
     *
     * @param array<string, int|string> $toolArgumentsFilledByThePlatform see {@see evaluateConversation()}
     * @return string[]
     */
    public function buildArgumentFailures(?AIConversationMessage $expectedToolCallRow, array $toolArgumentsFilledByThePlatform = []): array
    {
        if ($expectedToolCallRow === null) {
            return [];
        }
        $argumentFailures = $this->buildArgsContainFailures($expectedToolCallRow, $toolArgumentsFilledByThePlatform);
        if (!isset($this->expectBlock['argsAbsent']) || !is_array($this->expectBlock['argsAbsent'])) {
            return $argumentFailures;
        }
        $argumentsAsSent = $this->buildToolCallArgumentsAsUnescapedJson($expectedToolCallRow);
        foreach ($this->expectBlock['argsAbsent'] as $argumentKey => $needles) {
            foreach ((array)$needles as $needle) {
                if (str_contains($argumentsAsSent, (string)$needle)) {
                    $argumentFailures[] = sprintf("argsAbsent[%s]: '%s' unexpectedly in args", (string)$argumentKey, (string)$needle);
                }
            }
        }
        return $argumentFailures;
    }

    /**
     * One failure per `argsContain` substring the call's arguments lack; empty when the case states no `argsContain`
     * or the call carries them all.
     *
     * @param array<string, int|string> $toolArgumentsFilledByThePlatform see {@see evaluateConversation()}
     * @return string[]
     */
    public function buildArgsContainFailures(AIConversationMessage $toolCallRow, array $toolArgumentsFilledByThePlatform = []): array
    {
        if (!isset($this->expectBlock['argsContain']) || !is_array($this->expectBlock['argsContain'])) {
            return [];
        }
        $arguments = $this->buildToolCallArgumentsAsUnescapedJson($toolCallRow, $toolArgumentsFilledByThePlatform);
        $argsContainFailures = [];
        foreach ($this->expectBlock['argsContain'] as $argumentKey => $needles) {
            // a named key reads the value of that argument; a list without names reads the whole arguments
            $argumentValueText = is_string($argumentKey) ? $this->buildArgumentValueAsUnescapedText($arguments, $argumentKey) : $arguments;
            foreach ((array)$needles as $needle) {
                // a needle written for the whole arguments (before 0.1.3) starts with the key it names — `"dateFrom":"2026`
                // under `dateFrom`; it is still searched in the whole arguments, as it was written
                $searchedText = is_string($argumentKey) && $this->isNeedleWrittenForTheWholeArguments((string)$needle, $argumentKey)
                    ? $arguments
                    : $argumentValueText;
                if (!str_contains($searchedText, (string)$needle)) {
                    $argsContainFailures[] = sprintf("argsContain[%s]: '%s' not in args %s", (string)$argumentKey, (string)$needle, $arguments);
                }
            }
        }
        return $argsContainFailures;
    }

    /**
     * Whether `$needle` names `$argumentKey` itself as a JSON key (`"dateFrom":…`, also with spaces): the form written
     * for the whole-arguments search before a named key read only that argument's value. A dotted key matches its last
     * segment.
     */
    public function isNeedleWrittenForTheWholeArguments(string $needle, string $argumentKey): bool
    {
        $keySegments = explode('.', $argumentKey);
        $lastKeySegment = (string)end($keySegments);
        return preg_match('/^\s*"' . preg_quote($lastKeySegment, '/') . '"\s*:/', $needle) === 1;
    }

    /**
     * `resultContains`: every substring must appear in the checked call's result. For a `delegate` call the result is
     * its spawn answer plus the sub-agent's run ends ({@see AIConversationMessages::buildDelegateCallOutcomeText()}).
     *
     * @return string[]
     */
    public function buildResultContainsFailures(AIConversationMessages $messages, ?AIConversationMessage $expectedToolCallRow): array
    {
        if ($expectedToolCallRow === null || !isset($this->expectBlock['resultContains'])) {
            return [];
        }
        $resultFailures = [];
        $callOutcomeText = $this->buildCallOutcomeAsUnescapedText($messages, $expectedToolCallRow);
        foreach ($this->getStringListOfKey('resultContains') as $resultNeedle) {
            if ($resultNeedle !== '' && !str_contains($callOutcomeText, $resultNeedle)) {
                $resultFailures[] = sprintf(
                    "resultContains: '%s' not in the result of '%s' (%s)",
                    $resultNeedle,
                    (string)$expectedToolCallRow->toolCall->name,
                    mb_substr(preg_replace('/\s+/', ' ', $callOutcomeText) ?? $callOutcomeText, 0, 300)
                );
            }
        }
        return $resultFailures;
    }

    /**
     * `resultNotContains`: no substring may appear in the checked call's result (read as {@see buildResultContainsFailures()} reads it).
     *
     * @return string[]
     */
    public function buildResultNotContainsFailures(AIConversationMessages $messages, ?AIConversationMessage $expectedToolCallRow): array
    {
        if ($expectedToolCallRow === null || !isset($this->expectBlock['resultNotContains'])) {
            return [];
        }
        $resultFailures = [];
        $callOutcomeText = $this->buildCallOutcomeAsUnescapedText($messages, $expectedToolCallRow);
        foreach ($this->getStringListOfKey('resultNotContains') as $forbiddenNeedle) {
            if ($forbiddenNeedle !== '' && str_contains($callOutcomeText, $forbiddenNeedle)) {
                $resultFailures[] = sprintf("resultNotContains: '%s' found in the result of '%s'", $forbiddenNeedle, (string)$expectedToolCallRow->toolCall->name);
            }
        }
        return $resultFailures;
    }

    /**
     * `requiredTools`: every named tool must have a COMPLETED call somewhere in the tree — `tool` proves the end of a
     * flow, this proves that its stages were walked.
     *
     * @return string[]
     */
    public function buildRequiredToolFailures(AIConversationMessages $messages): array
    {
        if (!isset($this->expectBlock['requiredTools'])) {
            return [];
        }
        $completedToolNames = [];
        foreach ($messages->buildToolCallHistory()->getToolCallRows() as $toolCallRow) {
            if ($toolCallRow->toolCall?->status === AIConversationMessage::STATUS_COMPLETED) {
                $completedToolNames[] = (string)$toolCallRow->toolCall->name;
            }
        }
        $requiredToolFailures = [];
        foreach ($this->getRequiredToolNames() as $requiredToolName) {
            if (!in_array($requiredToolName, $completedToolNames, true)) {
                $requiredToolFailures[] = sprintf(
                    "required tool not completed: '%s' must have a COMPLETED call, completed were [%s]",
                    $requiredToolName,
                    implode(', ', array_unique($completedToolNames))
                );
            }
        }
        return $requiredToolFailures;
    }

    /**
     * `forbiddenTools`: the case fails for every named tool that was called in the tree, whatever `tool` says — the
     * boundary assertion of a request that must not be routed to that tool.
     *
     * @return string[]
     */
    public function buildForbiddenToolFailures(AIConversationMessages $messages): array
    {
        if (!isset($this->expectBlock['forbiddenTools'])) {
            return [];
        }
        $calledToolNames = $messages->buildToolCallHistory()->getCalledToolNames();
        $forbiddenToolFailures = [];
        foreach ($this->getStringListOfKey('forbiddenTools') as $forbiddenToolName) {
            if (in_array($forbiddenToolName, $calledToolNames, true)) {
                $forbiddenToolFailures[] = sprintf(
                    "forbidden tool called: '%s' must NOT be called, but it was (called [%s])",
                    $forbiddenToolName,
                    implode(', ', $calledToolNames)
                );
            }
        }
        return $forbiddenToolFailures;
    }

    /**
     * `taskPlan`: structural assertions on the plan the agent worked on last (the newest plan, not the active slot: a
     * finished plan is retired at the accepted final answer). Keys: `created` (default true: a plan must exist),
     * `minItems` (node count of the tree ≥), `maxOpenItems` (open count of the tree ≤), `hasSubtasks` (the tree is
     * nested), `hasBlockedWithReason`, `hasRequiresUserAction`, `blockedRequiringUserActionItemTextContains`,
     * `hasCompletionNotes` (see {@see buildTaskPlanItemFailures()}) and `tickedViaSetTaskStatus` (at least one
     * `set_task_status` call that changed the plan).
     *
     * @return string[]
     */
    public function buildTaskPlanFailures(AIConversation $conversation, AIConversationMessages $messages): array
    {
        if (!isset($this->expectBlock['taskPlan']) || !is_array($this->expectBlock['taskPlan'])) {
            return [];
        }
        $taskPlanExpect = $this->expectBlock['taskPlan'];
        $taskPlanFailures = [];
        $taskPlan = $conversation->getAgentWorkingState()?->getNewestTaskPlan();
        if ((bool)($taskPlanExpect['created'] ?? true) && $taskPlan === null) {
            $taskPlanFailures[] = 'taskPlan: expected the agent to create a task plan (update_task_plan), but none exists';
        }
        if ($taskPlan !== null && $taskPlan->items !== null) {
            $taskPlanFailures = array_merge($taskPlanFailures, $this->buildTaskPlanItemFailures($taskPlan, $taskPlanExpect));
        }
        if ((bool)($taskPlanExpect['tickedViaSetTaskStatus'] ?? false) && !$this->hasSetTaskStatusCallThatChangedThePlan($messages)) {
            $taskPlanFailures[] = 'taskPlan: expected progress to be ticked via set_task_status (COMPLETED), found no such call';
        }
        return $taskPlanFailures;
    }

    /**
     * The assertions of `taskPlan` on the items of an existing plan. The tree predicates are the plan items' own
     * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems}), so the evaluation reads the same definitions
     * as the loop: `hasBlockedWithReason` (a blocked item with a reason), `hasRequiresUserAction` (a blocked item only
     * the owner can unblock), `blockedRequiringUserActionItemTextContains` (that item, named by a word of its text),
     * `hasCompletionNotes` (every done leaf states what it produced; containers derive their state).
     *
     * @param array<string, mixed> $taskPlanExpect the `taskPlan` map of the block
     * @return string[]
     */
    public function buildTaskPlanItemFailures(TaskPlan $taskPlan, array $taskPlanExpect): array
    {
        $taskPlanItemFailures = [];
        $totalItemCount = $taskPlan->items->countAllItems();
        $statusCounts = $taskPlan->items->countItemsByStatus();
        if (isset($taskPlanExpect['minItems']) && $totalItemCount < (int)$taskPlanExpect['minItems']) {
            $taskPlanItemFailures[] = sprintf('taskPlan: expected ≥%d items, got %d', (int)$taskPlanExpect['minItems'], $totalItemCount);
        }
        if (isset($taskPlanExpect['maxOpenItems']) && $statusCounts->open > (int)$taskPlanExpect['maxOpenItems']) {
            $taskPlanItemFailures[] = sprintf(
                'taskPlan: expected ≤%d OPEN items at the end (finish or block every item), got %d open',
                (int)$taskPlanExpect['maxOpenItems'],
                $statusCounts->open
            );
        }
        if ((bool)($taskPlanExpect['hasSubtasks'] ?? false) && $taskPlan->items->count() >= $totalItemCount) {
            $taskPlanItemFailures[] = 'taskPlan: expected a NESTED plan (subtasks), got a flat item list';
        }
        if ((bool)($taskPlanExpect['hasBlockedWithReason'] ?? false) && !$taskPlan->items->hasBlockedItemWithReason()) {
            $taskPlanItemFailures[] = 'taskPlan: expected ≥1 BLOCKED item with a concrete blockReason, found none';
        }
        if ((bool)($taskPlanExpect['hasRequiresUserAction'] ?? false) && !$taskPlan->items->hasBlockedItemRequiringUserAction()) {
            $taskPlanItemFailures[] = 'taskPlan: expected ≥1 BLOCKED item flagged requiresUserAction, found none';
        }
        $blockedItemTextNeedle = (string)($taskPlanExpect['blockedRequiringUserActionItemTextContains'] ?? '');
        if ($blockedItemTextNeedle !== '' && !$taskPlan->items->hasBlockedItemRequiringUserActionWithTextContaining($blockedItemTextNeedle)) {
            $taskPlanItemFailures[] = sprintf("taskPlan: expected the item naming '%s' BLOCKED with requiresUserAction, found none", $blockedItemTextNeedle);
        }
        if ((bool)($taskPlanExpect['hasCompletionNotes'] ?? false)) {
            $doneLeafTextWithoutNote = $taskPlan->items->getDoneLeafTextWithoutStatusNote();
            if ($doneLeafTextWithoutNote !== null) {
                $taskPlanItemFailures[] = sprintf("taskPlan: done item '%s' carries no statusNote (state what was produced)", $doneLeafTextWithoutNote);
            }
        }
        return $taskPlanItemFailures;
    }

    /** Whether the tree holds a COMPLETED `set_task_status` call that changed the plan (a "no change needed" answer is no tick). */
    public function hasSetTaskStatusCallThatChangedThePlan(AIConversationMessages $messages): bool
    {
        foreach ($messages->buildToolCallHistory()->getToolCallRows() as $toolCallRow) {
            if ((string)$toolCallRow->toolCall->name === SetTaskStatusTool::NAME
                && $toolCallRow->toolCall->status === AIConversationMessage::STATUS_COMPLETED
                && !str_starts_with((string)($toolCallRow->toolCall->result ?? ''), SetTaskStatusTool::RESULT_PREFIX_NO_CHANGE_NEEDED)
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * `emitsWidget`, `emitsAnyWidgetOf` and `forbidsWidget` on the final answer, in this order. A block counts only when
     * it is valid ({@see WidgetBlocks}), in any fence syntax, with or without the `Directive` suffix of its type.
     *
     * @return string[]
     */
    public function buildAnswerWidgetFailures(AIConversation $conversation): array
    {
        $finalAnswerText = (string)($conversation->getLastAssistantText() ?? '');
        return array_merge(
            $this->buildEmitsWidgetFailures($finalAnswerText),
            $this->buildEmitsAnyWidgetOfFailures($finalAnswerText),
            $this->buildForbidsWidgetFailures($finalAnswerText)
        );
    }

    /**
     * `emitsWidget`: the final answer must carry a valid block of every listed type — a malformed block fails too.
     *
     * @return string[]
     */
    public function buildEmitsWidgetFailures(string $finalAnswerText): array
    {
        if (!isset($this->expectBlock['emitsWidget'])) {
            return [];
        }
        $emitsWidgetFailures = [];
        $finalAnswerWidgetBlocks = WidgetBlocks::fromMarkdown($finalAnswerText);
        foreach ($this->getStringListOfKey('emitsWidget') as $expectedWidgetType) {
            if ($finalAnswerWidgetBlocks->findFirstBlockOfTypes($expectedWidgetType) === null) {
                $emitsWidgetFailures[] = sprintf(
                    "widget not emitted: expected a ```widget block with type '%s' in the final answer",
                    $expectedWidgetType
                );
            }
        }
        return $emitsWidgetFailures;
    }

    /**
     * `emitsAnyWidgetOf`: the final answer must carry a valid block of at least one of the listed types — for a
     * question several presentations answer correctly, where `emitsWidget` would demand all of them.
     *
     * @return string[]
     */
    public function buildEmitsAnyWidgetOfFailures(string $finalAnswerText): array
    {
        if (!isset($this->expectBlock['emitsAnyWidgetOf'])) {
            return [];
        }
        $acceptedWidgetTypes = $this->getStringListOfKey('emitsAnyWidgetOf');
        $finalAnswerWidgetBlocks = WidgetBlocks::fromMarkdown($finalAnswerText);
        if ($acceptedWidgetTypes === [] || $finalAnswerWidgetBlocks->findFirstBlockOfTypes(...$acceptedWidgetTypes) !== null) {
            return [];
        }
        return [sprintf(
            'widget not emitted: expected a widget block of one of the types [%s] in the final answer, found [%s]',
            implode(', ', $acceptedWidgetTypes),
            implode(', ', $finalAnswerWidgetBlocks->getWrittenWidgetTypes())
        )];
    }

    /**
     * `forbidsWidget`: `true` fails when the final answer carries any widget block, valid or not (the answer must stay
     * prose); a name or a list fails only for a valid block of that type. `false` checks nothing.
     *
     * @return string[]
     */
    public function buildForbidsWidgetFailures(string $finalAnswerText): array
    {
        if (!isset($this->expectBlock['forbidsWidget']) || $this->expectBlock['forbidsWidget'] === false) {
            return [];
        }
        if ($this->expectBlock['forbidsWidget'] === true) {
            if (WidgetBlocks::fromMarkdown($finalAnswerText)->count() > 0) {
                return ['widget emitted but none expected: the answer should stay prose '
                    . '(a summary / synthesis / narrow field lookup), yet it carries a widget block'];
            }
            return [];
        }
        $forbidsWidgetFailures = [];
        $finalAnswerWidgetBlocks = WidgetBlocks::fromMarkdown($finalAnswerText);
        foreach ($this->getStringListOfKey('forbidsWidget') as $forbiddenWidgetType) {
            if ($finalAnswerWidgetBlocks->findFirstBlockOfTypes($forbiddenWidgetType) !== null) {
                $forbidsWidgetFailures[] = sprintf(
                    "widget emitted but forbidden: the answer must not carry the '%s' widget here",
                    $forbiddenWidgetType
                );
            }
        }
        return $forbidsWidgetFailures;
    }

    /**
     * `emitsProgressText`: the earliest assistant text with content must come before the earliest tool call (by
     * index in the conversation), so the owner sees activity instead of a silent spinner. A tree without a tool call
     * meets it.
     *
     * @return string[]
     */
    public function buildProgressTextFailures(AIConversationMessages $messages): array
    {
        if (!isset($this->expectBlock['emitsProgressText']) || !$this->expectBlock['emitsProgressText']) {
            return [];
        }
        $firstToolCallIndex = PHP_INT_MAX;
        $firstAssistantTextIndex = PHP_INT_MAX;
        foreach ($messages->getElements() as $message) {
            $indexInConversation = $message->indexInConversation ?? PHP_INT_MAX;
            if ($message->messageType === AIConversationMessage::MESSAGE_TOOL_CALL) {
                $firstToolCallIndex = min($firstToolCallIndex, $indexInConversation);
            } elseif ($message->isAssistantAuthoredText() && trim((string)($message->message ?? '')) !== '') {
                $firstAssistantTextIndex = min($firstAssistantTextIndex, $indexInConversation);
            }
        }
        if ($firstToolCallIndex === PHP_INT_MAX || $firstAssistantTextIndex < $firstToolCallIndex) {
            return [];
        }
        return ['no progress preamble: expected a user-facing assistant text BEFORE the first tool call '
            . '(the "I\'m fetching X now …" line that spares the user a silent spinner)'];
    }

    /**
     * `emitsToolMessage` / `forbidsToolMessage`: a tool message anywhere in the tree with a valid widget block of the
     * named type must / must not exist — the record of what a tool did, independent of the model's answer.
     *
     * @return string[]
     */
    public function buildToolMessageWidgetFailures(AIConversationMessages $messages): array
    {
        $toolMessageTexts = $this->getToolMessageTexts($messages);
        $toolMessageWidgetFailures = [];
        if (isset($this->expectBlock['emitsToolMessage'])) {
            foreach ($this->getStringListOfKey('emitsToolMessage') as $expectedWidgetType) {
                if (!$this->toolMessageTextsCarryWidget($toolMessageTexts, $expectedWidgetType)) {
                    $toolMessageWidgetFailures[] = sprintf("tool message not emitted: expected a TOOL_MESSAGE widget of type '%s' somewhere in the tree", $expectedWidgetType);
                }
            }
        }
        if (isset($this->expectBlock['forbidsToolMessage'])) {
            foreach ($this->getStringListOfKey('forbidsToolMessage') as $forbiddenWidgetType) {
                if ($this->toolMessageTextsCarryWidget($toolMessageTexts, $forbiddenWidgetType)) {
                    $toolMessageWidgetFailures[] = sprintf("tool message emitted but forbidden: a TOOL_MESSAGE widget of type '%s' is in the tree", $forbiddenWidgetType);
                }
            }
        }
        return $toolMessageWidgetFailures;
    }

    /**
     * The texts of every tool message of the tree, in message order.
     *
     * @return string[]
     */
    public function getToolMessageTexts(AIConversationMessages $messages): array
    {
        $toolMessageTexts = [];
        foreach ($messages->getElements() as $message) {
            if (($message->messageType ?? null) === AIConversationMessage::MESSAGE_TOOL_MESSAGE) {
                $toolMessageTexts[] = (string)($message->message ?? '');
            }
        }
        return $toolMessageTexts;
    }

    /**
     * Whether one of the tool-message texts carries a valid widget block of the type.
     *
     * @param string[] $toolMessageTexts
     */
    public function toolMessageTextsCarryWidget(array $toolMessageTexts, string $widgetType): bool
    {
        foreach ($toolMessageTexts as $toolMessageText) {
            if (WidgetBlocks::fromMarkdown($toolMessageText)->findFirstBlockOfTypes($widgetType) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * `finalAnswerNotContains`: the final answer must not carry these phrases (case-insensitive) — the honesty check
     * of an answer that claims what did not happen.
     *
     * @return string[]
     */
    public function buildFinalAnswerPhraseFailures(AIConversation $conversation): array
    {
        if (!isset($this->expectBlock['finalAnswerNotContains'])) {
            return [];
        }
        $finalAnswerPhraseFailures = [];
        $finalAnswerText = mb_strtolower((string)($conversation->getLastAssistantText() ?? ''));
        foreach ($this->getStringListOfKey('finalAnswerNotContains') as $forbiddenPhrase) {
            if ($forbiddenPhrase !== '' && str_contains($finalAnswerText, mb_strtolower($forbiddenPhrase))) {
                $finalAnswerPhraseFailures[] = sprintf("finalAnswerNotContains: the final answer says '%s'", $forbiddenPhrase);
            }
        }
        return $finalAnswerPhraseFailures;
    }

    /**
     * `signalSources` {requires, forbids}: which loop signals (by their signal source) must / must not appear in the
     * tree — e.g. a bounce the model should not have needed.
     *
     * @return string[]
     */
    public function buildSignalSourceFailures(AIConversationMessages $messages): array
    {
        if (!isset($this->expectBlock['signalSources']) || !is_array($this->expectBlock['signalSources'])) {
            return [];
        }
        $presentSignalSources = [];
        foreach ($messages->getElements() as $message) {
            if (($message->messageType ?? null) === AIConversationMessage::MESSAGE_SIGNAL && ($message->signalSource ?? null) !== null) {
                $presentSignalSources[(string)$message->signalSource] = true;
            }
        }
        $signalSourceFailures = [];
        foreach (array_map('strval', (array)($this->expectBlock['signalSources']['requires'] ?? [])) as $requiredSignalSource) {
            if (!isset($presentSignalSources[$requiredSignalSource])) {
                $signalSourceFailures[] = sprintf("signalSources: expected a '%s' signal in the tree, found none", $requiredSignalSource);
            }
        }
        foreach (array_map('strval', (array)($this->expectBlock['signalSources']['forbids'] ?? [])) as $forbiddenSignalSource) {
            if (isset($presentSignalSources[$forbiddenSignalSource])) {
                $signalSourceFailures[] = sprintf("signalSources: a '%s' signal fired although the case forbids it", $forbiddenSignalSource);
            }
        }
        return $signalSourceFailures;
    }

    /**
     * `toolCallCountMax`, then `toolCallCountMin` (tool name → bound): how often each tool was called in the tree, any
     * status — e.g. a tool at most twice, never a retry loop.
     *
     * @return string[]
     */
    public function buildToolCallCountFailures(AIConversationMessages $messages): array
    {
        $toolCallCountFailures = [];
        if (isset($this->expectBlock['toolCallCountMax']) && is_array($this->expectBlock['toolCallCountMax'])) {
            foreach ($this->expectBlock['toolCallCountMax'] as $countedToolName => $bound) {
                $callCount = $this->countToolCallsNamed($messages, (string)$countedToolName);
                if ($callCount > (int)$bound) {
                    $toolCallCountFailures[] = sprintf("toolCallCountMax: '%s' was called %d times, at most %d allowed", (string)$countedToolName, $callCount, (int)$bound);
                }
            }
        }
        if (isset($this->expectBlock['toolCallCountMin']) && is_array($this->expectBlock['toolCallCountMin'])) {
            foreach ($this->expectBlock['toolCallCountMin'] as $countedToolName => $bound) {
                $callCount = $this->countToolCallsNamed($messages, (string)$countedToolName);
                if ($callCount < (int)$bound) {
                    $toolCallCountFailures[] = sprintf("toolCallCountMin: '%s' was called %d times, at least %d expected", (string)$countedToolName, $callCount, (int)$bound);
                }
            }
        }
        return $toolCallCountFailures;
    }

    /** How many tool-call messages of the tree call the tool, whatever their status. */
    public function countToolCallsNamed(AIConversationMessages $messages, string $toolName): int
    {
        $callCount = 0;
        foreach ($messages->getElements() as $message) {
            if (($message->messageType ?? null) === AIConversationMessage::MESSAGE_TOOL_CALL && ($message->toolCall->name ?? null) === $toolName) {
                $callCount++;
            }
        }
        return $callCount;
    }

    /**
     * The arguments of a tool call as JSON with unescaped characters: the substring checks read the arguments as the
     * case author wrote them — a needle with a non-ASCII letter never matches the `\u` escape of the stored JSON.
     *
     * An argument the platform fills when the model leaves it out is added under its name when the stored arguments
     * lack that name; an argument the model passed itself is never replaced.
     *
     * @param array<string, int|string> $toolArgumentsFilledByThePlatform see {@see evaluateConversation()}
     */
    public function buildToolCallArgumentsAsUnescapedJson(AIConversationMessage $toolCallRow, array $toolArgumentsFilledByThePlatform = []): string
    {
        $decodedArguments = json_decode($toolCallRow->toolCall->argumentsAsJson(), true);
        if (!is_array($decodedArguments)) {
            return $toolCallRow->toolCall->argumentsAsJson();
        }
        foreach ($toolArgumentsFilledByThePlatform as $argumentName => $argumentValue) {
            if (!array_key_exists($argumentName, $decodedArguments)) {
                $decodedArguments[$argumentName] = $argumentValue;
            }
        }
        return json_encode($decodedArguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $toolCallRow->toolCall->argumentsAsJson();
    }

    /**
     * The value of the argument `$argumentKey` in the unescaped JSON `$argumentsAsJson`: a string as it is, any other
     * value as its unescaped JSON; a dotted key reaches into objects when no argument carries the whole key; '' for an
     * argument the call does not carry.
     */
    public function buildArgumentValueAsUnescapedText(string $argumentsAsJson, string $argumentKey): string
    {
        $decodedArguments = json_decode($argumentsAsJson, true);
        if (!is_array($decodedArguments)) {
            return '';
        }
        if (array_key_exists($argumentKey, $decodedArguments)) {
            $argumentValue = $decodedArguments[$argumentKey];
        } else {
            $argumentValue = $decodedArguments;
            foreach (explode('.', $argumentKey) as $keySegment) {
                if (!is_array($argumentValue) || !array_key_exists($keySegment, $argumentValue)) {
                    return '';
                }
                $argumentValue = $argumentValue[$keySegment];
            }
        }
        if (is_string($argumentValue)) {
            return $argumentValue;
        }
        return (string)json_encode($argumentValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The checked call's outcome as unescaped text: for a `delegate` call its spawn answer and the sub-agent's run ends
     * ({@see AIConversationMessages::buildDelegateCallOutcomeText()}); for any other call the words of its result
     * (`content[].text`, or a plain result) and its `structuredContent` as unescaped JSON, never the stored MCP envelope
     * with its JSON escapes.
     */
    public function buildCallOutcomeAsUnescapedText(AIConversationMessages $messages, AIConversationMessage $toolCallRow): string
    {
        if ($toolCallRow->isDelegationCall() || !isset($toolCallRow->toolCall)) {
            return $messages->buildDelegateCallOutcomeText($toolCallRow);
        }
        $decodedResult = $toolCallRow->toolCall->getDecodedResult();
        if ($decodedResult->structuredContent === null || $decodedResult->structuredContent === []) {
            return $decodedResult->contentText;
        }
        return trim($decodedResult->contentText . "\n" . (string)json_encode($decodedResult->structuredContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Whether the evaluation tool block of the conversation refuses the tool: the case blocks it, or the run may only
     * read and the tool changes data. Such a call ends FAILED whatever the agent does.
     */
    public function evaluationRunRefusesTool(AIConversation $conversation, string $toolName): bool
    {
        return isset($conversation->runSettings) && $conversation->runSettings->describeEvalToolBlock($toolName) !== null;
    }

    /**
     * The value of a key that holds a string or a list of strings, as a list. Empty when the key is absent.
     *
     * @return string[]
     */
    protected function getStringListOfKey(string $key): array
    {
        if (!isset($this->expectBlock[$key])) {
            return [];
        }
        return is_array($this->expectBlock[$key])
            ? array_values(array_map('strval', $this->expectBlock[$key]))
            : [(string)$this->expectBlock[$key]];
    }
}
