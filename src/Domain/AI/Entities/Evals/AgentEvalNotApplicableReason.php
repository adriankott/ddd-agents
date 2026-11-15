<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Why an evaluation case is NOT APPLICABLE to its agent space: it is not run (or its run is void) and counts neither as
 * a pass nor as a fail. A reason (a short key the case rows store and a suite compares) and a detail (the sentence for
 * the operator).
 *
 * The reason is an open string. The module declares its four neutral reasons as constants; an application's hooks
 * ({@see \DDD\Domain\AI\Services\Eval\AgentEvalRunHooksService::findUnmetRequirementDetails()}) return reasons of its
 * own, with the values its stored rows already carry. A reason of an unmet requirement names the requirement
 * ({@see getRequirementName()}) and is held under that name in an {@see AgentEvalNotApplicableReasons}.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalNotApplicableReason extends ValueObject
{
    /** @var string The agent space is not eligible for automation (locked, suspended) */
    public const string REASON_SCOPE_NOT_ELIGIBLE = 'scopeNotEligible';

    /** @var string A skill the case expects exists but is not offered in the agent space */
    public const string REASON_SKILL_NOT_AVAILABLE = 'skillNotAvailable';

    /** @var string A tool the case expects exists but is not offered in the agent space */
    public const string REASON_TOOL_NOT_AVAILABLE = 'toolNotAvailable';

    /** @var string A preparation step of the case failed: the agent was not run */
    public const string REASON_PRECONDITION_NOT_ESTABLISHED = 'preconditionNotEstablished';

    /** @var string The reason key */
    protected string $reason = '';

    /** @var string The sentence for the operator */
    protected string $detail = '';

    /** @var string The requirement of the case the agent space cannot meet; empty for every other reason */
    protected string $requirementName = '';

    /**
     * A reason with its detail.
     *
     * @param string $reason one of the `REASON_*` constants or an application's own key
     * @param string $detail the sentence for the operator
     * @return static
     */
    public static function withReasonAndDetail(string $reason, string $detail): static
    {
        $agentEvalNotApplicableReason = static::newInstance();
        $agentEvalNotApplicableReason->reason = $reason;
        $agentEvalNotApplicableReason->detail = $detail;
        return $agentEvalNotApplicableReason;
    }

    /**
     * The reason of one requirement of the case the agent space cannot meet now.
     *
     * @param string $requirementName the application's name of the requirement
     * @param string $reason the application's reason key
     * @param string $detail the sentence for the operator
     * @return static
     */
    public static function forUnmetRequirement(string $requirementName, string $reason, string $detail): static
    {
        $agentEvalNotApplicableReason = static::withReasonAndDetail($reason, $detail);
        $agentEvalNotApplicableReason->requirementName = $requirementName;
        return $agentEvalNotApplicableReason;
    }

    /**
     * The reason of a case whose preparation step failed: the agent was not run on an agent space without the state
     * the case is about.
     *
     * @param string $failedPreparationStepDescription what failed, as the operator reads it
     * @return static
     */
    public static function buildPreconditionNotEstablishedReason(string $failedPreparationStepDescription): static
    {
        return static::withReasonAndDetail(static::REASON_PRECONDITION_NOT_ESTABLISHED, $failedPreparationStepDescription);
    }

    /** The reason key. */
    public function getReason(): string
    {
        return $this->reason;
    }

    /** The sentence for the operator. */
    public function getDetail(): string
    {
        return $this->detail;
    }

    /** The requirement of the case the agent space cannot meet; empty for every other reason. */
    public function getRequirementName(): string
    {
        return $this->requirementName;
    }

    /**
     * @param string $requirementName
     * @return string the key of the reason of that requirement in an {@see AgentEvalNotApplicableReasons}
     */
    public static function uniqueKeyForRequirementName(string $requirementName): string
    {
        return self::class . '_' . $requirementName;
    }

    /**
     * The key of a reason of an unmet requirement is its requirement name; any other reason keeps the key of its own
     * object.
     */
    public function uniqueKey(): string
    {
        return $this->requirementName !== '' ? static::uniqueKeyForRequirementName($this->requirementName) : parent::uniqueKey();
    }

    /**
     * The reason in the form the case rows and the callers of the applicability check have always stored and printed
     * (the requirement name is not part of it).
     *
     * @return array{reason: string, detail: string}
     */
    public function toArray(): array
    {
        return ['reason' => $this->reason, 'detail' => $this->detail];
    }
}
