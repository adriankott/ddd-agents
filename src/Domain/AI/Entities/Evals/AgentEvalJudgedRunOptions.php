<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

/**
 * What a caller sets for one judged run: everything of {@see AgentEvalRunOptions}, plus the model that plays the owner
 * (the driver) and the model that reads the trace and returns the verdict (the judge). A null model name means the
 * judged-run service's default.
 *
 * Built like its parent: `AgentEvalJudgedRunOptions::forArms($agentEvalArms)` gives an object of this class.
 *
 * A plain object of one run; no container service.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalJudgedRunOptions extends AgentEvalRunOptions
{
    /** @var string|null The model that plays the owner; null = the judged-run service's default */
    public ?string $driverModelName = null;

    /** @var string|null The model that returns the verdict; null = the judged-run service's default */
    public ?string $judgeModelName = null;

    /** The driver model of the run: the one set, else `$defaultDriverModelName`. */
    public function resolveDriverModelName(string $defaultDriverModelName): string
    {
        return $this->driverModelName ?? $defaultDriverModelName;
    }

    /** The judge model of the run: the one set, else `$defaultJudgeModelName`. */
    public function resolveJudgeModelName(string $defaultJudgeModelName): string
    {
        return $this->judgeModelName ?? $defaultJudgeModelName;
    }
}
