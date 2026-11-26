<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionContextSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentDecisionResult;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentDialogue;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentDialogueLine;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentRequest;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentVerdict;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentOwnerConsentDecision;
use DDD\Domain\AI\Services\Conversations\Agent\OwnerConsentsService;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusTransportRecording;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperations;

/**
 * The owner-consent decision over a dialogue whose question stands BEFORE a long draft: the first pass reads the cut
 * explanation (the cut marked) and is asked whether the excerpt shows what the answer refers to; a low answer, or a
 * clear yes with a step-explained probability just short of the threshold, runs ONE second pass over the expanded
 * lines — which consents to a step the assistant named, and still refuses one it never named. The thresholds stay.
 */
class OwnerConsentSecondPassTest extends DecisionSiteOutcomeTestCase
{
    protected PassByPassDecisionModelNetworkDouble $passByPassDecisionModelDouble;

    protected function setUp(): void
    {
        parent::setUp();
        $this->passByPassDecisionModelDouble = new PassByPassDecisionModelNetworkDouble($this->worldLog);
        ArgusApiOperations::$transportRecording = ArgusTransportRecording::forRecording($this->passByPassDecisionModelDouble);
    }

    protected function onTearDown(): void
    {
        AgentDecisionContextSettings::useForCurrentProcess(null);
        parent::onTearDown();
    }

    public function testACutExcerptTheModelCallsInsufficientIsReadAgainExpandedAndTheYesPasses(): void
    {
        $this->passByPassDecisionModelDouble->answersByPass = [
            $this->answers(0.40, 0.97, 0.10),
            $this->answers(0.96, 0.97),
        ];

        $result = $this->decide($this->dialogueWithTheQuestionBeforeALongDraft());

        self::assertTrue($result->verdict->consented);
        self::assertSame(OwnerConsentDecisionResult::SECOND_PASS_REASON_CONTEXT_INSUFFICIENT, $result->secondPassReason);
        self::assertTrue($result->dialogueWasCut);
        self::assertSame([0.40, 0.97, 0.10], [$result->firstStepExplainedProbability, $result->firstOwnerAgreedProbability, $result->contextSufficientProbability]);
        self::assertSame(0.96, $result->verdict->stepExplainedProbability);
        self::assertCount(2, $this->passByPassDecisionModelDouble->sentStates);
        self::assertStringStartsWith('[… ', $this->passByPassDecisionModelDouble->sentStates[0]['dialogue'][0]['text'], 'the first pass marks the cut');
        self::assertStringStartsWith('Soll ich diese Antwort', $this->passByPassDecisionModelDouble->sentStates[1]['dialogue'][0]['text'], 'the second pass reads the question before the draft');
        self::assertContains(ArgusAgentOwnerConsentDecision::QUESTION_CONTEXT_SUFFICIENT, $this->passByPassDecisionModelDouble->sentRequests[0]['questionNames']);
        self::assertNotContains(ArgusAgentOwnerConsentDecision::QUESTION_CONTEXT_SUFFICIENT, $this->passByPassDecisionModelDouble->sentRequests[1]['questionNames'], 'nothing is cut in the second pass');
    }

    public function testAClearYesJustShortOfTheStepIsReadAgainWithoutTheModelsComplaint(): void
    {
        $this->passByPassDecisionModelDouble->answersByPass = [
            $this->answers(0.83, 0.97, 0.80),
            $this->answers(0.95, 0.97),
        ];

        $result = $this->decide($this->dialogueWithTheQuestionBeforeALongDraft());

        self::assertTrue($result->verdict->consented);
        self::assertSame(OwnerConsentDecisionResult::SECOND_PASS_REASON_NEAR_MISS, $result->secondPassReason);
    }

    public function testAStepTheAssistantNeverNamedStaysRefusedAfterTheSecondPass(): void
    {
        $this->passByPassDecisionModelDouble->answersByPass = [
            $this->answers(0.40, 0.97, 0.10),
            $this->answers(0.30, 0.97),
        ];

        $result = $this->decide($this->dialogueWithTheQuestionBeforeALongDraft());

        self::assertFalse($result->verdict->consented);
        self::assertSame(0.30, $result->verdict->stepExplainedProbability, 'the second pass decides; the threshold stays');
        self::assertCount(2, $this->passByPassDecisionModelDouble->sentStates, 'at most one second pass');
    }

