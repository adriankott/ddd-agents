<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * What an opened restore bracket of an evaluation case holds until it is closed: the restore plan of the case's
 * `restore` block, the values the capture read took before the agent ran, the removals of what the preparation steps
 * created, the provenance and the tool packages every harness call runs with, the arguments the platform fills into
 * each harness call, and the time the harness calls started (what the harness calls left held since then is released
 * when the bracket closes).
 *
 * The runner opens the bracket (preparation, capture), runs the case, and closes it in a `finally` (revert write,
 * `after` steps, removals in reverse order, release). A plain holder without behaviour; no container service.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalRestoreBracket
{
    /** @var AgentEvalRestorePlan The plan of the case's `restore` block */
    public AgentEvalRestorePlan $agentEvalRestorePlan;

    /** @var array<string, mixed> The values the capture read took, by argument of the revert write; empty when nothing was captured */
    public array $capturedValues = [];

    /** @var AgentEvalHarnessToolCalls The removals of what the preparation steps created, in the order it was created */
    public AgentEvalHarnessToolCalls $undoCalls;

    /** @var AgentToolCallProvenance The provenance of every harness call of the bracket */
    public AgentToolCallProvenance $harnessToolCallProvenance;

    /** @var string[] The tool package classes the executor searches for every harness call */
    public array $packageClasses = [];

    /** @var array<string, mixed> The arguments the platform fills into every harness call */
    public array $argumentsFilledByThePlatform = [];

    /** @var DateTime When the harness calls of the bracket started */
    public DateTime $harnessCallsStartedAt;

    /**
     * A bracket about to be opened: nothing captured yet, no removal recorded yet.
     *
     * @param AgentEvalRestorePlan $agentEvalRestorePlan
     * @param AgentToolCallProvenance $harnessToolCallProvenance
     * @param string[] $packageClasses
     * @param array<string, mixed> $argumentsFilledByThePlatform
     * @param DateTime $harnessCallsStartedAt
     * @return static
     */
    public static function forRestorePlan(
        AgentEvalRestorePlan $agentEvalRestorePlan,
        AgentToolCallProvenance $harnessToolCallProvenance,
        array $packageClasses,
        array $argumentsFilledByThePlatform,
        DateTime $harnessCallsStartedAt
    ): static {
        $agentEvalRestoreBracket = new static();
        $agentEvalRestoreBracket->agentEvalRestorePlan = $agentEvalRestorePlan;
        $agentEvalRestoreBracket->undoCalls = AgentEvalHarnessToolCalls::newInstance();
        $agentEvalRestoreBracket->harnessToolCallProvenance = $harnessToolCallProvenance;
        $agentEvalRestoreBracket->packageClasses = $packageClasses;
        $agentEvalRestoreBracket->argumentsFilledByThePlatform = $argumentsFilledByThePlatform;
        $agentEvalRestoreBracket->harnessCallsStartedAt = $harnessCallsStartedAt;
        return $agentEvalRestoreBracket;
    }
}
