<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What a suite run plans for: the models and flavors every case runs in, which model can see images, whether cases of
 * the job queue run inline, the skill and case filters, and the agent space for cases that name none. Read by
 * {@see \DDD\Domain\AI\Services\Eval\AgentEvalSuitePlanService} when it turns fixtures into tasks.
 *
 * An application that plans kinds of its own with options of its own declares them on its same-path subclass; the
 * planning creates the options with {@see newInstance()}.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalSuitePlanOptions extends ValueObject
{
    /** @var array The agent models under test, in order: a model name, or null for the conversation default */
    public array $modelNames = [];

    /** @var string[] The flavors ({@see AgentEvalSuiteTask::FLAVOR_CALL}, {@see AgentEvalSuiteTask::FLAVOR_JUDGE}) */
    public array $flavors = [];

    /** @var bool[] Model name → whether it can see images; a case that requires vision is dropped on any other model */
    public array $visionCapabilityByModelName = [];

    /** @var bool Whether cases whose flow exists only on the job queue are planned too (debugging only) */
    public bool $includeAsyncOnlyCases = false;

    /** @var string|null Only the tools-style fixtures whose skill or file name is this */
    public ?string $skillFilter = null;

    /** @var string|null Only the case with this id, or the cases of this comma-separated list of ids */
    public ?string $caseFilter = null;

    /** @var int|null The agent space of the cases that name none; null = such a case is not planned */
    public ?int $fallbackAgentSpaceId = null;

    /**
     * @var int|null The account a run names (`--accountId`): a case that names no space and has no fallback space is
     * planned at account level for it ({@see AgentEvalSuiteTask::$accountId}); null = such a case is not planned
     */
    public ?int $fallbackAccountId = null;

    /**
     * Options for the models and flavors of a run.
     *
     * @param array $modelNames model names; null = the conversation default
     * @param string[] $flavors
     * @return static
     */
    public static function forModelsAndFlavors(array $modelNames, array $flavors): static
    {
        $agentEvalSuitePlanOptions = static::newInstance();
        $agentEvalSuitePlanOptions->modelNames = array_values($modelNames);
        $agentEvalSuitePlanOptions->flavors = array_values($flavors);
        return $agentEvalSuitePlanOptions;
    }

    /** Whether the flavor `$flavor` is planned. */
    public function hasFlavor(string $flavor): bool
    {
        return in_array($flavor, $this->flavors, true);
    }

    /** Whether the model `$modelName` can see images (an unknown model and the default model cannot). */
    public function modelCanSeeImages(?string $modelName): bool
    {
        return $this->visionCapabilityByModelName[(string)$modelName] ?? false;
    }

    /**
     * Whether a case belongs to the run: no filter, or its id is one of the comma-separated ids of the filter — a batch
     * of named cases runs as one suite run, so its write cases share the lanes.
     */
    public function matchesCaseFilter(string $caseId): bool
    {
        if ($this->caseFilter === null || trim($this->caseFilter) === '') {
            return true;
        }
        return in_array($caseId, array_map('trim', explode(',', $this->caseFilter)), true);
    }

    /** Whether a fixture belongs to the run: no skill filter, or its skill or its file name is the filter. */
    public function matchesSkillFilter(AgentEvalFixture $agentEvalFixture): bool
    {
        return $this->skillFilter === null
            || $agentEvalFixture->getSkillName() === $this->skillFilter
            || $agentEvalFixture->getName() === $this->skillFilter;
    }
}
