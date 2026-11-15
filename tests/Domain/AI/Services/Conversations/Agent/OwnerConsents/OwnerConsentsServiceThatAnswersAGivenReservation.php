<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\OwnerConsents;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentRequest;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentReservation;
use DDD\Domain\AI\Services\Conversations\Agent\OwnerConsentsService;

/** The owner-consent service whose decision is given: the tests of what the reservation marks on the call. */
class OwnerConsentsServiceThatAnswersAGivenReservation extends OwnerConsentsService
{
    public ?OwnerConsentReservation $givenReservation = null;

    protected function findOwnerConsentReservationForStep(?AgentToolCallProvenance $callProvenance, OwnerConsentRequest $request): OwnerConsentReservation
    {
        return $this->givenReservation;
    }
}
