<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionCallOutcome;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * What one owner-consent decision produced ({@see \DDD\Domain\AI\Services\Conversations\Agent\OwnerConsentsService::decideDialogue()}):
 * the verdict, the dialogue it judged and how the decision-model call went — the last two for the turn event; both null
 * when the check ended before them (a delegation child, the site mode, no live owner, no owner row).
 *
 * @phpstan-consistent-constructor
 */
class OwnerConsentDecisionResult extends ValueObject
{
    public OwnerConsentVerdict $verdict;

    /** The dialogue the decision read; null when the check ended before it was built. */
    public ?OwnerConsentDialogue $dialogue = null;

    /** The second pass ran because the model said the cut excerpt does not show what the answer refers to */
    public const string SECOND_PASS_REASON_CONTEXT_INSUFFICIENT = 'contextInsufficient';

    /** The second pass ran because the owner's yes was clear and the step-explained probability fell just short */
    public const string SECOND_PASS_REASON_NEAR_MISS = 'nearMiss';

    /** How the decision-model call went (the second pass's when one ran); null when no call was run. */
    public ?AgentDecisionCallOutcome $callOutcome = null;

    /** Whether the first pass read a cut dialogue (a line longer than its limit, the cut marked) */
    public bool $dialogueWasCut = false;

    /** @var float|null The first pass's step-explained probability when a second pass followed; null otherwise */
    public ?float $firstStepExplainedProbability = null;

    /** @var float|null The first pass's owner-agreed probability when a second pass followed; null otherwise */
    public ?float $firstOwnerAgreedProbability = null;

    /** @var float|null The first pass's answer whether the cut excerpt shows what the answer refers to; null when not asked */
    public ?float $contextSufficientProbability = null;

    /** @var string|null Why a second pass ran (SECOND_PASS_REASON_*); null when none ran */
    public ?string $secondPassReason = null;

    public static function create(OwnerConsentVerdict $verdict, ?OwnerConsentDialogue $dialogue = null, ?AgentDecisionCallOutcome $callOutcome = null): static
    {
        $ownerConsentDecisionResult = new static();
        $ownerConsentDecisionResult->verdict = $verdict;
        $ownerConsentDecisionResult->dialogue = $dialogue;
        $ownerConsentDecisionResult->callOutcome = $callOutcome;
        return $ownerConsentDecisionResult;
    }
}
