<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationAgentTask;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationTask;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationToolRunTask;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The arguments of ONE `delegate` call: an optional `label` and ONE task — an agent task
 * (`task` + `skill_names`) or a tool run (`toolName` + `toolArguments`, the arguments as an object or a JSON string).
 * A call that names `toolName` is a tool run, whatever else it carries; a call with neither shape is no task and is
 * refused with {@see buildTaskShapeCorrective()}. A call whose `parallelTasks` holds two or more items starts one
 * sub-agent per item ({@see $parallelTaskArguments}, each item read like a call of its own; the top-level task fields
 * are then not read); a single item is read as the call itself. The name is not `tasks`: rows stored before one
 * sub-agent per call carry `tasks[]`, and their readers count its items as the row's sub-agents. Fields the model has no
 * say in (whether the run end resumes the parent) are not read. Old rows carry `tasks[]`, read by
 * {@see fromLegacyArguments()}. Pure parse.
 *
 * @phpstan-consistent-constructor
 */
class DelegateToolArguments extends ValueObject
{
    /**
     * The tail of a rejection whose call named no `toolName`: such a delegate often stands for a widget the model writes
     * itself. A call that named a tool needs that tool, so its rejection carries no tail.
     */
    public const string DIRECT_WIDGET_TAIL = 'A widget you write directly (the weather, the time, a draft) needs no sub-agent: '
        . 'answer now with one line, then its block.';

    /** The model's label for the sub-agent, cut to a label; null = none given. */
    public ?string $label = null;

    /** The one task; null when the call names neither task shape. */
    public ?AgentDelegationTask $task = null;

    /** The call names an agent task AND a tool run: the tool run is its task, the `task` text is not read. */
    public bool $namesBothTaskShapes = false;

    /**
     * The items of `parallelTasks` when it holds two or more — one sub-agent per item; null otherwise (then
     * {@see $label} and {@see $task} are the call's one sub-agent).
     */
    public ?DelegateToolParallelTaskArguments $parallelTaskArguments = null;

    /** The number of this item in its call's `parallelTasks` (from 1); null for the call itself. */
    public ?int $parallelTaskItemNumber = null;

    /** The argument that carries several tasks of one call. */
    public const string PARALLEL_TASKS_ARGUMENT = 'parallelTasks';

    /** @param array<string, mixed> $arguments the call's parsed arguments */
    public static function fromToolCallArguments(array $arguments): static
    {
        $rawParallelTasks = $arguments[self::PARALLEL_TASKS_ARGUMENT] ?? null;
        if (is_array($rawParallelTasks) && array_is_list($rawParallelTasks) && $rawParallelTasks !== []) {
            if (count($rawParallelTasks) === 1) {
                // one item is the call itself
                return static::fromToolCallArguments(is_array($rawParallelTasks[0]) ? $rawParallelTasks[0] : []);
            }
            $delegateToolArguments = new static();
            $delegateToolArguments->parallelTaskArguments = new DelegateToolParallelTaskArguments();
            foreach ($rawParallelTasks as $rawParallelTask) {
                // an item is read like a call of its own; a nested `parallelTasks` in an item is not read
                $itemArguments = is_array($rawParallelTask) ? $rawParallelTask : [];
                unset($itemArguments[self::PARALLEL_TASKS_ARGUMENT]);
                $itemDelegateToolArguments = static::fromToolCallArguments($itemArguments);
                $itemDelegateToolArguments->parallelTaskItemNumber = $delegateToolArguments->parallelTaskArguments->count() + 1;
                $delegateToolArguments->parallelTaskArguments->add($itemDelegateToolArguments);
            }
            return $delegateToolArguments;
        }
        $delegateToolArguments = new static();
        $label = $arguments['label'] ?? null;
        if (is_string($label) && trim($label) !== '') {
            $delegateToolArguments->label = AgentDelegationTask::cutToLabel($label);
        }
        $delegateToolArguments->task = AgentDelegationTask::fromArgumentItem($arguments);
        $delegateToolArguments->namesBothTaskShapes = is_string($arguments['task'] ?? null) && trim($arguments['task']) !== ''
            && AgentDelegationToolRunTask::readToolNameFromArgumentItem($arguments) !== null;
        return $delegateToolArguments;
    }

    /**
     * The `tasks[]` of a call stored before a call carried ONE task (and of today's model calls): every item with a non-blank `task`
     * is an agent task, everything else is skipped ({@see AgentDelegationAgentTask::fromLegacyArgumentItem()}).
     *
     * @param array<string, mixed> $arguments
     * @return AgentDelegationAgentTask[]
     */
    public static function fromLegacyArguments(array $arguments): array
    {
        $rawTasks = $arguments['tasks'] ?? null;
        if (!is_array($rawTasks)) {
            return [];
        }
        $agentDelegationAgentTasks = [];
        foreach ($rawTasks as $rawTask) {
            $agentDelegationAgentTask = AgentDelegationAgentTask::fromLegacyArgumentItem($rawTask);
            if ($agentDelegationAgentTask !== null) {
                $agentDelegationAgentTasks[] = $agentDelegationAgentTask;
            }
        }
        return $agentDelegationAgentTasks;
    }

    public function hasTask(): bool
    {
        return $this->task !== null;
    }

    /** Whether an item of the call's `parallelTasks` names a task. */
    public function namesTaskInAParallelTaskItem(): bool
    {
        foreach ($this->parallelTaskArguments?->getElements() ?? [] as $itemDelegateToolArguments) {
            if ($itemDelegateToolArguments->task !== null) {
                return true;
            }
        }
        return false;
    }

    /** Whether the call starts one sub-agent per item of its `parallelTasks`. */
    public function hasParallelTasks(): bool
    {
        return $this->parallelTaskArguments !== null;
    }

    /** The corrective of a call without a task shape; null when the call names a task. */
    public function buildTaskShapeCorrective(): ?string
    {
        if ($this->parallelTaskItemNumber !== null) {
            return $this->task !== null ? null : 'delegate REJECTED — item ' . $this->parallelTaskItemNumber . ' of `parallelTasks` names '
                . 'no task: give it `task` (with `skill_names`) for an agent task, or `toolName` + `toolArguments` for one tool '
                . 'call. This item was not started; the other items of the call are not affected.';
        }
        if ($this->parallelTaskArguments !== null) {
            return $this->namesTaskInAParallelTaskItem() ? null : 'delegate REJECTED — no item of `parallelTasks` names a task: give '
                . 'each item `task` (with `skill_names`) for an agent task, or `toolName` + `toolArguments` for one tool call. '
                . 'Nothing was started. ' . self::DIRECT_WIDGET_TAIL;
        }
        if ($this->task !== null) {
            return null;
        }
        // neither shape: the call named no `toolName` (a `toolName` is a tool run, with or without a `task` text)
        return 'delegate REJECTED — the call names no task: give `task` (with `skill_names`) for an agent task, or '
            . '`toolName` + `toolArguments` for one tool call. Nothing was started. ' . self::DIRECT_WIDGET_TAIL;
    }
}
