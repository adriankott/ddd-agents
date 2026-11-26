<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentMandateCoverage;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentRequest;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentReservation;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentVerdict;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Mandates\AgentMandate;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\AuthService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Tests\Agents\Presentation\Services\Probes\ProbeProvenanceMCPPackage;

/**
 * The owner-consent step fails closed: only a caller that is the consent-giver itself (a direct call without an agent —
 * the HTTP-MCP owner, an operator's console call — whose provenance the executor marks) or an eval harness step is
 * ungated. A call without provenance, or with one that names no conversation, is refused. A conversation without an id
 * (a transient eval run) is decided like a stored one: a replayed schedule run finds no owner and is refused unless a
 * standing mandate covers it — the host's hook decides that case as in production — and no turn event is written.
 */
class OwnerConsentWithoutAStoredConversationTest extends DecisionSiteOutcomeTestCase
{
    protected const string ACTION_CLASS = 'EXAMPLE_REPLY';

    protected OwnerConsentsServiceWithScriptedMandateDouble $ownerConsentsService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ownerConsentsService = new OwnerConsentsServiceWithScriptedMandateDouble();
        ProbeProvenanceMCPPackage::forgetRuns();
    }

    public function testACallWithoutProvenanceIsRefused(): void
    {
        $reservation = $this->ownerConsentsService->reserveOwnerConsentForStep(null, $this->request());

        self::assertFalse($reservation->isWriteAllowed());
        self::assertSame(OwnerConsentVerdict::FAILURE_NO_CONVERSATION, $reservation->verdict->failure);
        self::assertStringStartsWith('NOT DONE', $reservation->buildModelFacingText());
    }

    public function testAProvenanceWithoutAConversationIsRefusedUnlessItIsADirectCallOrTheHarness(): void
    {
        $agentSpace = new AgentSpace();
        $agentSpace->id = 7;
        $unmarkedProvenance = new AgentToolCallProvenance(agentSpace: $agentSpace);
        self::assertSame(
            OwnerConsentVerdict::FAILURE_NO_CONVERSATION,
            $this->ownerConsentsService->reserveOwnerConsentForStep($unmarkedProvenance, $this->request())->verdict->failure
        );

        $directReservation = $this->ownerConsentsService->reserveOwnerConsentForStep(AgentToolCallProvenance::forDirectCallWithoutAgent($agentSpace), $this->request());
        self::assertSame(OwnerConsentReservation::OUTCOME_UNGATED, $directReservation->outcome);

        $harnessReservation = $this->ownerConsentsService->reserveOwnerConsentForStep(AgentToolCallProvenance::forEvalHarness($agentSpace), $this->request());
        self::assertSame(OwnerConsentReservation::OUTCOME_UNGATED, $harnessReservation->outcome);
    }

    public function testAHostWhoseDirectCallersAreAgentsRefusesThem(): void
    {
        $this->ownerConsentsService->directCallerIsTheConsentGiver = false;

        $reservation = $this->ownerConsentsService->reserveOwnerConsentForStep(AgentToolCallProvenance::forDirectCallWithoutAgent(), $this->request());

        self::assertFalse($reservation->isWriteAllowed());
        self::assertSame(OwnerConsentVerdict::FAILURE_NO_CONVERSATION, $reservation->verdict->failure);
    }

    public function testADirectCallThatCarriesAConversationIsDecidedNotUngated(): void
    {
        $callProvenance = $this->transientScheduledRunProvenance(null);
        $callProvenance->isDirectCallWithoutAgent = true;

        $reservation = $this->ownerConsentsService->reserveOwnerConsentForStep($callProvenance, $this->request());

        self::assertFalse($reservation->isWriteAllowed());
    }

    public function testAReplayedScheduleRunWithoutAnIdFindsNoOwnerAndWritesNoEvent(): void
    {
        $reservation = $this->ownerConsentsService->reserveOwnerConsentForStep($this->transientScheduledRunProvenance(null), $this->request());

        self::assertFalse($reservation->isWriteAllowed());
        self::assertSame(OwnerConsentVerdict::FAILURE_NO_LIVE_OWNER, $reservation->verdict->failure);
        self::assertSame([], $this->decisionModelDouble->sentRequests);
        self::assertSame([], $this->turnEventsServiceDouble->recordedEvents, 'no row to hang an event on');
    }

    public function testAReplayedScheduleRunGoesThroughTheMandateAndTheHostsHook(): void
    {
        $this->ownerConsentsService->standingMandate = $this->standingMandate();
        $this->ownerConsentsService->scriptedCoverage = OwnerConsentMandateCoverage::refused('The case is unclear: mark it for a person.');

        $refusedReservation = $this->ownerConsentsService->reserveOwnerConsentForStep($this->transientScheduledRunProvenance(self::ACTION_CLASS), $this->request());

        self::assertFalse($refusedReservation->isWriteAllowed());
        self::assertSame(OwnerConsentVerdict::FAILURE_REFUSED_UNDER_MANDATE, $refusedReservation->verdict->failure);
        self::assertSame([self::ACTION_CLASS], $this->ownerConsentsService->lookedUpActionClasses);

        $this->ownerConsentsService->scriptedCoverage = OwnerConsentMandateCoverage::covered();
        $coveredReservation = $this->ownerConsentsService->reserveOwnerConsentForStep($this->transientScheduledRunProvenance(self::ACTION_CLASS), $this->request());
        self::assertSame(OwnerConsentReservation::OUTCOME_COVERED_BY_MANDATE, $coveredReservation->outcome);
        self::assertSame([], $this->turnEventsServiceDouble->recordedEvents);
    }

    public function testTheExecutorMarksOnlyTheProvenanceItInjectsForACallerWithoutAgentContext(): void
    {
        $accountBefore = AuthService::instance()->getAccount();
        $callingAccount = Account::newInstance();
        $callingAccount->id = 11;
        AuthService::instance()->setAccount($callingAccount);
        try {
            /** @var MCPToolExecutorService $mCPToolExecutorService */
            $mCPToolExecutorService = DDDService::instance()->getService(MCPToolExecutorService::class);
            $mCPToolExecutorService->execute('probeRecordProvenance', ['note' => 'x'], [ProbeProvenanceMCPPackage::class]);
            $agentProvenance = new AgentToolCallProvenance(conversationId: self::CONVERSATION_ID, conversation: $this->conversation([$this->ownerText(1, 'Hello')]));
            $mCPToolExecutorService->execute('probeRecordProvenance', ['note' => 'x'], [ProbeProvenanceMCPPackage::class], null, $agentProvenance);
        } finally {
            AuthService::instance()->setAccount($accountBefore);
        }

        self::assertCount(2, ProbeProvenanceMCPPackage::$provenancesOfRuns);
        self::assertTrue(ProbeProvenanceMCPPackage::$provenancesOfRuns[0]->isDirectCallWithoutAgent, 'the HTTP-MCP / executor-direct path');
        self::assertFalse(ProbeProvenanceMCPPackage::$provenancesOfRuns[1]->isDirectCallWithoutAgent, 'an agent call is never marked');
    }

    /** The provenance of a tool call in a transient eval replay of a schedule firing (no conversation id). */
    protected function transientScheduledRunProvenance(?string $coveringActionClass): AgentToolCallProvenance
    {
        $conversation = $this->conversation([
            $this->ownerText(4, 'Answer the open tickets.'),
            $this->assistantText(5, 'Working on it.'),
        ]);
        unset($conversation->id);
        $conversation->accountId = 11;
        $conversation->scheduleId = AIConversation::EVAL_SCHEDULED_RUN_SCHEDULE_ID;
        $conversation->initiatorType = AIConversation::INITIATOR_TYPE_SCHEDULE;
        $callProvenance = new AgentToolCallProvenance(conversation: $conversation);
        $callProvenance->toolCallId = 'call-1';
        $callProvenance->ownerConsentCoveringMandateActionClass = $coveringActionClass;
        return $callProvenance;
    }

    protected function standingMandate(): AgentMandate
    {
        $agentMandate = new AgentMandate();
        $agentMandate->id = 78;
        $agentMandate->actionClass = self::ACTION_CLASS;
        $agentMandate->scopeType = AgentMandate::SCOPE_UNLIMITED;
        $agentMandate->status = AgentMandate::STATUS_ACTIVE;
        return $agentMandate;
    }

    protected function request(): OwnerConsentRequest
    {
        return OwnerConsentRequest::forAction('replyToTicket', 'TICKET_REPLY', 'send the drafted reply on ticket #42', 'the message cannot be taken back', 'ticket:42');
    }
}
