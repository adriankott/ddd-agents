<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * Whether an evaluation case can be judged in its agent space at all — facts in, reason out, no I/O. A case that
 * expects a capability the agent space does not have is neither a pass nor a fail: the agent cannot load a skill the
 * space is not offered, and nothing runs for a locked space. Such a case is NOT APPLICABLE and leaves the pass rate
 * untouched.
 *
 * The facts: whether the space is eligible for automation, every skill and tool of the catalog, the skills the space is
 * not offered with the reason of each, the tools it is offered, and what the space cannot meet now of what the case
 * requires. The evaluation runner fills them: the catalog walk and the tool gates are the module's, the eligibility,
 * the reason of a withheld skill and the unmet requirements come from the application's hooks
 * ({@see \DDD\Domain\AI\Services\Eval\AgentEvalRunHooksService}).
 *
 * A name the catalog does not know at all is a defect of the fixture and stays a failure: only a capability that
 * EXISTS and is withheld from this space makes a case not applicable.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalApplicabilityFacts extends ValueObject
{
    /** @var AgentEvalCase The case the facts are about */
    public AgentEvalCase $case;

    /** @var string|null Null when the agent space is eligible for automation, else its state and the reason */
    public ?string $ineligibleScopeReason = null;

    /** @var string[] Every skill the platform knows */
    public array $catalogSkillNames = [];

    /** @var array<string, string> Skill name => why the agent space is not offered it */
    public array $withheldSkillReasons = [];

    /** @var string[] Every tool of every skill */
    public array $catalogToolNames = [];

    /** @var string[] The tools of the skills the agent space is offered, after the tool gates */
    public array $availableToolNames = [];

    /**
     * @var AgentEvalNotApplicableReasons What the agent space cannot meet now of what THIS case requires, one reason per
     *      requirement name, in the order of the case's requirements; the first one decides
     */
    public AgentEvalNotApplicableReasons $unmetRequirementReasons;

    /** @var string The application's word for an agent space, as the operator's sentences name it */
    public string $agentSpaceName = '';

    public function __construct()
    {
        parent::__construct();
        $this->unmetRequirementReasons = AgentEvalNotApplicableReasons::newInstance();
    }

    /**
     * The facts of `$case`, the rest empty; the word for the agent space is the application's
     * ({@see AgentApplicationWording::$agentSpaceName}).
     *
     * @param AgentEvalCase $case
     * @return static
     */
    public static function forCase(AgentEvalCase $case): static
    {
        $agentEvalApplicabilityFacts = static::newInstance();
        $agentEvalApplicabilityFacts->case = $case;
        $agentEvalApplicabilityFacts->agentSpaceName = AgentApplicationWording::current()->agentSpaceName;
        return $agentEvalApplicabilityFacts;
    }

    /**
     * Why the case cannot be judged in its agent space, or null when it applies. In this order: the space is not
     * eligible (unless the case runs on an ineligible space on purpose); a case that tests the answer to a withheld
     * capability applies from here on; an expected skill is withheld; none of the any-of skills is offered; none of
     * the any-of tools is offered; one required tool is withheld; the first unmet requirement.
     *
     * @return AgentEvalNotApplicableReason|null
     */
    public function findNotApplicableReason(): ?AgentEvalNotApplicableReason
    {
        if ($this->ineligibleScopeReason !== null && !$this->case->runsOnIneligibleScope()) {
            return AgentEvalNotApplicableReason::withReasonAndDetail(
                AgentEvalNotApplicableReason::REASON_SCOPE_NOT_ELIGIBLE,
                'the scope is not eligible for automation: ' . $this->ineligibleScopeReason
            );
        }
        // a case about the answer to a WITHHELD capability (the upgrade offer, the missing right) runs where it is withheld
        if ($this->case->runsWithoutCapability()) {
            return null;
        }
        return $this->buildWithheldExpectedSkillReason()
            ?? $this->buildWithheldAnyOfSkillsReason()
            ?? $this->buildWithheldAnyOfToolsReason()
            ?? $this->buildWithheldRequiredToolReason()
            ?? $this->getFirstUnmetRequirementReason();
    }

    /** An expected skill the catalog knows and the agent space is not offered; null when there is none. */
    protected function buildWithheldExpectedSkillReason(): ?AgentEvalNotApplicableReason
    {
        foreach ($this->case->getExpectedSkillNames() as $expectedSkillName) {
            if (in_array($expectedSkillName, $this->catalogSkillNames, true) && isset($this->withheldSkillReasons[$expectedSkillName])) {
                return AgentEvalNotApplicableReason::withReasonAndDetail(
                    AgentEvalNotApplicableReason::REASON_SKILL_NOT_AVAILABLE,
                    sprintf("the expected skill '%s' is not offered to this %s: %s", $expectedSkillName, $this->agentSpaceName, $this->withheldSkillReasons[$expectedSkillName])
                );
            }
        }
        return null;
    }

    /** `anyOfSkills`: the case applies while ONE of its known skills is offered; null when one is. */
    protected function buildWithheldAnyOfSkillsReason(): ?AgentEvalNotApplicableReason
    {
        $knownAnyOfSkillNames = array_values(array_intersect($this->case->getExpectedAnyOfSkillNames(), $this->catalogSkillNames));
        if ($knownAnyOfSkillNames === [] || array_diff($knownAnyOfSkillNames, array_keys($this->withheldSkillReasons)) !== []) {
            return null;
        }
        return AgentEvalNotApplicableReason::withReasonAndDetail(
            AgentEvalNotApplicableReason::REASON_SKILL_NOT_AVAILABLE,
            sprintf(
                'none of the expected skills [%s] is offered to this %s: %s',
                implode(', ', $knownAnyOfSkillNames),
                $this->agentSpaceName,
                $this->withheldSkillReasons[$knownAnyOfSkillNames[0]]
            )
        );
    }

    /** `tool` is any-of: the case applies while ONE of its known tools is offered; null when one is. */
    protected function buildWithheldAnyOfToolsReason(): ?AgentEvalNotApplicableReason
    {
        $knownAnyOfToolNames = array_values(array_intersect($this->case->getExpectedAnyOfToolNames(), $this->catalogToolNames));
        if ($knownAnyOfToolNames === [] || array_intersect($knownAnyOfToolNames, $this->availableToolNames) !== []) {
            return null;
        }
        return AgentEvalNotApplicableReason::withReasonAndDetail(
            AgentEvalNotApplicableReason::REASON_TOOL_NOT_AVAILABLE,
            sprintf('none of the expected tools [%s] is offered to this %s', implode(', ', $knownAnyOfToolNames), $this->agentSpaceName)
        );
    }

    /** `requiredTools` is all-of: one withheld tool the catalog knows is enough; null when none is withheld. */
    protected function buildWithheldRequiredToolReason(): ?AgentEvalNotApplicableReason
    {
        foreach ($this->case->getExpectedRequiredToolNames() as $requiredToolName) {
            if (in_array($requiredToolName, $this->catalogToolNames, true) && !in_array($requiredToolName, $this->availableToolNames, true)) {
                return AgentEvalNotApplicableReason::withReasonAndDetail(
                    AgentEvalNotApplicableReason::REASON_TOOL_NOT_AVAILABLE,
                    sprintf("the required tool '%s' is not offered to this %s", $requiredToolName, $this->agentSpaceName)
                );
            }
        }
        return null;
    }

    /** The first requirement of the case the agent space cannot meet now; null when it meets every one. */
    protected function getFirstUnmetRequirementReason(): ?AgentEvalNotApplicableReason
    {
        return $this->unmetRequirementReasons->first();
    }
}