    public function testADialogueWithoutACutOrASwitchedOffSecondPassIsReadOnce(): void
    {
        $this->passByPassDecisionModelDouble->answersByPass = [$this->answers(0.83, 0.97)];
        $shortDialogue = new OwnerConsentDialogue();
        $shortDialogue->add(OwnerConsentDialogueLine::create(OwnerConsentDialogueLine::SPEAKER_ASSISTANT, 10, 'Soll ich die Antwort auf Ticket #13129 senden? Sie lässt sich nicht zurückholen.'));
        $shortDialogue->add(OwnerConsentDialogueLine::create(OwnerConsentDialogueLine::SPEAKER_OWNER, 11, 'Ja, schick das so raus.'));

        $result = $this->decide($shortDialogue);

        self::assertFalse($result->verdict->consented);
        self::assertNull($result->secondPassReason);
        self::assertFalse($result->dialogueWasCut);
        self::assertNotContains(ArgusAgentOwnerConsentDecision::QUESTION_CONTEXT_SUFFICIENT, $this->passByPassDecisionModelDouble->sentRequests[0]['questionNames'], 'the normal path asks nothing more');

        $agentDecisionContextSettings = new AgentDecisionContextSettings();
        $agentDecisionContextSettings->ownerConsentSecondPass = false;
        AgentDecisionContextSettings::useForCurrentProcess($agentDecisionContextSettings);
        $this->passByPassDecisionModelDouble->answersByPass[] = $this->answers(0.40, 0.97, 0.10);
        $switchedOffResult = $this->decide($this->dialogueWithTheQuestionBeforeALongDraft());
        self::assertNull($switchedOffResult->secondPassReason);
        self::assertCount(2, $this->passByPassDecisionModelDouble->sentStates, 'one request per decision');
    }

    /** @return array<string, float> */
    protected function answers(float $stepExplainedProbability, float $ownerAgreedProbability, ?float $contextSufficientProbability = null): array
    {
        $answers = [
            ArgusAgentOwnerConsentDecision::QUESTION_STEP_EXPLAINED => $stepExplainedProbability,
            ArgusAgentOwnerConsentDecision::QUESTION_OWNER_AGREED => $ownerAgreedProbability,
        ];
        if ($contextSufficientProbability !== null) {
            $answers[ArgusAgentOwnerConsentDecision::QUESTION_CONTEXT_SUFFICIENT] = $contextSufficientProbability;
        }
        return $answers;
    }

    protected function decide(OwnerConsentDialogue $ownerConsentDialogue): OwnerConsentDecisionResult
    {
        $ownerConsentRequest = OwnerConsentRequest::forAction('replyToSupportTicket', 'SUPPORT_REPLY', 'send the drafted reply on support ticket #13129', 'the message cannot be taken back', 'supportReply:13129');
        return (new OwnerConsentsService())->decideDialogue($ownerConsentRequest, $ownerConsentDialogue, 'chat', null, null, OwnerConsentVerdict::DECIDED_BY_DECISION_MODEL);
    }

    /** The question first, then a draft far longer than the first pass reads. */
    protected function dialogueWithTheQuestionBeforeALongDraft(): OwnerConsentDialogue
    {
        $ownerConsentDialogue = new OwnerConsentDialogue();
        $ownerConsentDialogue->add(OwnerConsentDialogueLine::create(
            OwnerConsentDialogueLine::SPEAKER_ASSISTANT,
            10,
            'Soll ich diese Antwort auf Ticket #13129 senden? Sie lässt sich nicht zurückholen. Entwurf: ' . str_repeat('Hallo Greta, ich habe deine Fahrten geprüft. ', 120)
        ));
        $ownerConsentDialogue->add(OwnerConsentDialogueLine::create(OwnerConsentDialogueLine::SPEAKER_OWNER, 11, 'Ja, schick das so raus.'));
        return $ownerConsentDialogue;
    }
}
