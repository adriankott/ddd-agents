<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentMandateCoverage;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentRequest;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentReservation;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentVerdict;
use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent;
use DDD\Domain\AI\Entities\Mandates\AgentMandate;

/**
 * A standing mandate of the tool's `coveredByMandateActionClass` covers its owner-consent step: no consent question, the
 * write runs, the turn event says "under mandate #id". Without one the step asks as before; after a revoke it asks
 * again; the host's hook narrows it (not applicable → the step asks) or refuses a case (nothing runs, a clean text, the
 * call not held for a confirmation). A tool without a covering class never looks a mandate up.
 */
class OwnerConsentCoveredByMandateTest extends DecisionSiteOutcomeTestCase
{
    protected const int MANDATE_ID = 77;

    protected const string ACTION_CLASS = 'EXAMPLE_REPLY';

    protected OwnerConsentsServiceWithScriptedMandateDouble $ownerConsentsService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ownerConsentsService = new OwnerConsentsServiceWithScriptedMandateDouble();
    }

    public function testAStandingMandateCoversTheStepWithoutAConsentQuestion(): void
    {
        $this->ownerConsentsService->standingMandate = $this->standingMandate();
        $callProvenance = $this->callProvenance(self::ACTION_CLASS);

        $reservation = $this->ownerConsentsService->reserveOwnerConsentForStep($callProvenance, $this->request());

        self::assertSame(OwnerConsentReservation::OUTCOME_COVERED_BY_MANDATE, $reservation->outcome);
        self::assertTrue($reservation->isWriteAllowed());
        self::assertSame('', $reservation->buildModelFacingText());
        self::assertNull($reservation->reservedUse, 'nothing to mark used or release');
        self::assertFalse($callProvenance->isWriteHeldForOwnerConsent);
        self::assertSame([], $this->decisionModelDouble->sentRequests, 'no consent question');
        $payload = $this->onlyOwnerConsentEventPayload();
        self::assertSame(OwnerConsentVerdict::DECIDED_BY_MANDATE, $payload['decidedBy']);
        self::assertSame(self::MANDATE_ID, $payload['coveringMandateId']);
        self::assertSame(self::ACTION_CLASS, $payload['coveringMandateActionClass']);
        self::assertTrue($payload['consented']);
    }

    public function testWithoutAMandateOrAfterARevokeTheStepAsksTheOwner(): void
    {
        $this->ownerConsentsService->standingMandate = $this->standingMandate();
        self::assertTrue($this->ownerConsentsService->reserveOwnerConsentForStep($this->callProvenance(self::ACTION_CLASS), $this->request())->isWriteAllowed());

        // revoked: the live lookup finds nothing
        $this->ownerConsentsService->standingMandate = null;
        $callProvenance = $this->callProvenance(self::ACTION_CLASS);
        $reservation = $this->ownerConsentsService->reserveOwnerConsentForStep($callProvenance, $this->request());

        self::assertFalse($reservation->isWriteAllowed());
        self::assertNotSame(OwnerConsentVerdict::DECIDED_BY_MANDATE, $reservation->verdict->decidedBy);
        self::assertNull($reservation->verdict->coveringMandateId);
        self::assertTrue($callProvenance->isWriteHeldForOwnerConsent, 'held for the owner\'s confirmation as without a mandate');
        self::assertStringStartsWith('NOT DONE', $reservation->buildModelFacingText());
        self::assertSame([self::ACTION_CLASS, self::ACTION_CLASS], $this->ownerConsentsService->lookedUpActionClasses, 'looked up live on every call');
    }

    public function testTheHostsRefusalUnderTheMandateRunsNothingAndSaysWhatToDoInstead(): void
    {
        $this->ownerConsentsService->standingMandate = $this->standingMandate();
        $this->ownerConsentsService->scriptedCoverage = OwnerConsentMandateCoverage::refused('The case is unclear: mark the ticket for a person and do not reply.');
        $callProvenance = $this->callProvenance(self::ACTION_CLASS);

        $reservation = $this->ownerConsentsService->reserveOwnerConsentForStep($callProvenance, $this->request());

        self::assertFalse($reservation->isWriteAllowed());
        self::assertSame(
            'NOT DONE — nothing was changed. The case is unclear: mark the ticket for a person and do not reply. Do not retry this step in this run.',
            $reservation->buildModelFacingText()
        );
        self::assertFalse($callProvenance->isWriteHeldForOwnerConsent, 'refused, not held for a confirmation');
        self::assertSame([], $this->decisionModelDouble->sentRequests);
        $payload = $this->onlyOwnerConsentEventPayload();
        self::assertSame(OwnerConsentVerdict::STAMP_REFUSED_UNDER_MANDATE, $payload['decidedBy']);
        self::assertSame(self::MANDATE_ID, $payload['coveringMandateId']);
    }

    public function testAMandateTheHostDoesNotApplyToTheCallLeavesTheConsentStep(): void
    {
        $this->ownerConsentsService->standingMandate = $this->standingMandate();
        $this->ownerConsentsService->scriptedCoverage = OwnerConsentMandateCoverage::notApplicable();

        $reservation = $this->ownerConsentsService->reserveOwnerConsentForStep($this->callProvenance(self::ACTION_CLASS), $this->request());

        self::assertFalse($reservation->isWriteAllowed());
        self::assertNull($reservation->verdict->coveringMandateId);
    }

    public function testAToolWithoutACoveringClassNeverLooksAMandateUp(): void
    {
        $this->ownerConsentsService->standingMandate = $this->standingMandate();

        $reservation = $this->ownerConsentsService->reserveOwnerConsentForStep($this->callProvenance(null), $this->request());

        self::assertFalse($reservation->isWriteAllowed());
        self::assertSame([], $this->ownerConsentsService->lookedUpActionClasses);
    }

    protected function standingMandate(): AgentMandate
    {
        $agentMandate = new AgentMandate();
        $agentMandate->id = self::MANDATE_ID;
        $agentMandate->actionClass = self::ACTION_CLASS;
        $agentMandate->scopeType = AgentMandate::SCOPE_UNLIMITED;
        $agentMandate->status = AgentMandate::STATUS_ACTIVE;
        return $agentMandate;
    }

    protected function callProvenance(?string $coveringActionClass): AgentToolCallProvenance
    {
        $conversation = $this->conversation([
            $this->ownerText(4, 'Please answer the open tickets.'),
            $this->assistantText(5, 'I am on it.'),
        ]);
        $conversation->accountId = 11;
        $callProvenance = new AgentToolCallProvenance(conversationId: self::CONVERSATION_ID, rootConversationId: self::CONVERSATION_ID, conversation: $conversation);
        $callProvenance->toolCallId = 'call-1';
        $callProvenance->ownerConsentCoveringMandateActionClass = $coveringActionClass;
        return $callProvenance;
    }

    protected function request(): OwnerConsentRequest
    {
        return OwnerConsentRequest::forAction('replyToTicket', 'TICKET_REPLY', 'send the drafted reply on ticket #42', 'the message cannot be taken back', 'ticket:42');
    }

    /** @return array<string, mixed> */
    protected function onlyOwnerConsentEventPayload(): array
    {
        $ownerConsentEvents = array_values(array_filter(
            $this->turnEventsServiceDouble->recordedEvents,
            static fn (AIConversationTurnEvent $event): bool => $event->site === 'ownerConsent'
        ));
        self::assertCount(1, $ownerConsentEvents);
        return json_decode((string)$ownerConsentEvents[0]->payload, true);
    }
}
