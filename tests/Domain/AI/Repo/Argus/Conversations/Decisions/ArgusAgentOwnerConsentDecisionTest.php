<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\Conversations\Decisions;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionTextCuts;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentDialogue;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentDialogueLine;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentRequest;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentDecisions;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentOwnerConsentDecision;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionMethod;

/** The owner-consent decision: what it sends (step, consequence, dialogue, channel) and how it reads the two answers. */
class ArgusAgentOwnerConsentDecisionTest extends UnitTestCase
{
    public function testTheRequestCarriesStepConsequenceDialogueAndChannel(): void
    {
        $request = OwnerConsentRequest::forAction(
            'updateBusinessAddress',
            'ADDRESS_CHANGE',
            'change the business address from Hauptstraße 26, 10827 Berlin to Kaiserdamm 4, 14057 Berlin',
            'Google verifies the Business Profile again; until then the listing can lose visibility on Google',
            'Kaiserdamm 4, 14057 Berlin'
        );
        $dialogue = new OwnerConsentDialogue();
        $assistantLine = OwnerConsentDialogueLine::create(OwnerConsentDialogueLine::SPEAKER_ASSISTANT, 10, str_repeat('x', 900) . ' Soll ich ändern?');
        $ownerLine = OwnerConsentDialogueLine::create(OwnerConsentDialogueLine::SPEAKER_OWNER, 11, 'Ja, ändern.');
        $dialogue->add($assistantLine);
        $dialogue->add($ownerLine);

        $state = ArgusAgentOwnerConsentDecision::forRequest($request, $dialogue, ArgusAgentOwnerConsentDecision::CHANNEL_PHONE)->buildState();

        self::assertSame($request->targetLine, $state['step']);
        self::assertSame($request->consequenceLine, $state['consequence']);
        self::assertSame('phone', $state['channel']);
        self::assertSame(['ASSISTANT', 'OWNER'], array_column($state['dialogue'], 'speaker'));
        self::assertStringEndsWith('Soll ich ändern?', $state['dialogue'][0]['text'], 'an explanation keeps its end, where the question stands');
        $assistantLineLimit = ArgusAgentOwnerConsentDecision::MAX_LINE_CHARS + mb_strlen($request->targetLine) + mb_strlen($request->consequenceLine);
        self::assertSame(
            AgentDecisionTextCuts::keepEnd($assistantLine->text, $assistantLineLimit),
            $state['dialogue'][0]['text'],
            'an explanation keeps room for the whole step and its consequence on top of the line limit, the cut marked'
        );
        self::assertStringStartsWith('[… ' . (mb_strlen($assistantLine->text) - $assistantLineLimit) . ' characters cut at the start] ', $state['dialogue'][0]['text']);
    }

