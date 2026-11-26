<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The host's answer whether a standing mandate covers one owner-consent step
 * ({@see \DDD\Domain\AI\Services\Conversations\Agent\OwnerConsentsService::decideMandateCoverage()}): COVERED — the step
 * runs without a consent question; NOT_APPLICABLE — the mandate does not reach this call (another context), the step
 * asks the owner as without a mandate; REFUSED — the mandate reaches the call but the host does not take this step on
 * its own here (unsure, a trigger): nothing runs and the model reads {@see $reasonForTheModel}.
 *
 * @phpstan-consistent-constructor
 */
class OwnerConsentMandateCoverage extends ValueObject
{
    public const string OUTCOME_COVERED = 'COVERED';
    public const string OUTCOME_NOT_APPLICABLE = 'NOT_APPLICABLE';
    public const string OUTCOME_REFUSED = 'REFUSED';

    public string $outcome = self::OUTCOME_COVERED;

    /** @var string|null What the model reads for a REFUSED step: what to do instead (mark the case, report it) */
    public ?string $reasonForTheModel = null;

    public static function covered(): static
    {
        return new static();
    }

    public static function notApplicable(): static
    {
        $ownerConsentMandateCoverage = new static();
        $ownerConsentMandateCoverage->outcome = self::OUTCOME_NOT_APPLICABLE;
        return $ownerConsentMandateCoverage;
    }

    /** @param string $reasonForTheModel what the model reads: why the step is not taken and what to do instead */
    public static function refused(string $reasonForTheModel): static
    {
        $ownerConsentMandateCoverage = new static();
        $ownerConsentMandateCoverage->outcome = self::OUTCOME_REFUSED;
        $ownerConsentMandateCoverage->reasonForTheModel = $reasonForTheModel;
        return $ownerConsentMandateCoverage;
    }
}
