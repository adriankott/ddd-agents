<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One unit of work of a parallel evaluation suite: one case driven on one model in one flavor, as its own child
 * process. The schedule ({@see AgentEvalSuiteSchedule}) decides from {@see $access}, the scope
 * ({@see getScopeId()}), {@see $resourceKey}, the wave and the concurrency group alone which tasks may run at the same
 * time.
 *
 * The kind is an open string: the module declares the kinds it plans itself, an application declares its own on its
 * same-path subclass and plans them in its subclass of {@see \DDD\Domain\AI\Services\Eval\AgentEvalSuitePlanService}.
 *
 * The scope is the agent space the case works on ({@see $agentSpaceId}). An application whose fixtures name another
 * scope keeps that value on its subclass and returns it from {@see getScopeId()}: the schedule never reads
 * {@see $agentSpaceId} directly.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalSuiteTask extends ValueObject
{
    /** @var string A case of a tools-style fixture, driven by the tools evaluation */
    public const string KIND_TOOLS_CASE = 'toolsCase';

    /** @var string A skill-selection case, driven by the selection evaluation */
    public const string KIND_SELECTION_CASE = 'selectionCase';

    /** @var string A cross-conversation recall case (it seeds conversations on its agent space) */
    public const string KIND_RECALL_CASE = 'recallCase';

    /** @var string The deterministic flavor: one run, checked against the case's expectations */
    public const string FLAVOR_CALL = 'call';

    /** @var string The judged flavor: a simulated owner drives the case, a judge gives the verdict */
    public const string FLAVOR_JUDGE = 'judge';

    /** @var string Touches no shared live state (its own throwaway scope): runs whenever a process slot is free */
    public const string ACCESS_NONE = 'none';

    /** @var string Reads a live scope: runs beside other reads, never beside a write on the resource it reads */
    public const string ACCESS_READ = 'read';

    /** @var string Changes one resource of a live scope and restores it: runs beside writes on other resources of that scope */
    public const string ACCESS_WRITE = 'write';

    /** @var string Changes state other cases depend on (several resources at once, a shared grant): alone on its scope */
    public const string ACCESS_EXCLUSIVE = 'exclusive';

    /** @var string Between a task id and the repetition of a repeated case: `<task id>#<repetition>` */
    public const string REPETITION_SEPARATOR = '#';

    /**
     * @var string Unique within a suite run: `<model>|<flavor>|<suite>|<caseId>`, for a run of a repeated case
     * followed by `#<repetition>` ({@see buildRepetition()}).
     */
    public string $taskId = '';

    /** @var string The kind of the task: one of the `KIND_*` constants or an application's own kind. */
    public string $kind = self::KIND_TOOLS_CASE;

    /** @var string The suite the case belongs to. */
    public string $suite = '';

    /** @var string The fixture case id. */
    public string $caseId = '';

    /** @var string Absolute path of the fixture file the child process resolves the case in. */
    public string $fixturePath = '';

    /** @var string|null The agent model under test; null = the conversation default. */
    public ?string $modelName = null;

    /** @var string One of the `FLAVOR_*` constants. */
    public string $flavor = self::FLAVOR_CALL;

    /** @var int|null The agent space the case works on; null when the task names none. */
    public ?int $agentSpaceId = null;

    /**
     * @var int|null The account a task without a space works for at account level (the run's `--accountId`); null when
     * the task works in a space or names no scope.
     */
    public ?int $accountId = null;

    /** @var string One of the `ACCESS_*` constants. */
    public string $access = self::ACCESS_READ;

    /** @var string The resource group the task reads or changes: a write never overlaps a read or a write with the same key on its scope. */
    public string $resourceKey = '';

    /**
     * @var string Tasks of one wave group start in waves: a task starts only when every task of an earlier wave of
     * its group has finished. For cases that reset shared state at their start, which must not happen while a sibling
     * on the same state is in the middle of its run. Empty = no wave.
     */
    public string $waveGroup = '';

    /** @var int The task's wave inside {@see $waveGroup}, 0-based. */
    public int $waveIndex = 0;

    /**
     * @var string Tasks of one concurrency group never run more than {@see $concurrencyLimit} at a time (e.g. cases
     * that each lease one item of a pool of the same size). Empty = no group.
     */
    public string $concurrencyGroup = '';

    /** @var int The most tasks of {@see $concurrencyGroup} that run at the same time. */
    public int $concurrencyLimit = 0;

    /** @var int Wall-clock ceiling of the child process. */
    public int $timeoutSeconds = 600;

    /**
     * @var float The prior of the task's duration in seconds, which the planning sets per kind and flavor; the
     * schedule's wall-time estimate reads it. 60 for a task the planning did not estimate.
     */
    public float $estimatedSeconds = 60.0;

    /**
     * @var int Which run of a repeated case the task is, 1-based (its case file is `<caseId>.rep<k>.json`, its row
     * carries `repetition`); 0 for a case that runs once.
     */
    public int $repetition = 0;

    /**
     * The task of run `$repetition` (1-based) of this case: a copy with the repetition and the task id
     * `<task id>#<repetition>`, so the runs of one case are told apart by the schedule. Nothing else changes: the runs
     * of one case share the scope, the resource and the run folder.
     */
    public function buildRepetition(int $repetition): static
    {
        $repeatedTask = clone $this;
        $repeatedTask->repetition = $repetition;
        $repeatedTask->taskId = $this->taskId . static::REPETITION_SEPARATOR . $repetition;
        return $repeatedTask;
    }

    /** Whether the task is one run of a repeated case. */
    public function isRepetition(): bool
    {
        return $this->repetition > 0;
    }

    /** The run this task belongs to: one result folder and one summary per run. */
    public function getRunKey(): string
    {
        return ($this->modelName ?? 'default') . '|' . $this->flavor;
    }

    /** Whether the task changes live state ({@see ACCESS_WRITE} or {@see ACCESS_EXCLUSIVE}). */
    public function writesLiveState(): bool
    {
        return $this->access === static::ACCESS_WRITE || $this->access === static::ACCESS_EXCLUSIVE;
    }

    /**
     * The scope the task shares live state in: two tasks with the same scope id are scheduled against each other.
     * Null for a task without a scope (it shares nothing). The module's scope is the agent space; a task at account
     * level ({@see $accountId}) has the scope id of its account negated, so it never meets a space's id.
     */
    public function getScopeId(): ?int
    {
        if ($this->agentSpaceId !== null) {
            return $this->agentSpaceId;
        }
        return $this->accountId !== null ? -$this->accountId : null;
    }

    /**
     * The lane of the task as a suite prints and stores it: `<scope>:<resource>`.
     */
    public function getLane(): string
    {
        return $this->getScopeId() . ':' . $this->resourceKey;
    }

    /**
     * The lane a writing task waits in: its scope and, for a plain write, its resource (`*` for an exclusive task).
     */
    public function getWriteLaneKey(): string
    {
        return $this->getScopeId() . ':' . ($this->access === static::ACCESS_EXCLUSIVE ? '*' : $this->resourceKey);
    }

    /**
     * What a read and the write that excludes it share: the scope and, unless the whole scope is isolated, the
     * resource.
     */
    public function getReadKey(bool $isolateWholeScope): string
    {
        return $this->getScopeId() . ':' . ($isolateWholeScope ? '*' : $this->resourceKey);
    }

    /**
     * The folder a run's files are written to, `<model>-<flavor>`, with every character other than letters, digits,
     * dots, underscores and dashes replaced by `_`.
     */
    public function getRunFolderName(): string
    {
        return $this->sanitizeForFolderName($this->modelName ?? 'default') . '-' . $this->flavor;
    }

    /** A text safe for a file or folder name segment (letters, digits, `.`, `_`, `-`; `x` for an empty result). */
    public function sanitizeForFolderName(string $value): string
    {
        $safeValue = preg_replace('/[^A-Za-z0-9._-]+/', '_', $value);
        return $safeValue !== null && $safeValue !== '' ? $safeValue : 'x';
    }
}
