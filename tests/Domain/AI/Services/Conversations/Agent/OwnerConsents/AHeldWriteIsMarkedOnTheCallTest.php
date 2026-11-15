<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\OwnerConsents;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentRequest;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentReservation;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentVerdict;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A write the owner-consent check holds (NOT_RESERVED) is marked on the call's provenance — the loop copies it onto the
 * tool call, so the answer contract asks for the confirmation instead of reporting the write as done. An allowed
 * write leaves the mark off.
 */
class AHeldWriteIsMarkedOnTheCallTest extends UnitTestCase
{
    public function testANotReservedWriteMarksTheCallAndAnAllowedOneDoesNot(): void
    {
        $request = OwnerConsentRequest::forAction('updateAddress', 'update', 'the address', 'the profile shows it', 'address');
        $ownerConsentsService = new OwnerConsentsServiceThatAnswersAGivenReservation();

        $heldCallProvenance = new AgentToolCallProvenance();
        $ownerConsentsService->givenReservation = OwnerConsentReservation::notReserved($request, OwnerConsentVerdict::failed(OwnerConsentVerdict::FAILURE_ERROR));
        self::assertFalse($ownerConsentsService->reserveOwnerConsentForStep($heldCallProvenance, $request)->isWriteAllowed());
        self::assertTrue($heldCallProvenance->isWriteHeldForOwnerConsent);

        $allowedCallProvenance = new AgentToolCallProvenance();
        $ownerConsentsService->givenReservation = OwnerConsentReservation::ungated($request, OwnerConsentVerdict::ungated());
        self::assertTrue($ownerConsentsService->reserveOwnerConsentForStep($allowedCallProvenance, $request)->isWriteAllowed());
        self::assertFalse($allowedCallProvenance->isWriteHeldForOwnerConsent);
    }
}