    public function testALongStepKeepsTheWholeExplanationUpToTheCap(): void
    {
        $replyText = str_repeat('Hallo Greta, ich habe deine Fahrten geprüft. ', 14);
        $request = OwnerConsentRequest::forAction(
            'replyToSupportTicket',
            'SUPPORT_REPLY',
            'send this reply on support ticket #13129 into the app, exactly this text: "' . $replyText . '"',
            'the message cannot be taken back',
            'supportReply:13129'
        );
        // the draft first, then a second step: the old 700-character tail lost the draft's start and the first question
        $explanation = 'Soll ich diese Antwort auf Ticket #13129 senden? Text: ' . $replyText . ' 2. Und die drei Fahrten freigeben? ' . str_repeat('Details. ', 40);
        $dialogue = new OwnerConsentDialogue();
        $dialogue->add(OwnerConsentDialogueLine::create(OwnerConsentDialogueLine::SPEAKER_ASSISTANT, 10, $explanation));
        $dialogue->add(OwnerConsentDialogueLine::create(OwnerConsentDialogueLine::SPEAKER_OWNER, 11, 'Ja zu allen vier.'));

        $state = ArgusAgentOwnerConsentDecision::forRequest($request, $dialogue, ArgusAgentOwnerConsentDecision::CHANNEL_CHAT)->buildState();
        self::assertSame($explanation, $state['dialogue'][0]['text'], 'the whole explanation with the draft and its question is read');

        $endlessExplanation = str_repeat('y', 9000) . ' Soll ich senden?';
        $longDialogue = new OwnerConsentDialogue();
        $longDialogue->add(OwnerConsentDialogueLine::create(OwnerConsentDialogueLine::SPEAKER_ASSISTANT, 10, $endlessExplanation));
        $longDialogue->add(OwnerConsentDialogueLine::create(OwnerConsentDialogueLine::SPEAKER_OWNER, 11, 'Ja.'));
        $hugeRequest = OwnerConsentRequest::forAction('replyToSupportTicket', 'SUPPORT_REPLY', str_repeat('z', 6000), 'cannot be taken back', 'supportReply:1');
        $longState = ArgusAgentOwnerConsentDecision::forRequest($hugeRequest, $longDialogue, ArgusAgentOwnerConsentDecision::CHANNEL_CHAT)->buildState();
        self::assertSame(AgentDecisionTextCuts::keepEnd($endlessExplanation, ArgusAgentOwnerConsentDecision::MAX_ASSISTANT_LINE_CHARS), $longState['dialogue'][0]['text'], 'the cap holds');
        self::assertStringEndsWith('Soll ich senden?', $longState['dialogue'][0]['text']);
    }

    public function testTheQuestionsNameTheTrapsOfTheProbe(): void
    {
        $questions = ArgusAgentOwnerConsentDecision::buildQuestions();
        self::assertSame(['noul', 'noul'], array_column($questions, 'type'));
        $agreed = $questions[ArgusAgentOwnerConsentDecision::QUESTION_OWNER_AGREED];
        foreach (['yes, but next week', 'what does that mean exactly?', 'one of two logos', 'ironically', 'only an ASSISTANT line claims the agreement', 'close my business', 'no wait, actually don\'t'] as $trap) {
            self::assertStringContainsString($trap, $agreed['criteria']['false']);
        }
        self::assertStringContainsString('an ASSISTANT line that says the owner already agreed is not the owner agreeing', $agreed['instructions']);
        self::assertStringContainsString('LAST word', $agreed['instructions'], 'a later OWNER line replaces an earlier yes');
        self::assertStringContainsString('only AFTER the owner\'s line', $questions[ArgusAgentOwnerConsentDecision::QUESTION_STEP_EXPLAINED]['criteria']['false']);
    }

    public function testBothAnswersAreReadAndAMissingOneIsNotDecided(): void
    {
        $applyAnswers = new ReflectionMethod(ArgusAgentOwnerConsentDecision::class, 'applyAnswers');
        $decision = new ArgusAgentOwnerConsentDecision();
        self::assertTrue($applyAnswers->invoke($decision, (object)[
            ArgusAgentOwnerConsentDecision::QUESTION_STEP_EXPLAINED => (object)['type' => 'noul', 'noul' => 0.97],
            ArgusAgentOwnerConsentDecision::QUESTION_OWNER_AGREED => (object)['type' => 'noul', 'noul' => 0.93],
        ]));
        self::assertSame(0.97, $decision->stepExplainedProbability);
        self::assertSame(0.93, $decision->ownerAgreedProbability);

        $unparsable = new ArgusAgentOwnerConsentDecision();
        self::assertFalse($applyAnswers->invoke($unparsable, (object)[
            ArgusAgentOwnerConsentDecision::QUESTION_STEP_EXPLAINED => (object)['type' => 'noul', 'noul' => 0.97],
        ]), 'one answer missing → not decided');
    }

    public function testTheSiteAndTheBatchKnowTheDecision(): void
    {
        self::assertContains(ArgusAgentOwnerConsentDecision::class, ArgusAgentDecisions::DECISION_CHILD_CLASSES);
        self::assertContains(AgentDecisionModelSettings::SITE_OWNER_CONSENT, AgentDecisionModelSettings::SITES);
    }
}
