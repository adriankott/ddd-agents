<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionCallOutcome;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentSkillPreloadDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentStallRecoveryDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentDialogue;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentDialogueLine;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentRequest;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentVerdict;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Services\Conversations\Agent\AgentFinalTextAcceptanceService;
use DDD\Domain\AI\Services\Conversations\Agent\AgentStallRecoveryService;
use DDD\Domain\AI\Services\Conversations\Agent\OwnerConsentsService;
use DDD\Domain\AI\Services\Skills\AISkillsService;
use DDD\Tests\Agents\Domain\AI\Services\Eval\AISkillsServiceWithSubSkillsDouble;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

/**
 * The site × outcome table of the decision sites that ask the decision model with a batch of ONE child, each through
 * the runner `AgentDecisionCallsService::runDecisionBatch()`: the skill-preload follow-up pass, the sub-skill pass after
 * the chat model's own `load_skills`, stall recovery, turn closing and the owner-consent decision (with its check).
 *
 * A row's cells ({@see OneChildDecisionSitesOutcomeTableCells}), in call order: the request sent, the breaker trip and
 * close requests, the working-state slice and the decision card written (or none), the turn event with its
 * `decidedBy`, and `result …` = what the site hands back to the loop (its fail-open value).
 *
 * What the table pins, one rule each:
 * - our own gate's refusal stamps every site `fallback:refused` (the two sub-skill passes with their card, decision
 *   event and request event, as on a transport failure); the consent verdict keeps the failure `refused`, its turn event
 *   carries `decidedBy` `fallback:refused`;
 * - only a parsable answer asks to close the breaker (an unparsable or a 400 answer leaves it);
 * - the sub-skill pass after the chat model's load returns '' silently (no stamp, no card, no event) under an open
 *   breaker and under our own error; a timed-out or 503 child is settled and stamped `fallback:transport`;
 * - the owner-consent decision writes no card; the check records its own turn event.
 */
class OneChildDecisionSitesOutcomeTableTest extends DecisionSiteOutcomeTestCase
{
    public AISkillsServiceWithSubSkillsDouble $skillsServiceDouble;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skillsServiceDouble = new AISkillsServiceWithSubSkillsDouble();
        $this->skillsServiceDouble->subSkillNamesBySkillName = [self::ROOT_SKILL => [self::SUB_SKILL_ADDRESS, self::SUB_SKILL_LINKS]];
        $this->replaceService(AISkillsService::class, $this->skillsServiceDouble);
    }

    /** @return array<string, array{0: string, 1: string, 2: array<string, float|string>}> site scenario, outcome, scripted answers */
    public static function oneChildRows(): array
    {
        $rows = [];
        foreach (['skillPreloadFollowUpPass', 'subSkillPassAfterModelLoad', 'stallRecovery', 'turnClosing', 'ownerConsentDecision'] as $site) {
            foreach (self::OUTCOMES as $outcome) {
                $rows[$site . ' × ' . $outcome] = [$site, $outcome, []];
            }
        }
        $rows['skillPreloadFollowUpPass × usable, the pass before was the last (MAX_PASSES)'] = ['skillPreloadFollowUpPassAtMaxPasses', self::OUTCOME_USABLE, []];
        $rows['subSkillPassAfterModelLoad × usable, a sub-skill is admitted'] = ['subSkillPassAfterModelLoad', self::OUTCOME_USABLE, ['skill' => strtolower(self::SUB_SKILL_ADDRESS)]];
        $rows['subSkillPassAfterModelLoad × usable, the call loaded four skills (nothing asked)'] = ['subSkillPassAfterFourModelLoads', self::OUTCOME_USABLE, []];
        $rows['stallRecovery × usable, a forced work-tool call is picked'] = ['stallRecovery', self::OUTCOME_USABLE, ['next_move' => AgentStallRecoveryDecision::MOVE_CALL_A_WORK_TOOL]];
        $rows['stallRecovery × usable under shadow'] = ['stallRecoveryShadow', self::OUTCOME_USABLE, ['next_move' => AgentStallRecoveryDecision::MOVE_CALL_A_WORK_TOOL]];
        $rows['turnClosing × usable, the answer announces (bounce)'] = ['turnClosing', self::OUTCOME_USABLE, ['announces_instead_of_delivers' => 0.9]];
        $rows['turnClosing × usable under shadow'] = ['turnClosingShadow', self::OUTCOME_USABLE, ['announces_instead_of_delivers' => 0.9]];
        $rows['ownerConsentDecision × usable, both probabilities above 0.90'] = ['ownerConsentDecision', self::OUTCOME_USABLE, ['assistant_named_this_step_and_its_consequence' => 0.95, 'owner_explicitly_agreed_afterwards' => 0.95]];
        $rows['ownerConsentCheck × usable, both probabilities above 0.90 (event)'] = ['ownerConsentCheck', self::OUTCOME_USABLE, ['assistant_named_this_step_and_its_consequence' => 0.95, 'owner_explicitly_agreed_afterwards' => 0.95]];
        $rows['ownerConsentCheck × transport (event)'] = ['ownerConsentCheck', self::OUTCOME_TRANSPORT, []];
        $rows['ownerConsentCheck × refused (event)'] = ['ownerConsentCheck', self::OUTCOME_REFUSED, []];
        $rows['ownerConsentCheck × usable under shadow (no consent, no call)'] = ['ownerConsentCheckShadow', self::OUTCOME_USABLE, []];
        return $rows;
    }

    /** @param array<string, float|string> $scriptedAnswers */
    #[DataProvider('oneChildRows')]
    public function testTheOneChildSiteRow(string $siteScenario, string $outcome, array $scriptedAnswers): void
    {
        $rowName = (string)$this->dataName();
        $this->scriptOutcome($outcome);
        $this->decisionModelDouble->answersByQuestion = $scriptedAnswers + $this->decisionModelDouble->answersByQuestion;

        $result = match ($siteScenario) {
            'skillPreloadFollowUpPass' => $this->runSkillPreloadFollowUpPass(1),
            'skillPreloadFollowUpPassAtMaxPasses' => $this->runSkillPreloadFollowUpPass(AgentSkillPreloadDecision::MAX_PASSES),
            'subSkillPassAfterModelLoad' => $this->runSubSkillPassAfterModelLoad([self::ROOT_SKILL]),
            'subSkillPassAfterFourModelLoads' => $this->runSubSkillPassAfterModelLoad([self::ROOT_SKILL, self::OTHER_ROOT_SKILL, 'Fixture.Photos', 'Fixture.Reviews']),
            'stallRecovery' => $this->runStallRecovery(),
            'stallRecoveryShadow' => $this->runInShadow(AgentDecisionModelSettings::SITE_STALL_RECOVERY, 'stallRecovery'),
            'turnClosing' => $this->runTurnClosing(),
            'turnClosingShadow' => $this->runInShadow(AgentDecisionModelSettings::SITE_TURN_CLOSING, 'turnClosing'),
            'ownerConsentDecision' => $this->runOwnerConsentDecision(),
            'ownerConsentCheck' => $this->runOwnerConsentCheck(),
            'ownerConsentCheckShadow' => $this->runInShadow(AgentDecisionModelSettings::SITE_OWNER_CONSENT, 'ownerConsentCheck'),
            default => throw new LogicException('unknown scenario ' . $siteScenario),
        };
        $this->worldLog->record('result ' . $result);

        self::assertSame(OneChildDecisionSitesOutcomeTableCells::CELLS[$rowName] ?? ['<not pinned>'], $this->summarizeWorldLog(), $rowName);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function failingSubSkillApplicationRows(): array
    {
        return [
            'the follow-up pass' => ['skillPreloadFollowUpPass', 'pass2:'],
            'the sub-skill pass after the chat model loaded the skill' => ['subSkillPassAfterModelLoad', 'modelLoad:'],
        ];
    }

    #[DataProvider('failingSubSkillApplicationRows')]
    public function testAFailedSubSkillApplicationKeepsTheEventOfItsRequest(string $siteScenario, string $subjectPrefix): void
    {
        $result = $this->runFailingSubSkillApplication($siteScenario, $subjectPrefix, new RuntimeException('a failure inside the application'));

        self::assertContains('event request turnStart decidedBy="answered"', $this->worldLog->lines);
        self::assertContains($result, ['none (void)', 'sentence ""'], 'nothing is announced as loaded');
    }

    #[DataProvider('failingSubSkillApplicationRows')]
    public function testALostClaimInsideASubSkillApplicationIsRethrown(string $siteScenario, string $subjectPrefix): void
    {
        $this->expectException(AgentRunClaimLostException::class);
        $this->runFailingSubSkillApplication($siteScenario, $subjectPrefix, new AgentRunClaimLostException('another worker owns this conversation'));
    }

    protected function runFailingSubSkillApplication(string $siteScenario, string $subjectPrefix, Throwable $applyFailure): string
    {
        return $siteScenario === 'skillPreloadFollowUpPass'
            ? $this->runSkillPreloadFollowUpPass(1, [$subjectPrefix => $applyFailure])
            : $this->runSubSkillPassAfterModelLoad([self::ROOT_SKILL], [$subjectPrefix => $applyFailure]);
    }

    protected function runInShadow(string $site, string $siteScenario): string
    {
        $this->useSiteInShadow($site);
        return match ($siteScenario) {
            'stallRecovery' => $this->runStallRecovery(),
            'turnClosing' => $this->runTurnClosing(),
            'ownerConsentCheck' => $this->runOwnerConsentCheck(),
            default => throw new LogicException('unknown scenario ' . $siteScenario),
        };
    }

    /**
     * The follow-up pass under a root the pass before admitted.
     *
     * @param array<string, Throwable> $applyFailureBySubjectPrefix
     */
    protected function runSkillPreloadFollowUpPass(int $previousPass, array $applyFailureBySubjectPrefix = []): string
    {
        $skillPreloadServiceDouble = new SkillPreloadServiceWithScriptedInputsDouble($this->worldLog);
        $skillPreloadServiceDouble->applyFailureBySubjectPrefix = $applyFailureBySubjectPrefix;
        $skillPreloadServiceDouble->scriptedInputs['pass' . ($previousPass + 1) . ':' . self::ROOT_SKILL] = $this->subSkillPassQuestion('pass' . ($previousPass + 1), $previousPass + 1);
        $rootsQuestion = $this->skillPreloadRootsQuestion();
        $rootsQuestion->pass = $previousPass;
        $admittedRootDecision = new AgentSkillPreloadDecision();
        $admittedRootDecision->ownerMessageIndex = 2;
        $admittedRootDecision->wouldPreload = true;
        $admittedRootDecision->wouldApply = true;
        $admittedRootDecision->pickedSkillName = self::ROOT_SKILL;
        $admittedRootDecision->admittedSkillNames = [self::ROOT_SKILL];
        $admittedRootDecision->skipReason = null;
        self::callProtectedMethod($skillPreloadServiceDouble, 'decideSkillPreloadFollowUp', [$this->ownerTurnConversation(), $rootsQuestion, $admittedRootDecision, AgentDecisionModelSettings::current(), null]);
        return 'none (void)';
    }

    /**
     * The sub-skill pass after the chat model's own `load_skills`.
     *
     * @param string[] $loadedSkillNames
     * @param array<string, Throwable> $applyFailureBySubjectPrefix
     */
    protected function runSubSkillPassAfterModelLoad(array $loadedSkillNames, array $applyFailureBySubjectPrefix = []): string
    {
        $skillPreloadServiceDouble = new SkillPreloadServiceWithScriptedInputsDouble($this->worldLog);
        $skillPreloadServiceDouble->applyFailureBySubjectPrefix = $applyFailureBySubjectPrefix;
        $skillPreloadServiceDouble->scriptedInputs['modelLoad:' . self::ROOT_SKILL] = $this->subSkillPassQuestion('modelLoad');
        $this->decisionModelDouble->answersByLabel['subSkill:' . self::ROOT_SKILL] = array_intersect_key($this->decisionModelDouble->answersByQuestion, ['skill' => true]);
        unset($this->decisionModelDouble->answersByQuestion['skill']);
        $sentence = $skillPreloadServiceDouble->preloadSubSkillsOfSkillsLoadedByTheChatModel($this->ownerTurnConversation(), $loadedSkillNames);
        return 'sentence ' . json_encode($sentence, JSON_UNESCAPED_UNICODE);
    }

    /** Stall recovery at the second stall. */
    protected function runStallRecovery(): string
    {
        $agentStallRecoveryService = new AgentStallRecoveryService();
        $move = $agentStallRecoveryService->decideMove($this->ownerTurnConversation(), AgentStallRecoveryDecision::SHAPE_EMPTY, '', ['readOrders', 'writeOrder'], [self::OTHER_ROOT_SKILL], 2, false, null);
        return 'move ' . json_encode($move);
    }

    /** Turn closing on a candidate final text while a plan item owes a tool call. */
    protected function runTurnClosing(): string
    {
        $taskPlanItem = new TaskPlanItem();
        $taskPlanItem->text = 'Add the search terms';
        $taskPlanItem->status = TaskPlanItem::STATUS_OPEN;
        $taskPlanItem->doneRequiresToolCall = 'writeOrder';
        $taskPlanItems = new TaskPlanItems();
        $taskPlanItems->add($taskPlanItem);
        $taskPlan = new TaskPlan();
        $taskPlan->items = $taskPlanItems;
        $agentFinalTextAcceptanceService = new AgentFinalTextAcceptanceService();
        $agentTurnClosingOutcome = $agentFinalTextAcceptanceService->decideTurnClosing(
            $this->ownerTurnConversation(),
            $taskPlan,
            'I am starting on your search terms now and will get back to you shortly.',
            0,
            AgentDecisionModelSettings::TURN_CLOSING_MODE_DECISION_MODEL
        );
        return 'outcome ' . json_encode(['kind' => $agentTurnClosingOutcome->kind, 'wouldBounce' => $agentTurnClosingOutcome->wouldBounce, 'reason' => $agentTurnClosingOutcome->reason]);
    }

    protected function ownerConsentRequest(): OwnerConsentRequest
    {
        return OwnerConsentRequest::forAction('deleteAttachments', 'ATTACHMENTS_DELETE', 'delete 1 attachment (old-photo.png) from the attachments', 'this cannot be undone', '101');
    }

    /** The owner-consent decision over a built dialogue. */
    protected function runOwnerConsentDecision(): string
    {
        $ownerConsentDialogue = new OwnerConsentDialogue();
        $assistantLine = OwnerConsentDialogueLine::create(OwnerConsentDialogueLine::SPEAKER_ASSISTANT, 5, 'Shall I delete the old attachment old-photo.png? This cannot be undone.');
        $ownerLine = OwnerConsentDialogueLine::create(OwnerConsentDialogueLine::SPEAKER_OWNER, 6, 'Yes, delete it.');
        $ownerConsentDialogue->add($assistantLine);
        $ownerConsentDialogue->add($ownerLine);
        $ownerConsentsService = new OwnerConsentsService();
        $ownerConsentDecisionResult = $ownerConsentsService->decideDialogue($this->ownerConsentRequest(), $ownerConsentDialogue, 'chat', null, null, OwnerConsentVerdict::DECIDED_BY_DECISION_MODEL);
        $ownerConsentVerdict = $ownerConsentDecisionResult->verdict;
        $agentDecisionCallOutcome = $ownerConsentDecisionResult->callOutcome;
        return 'verdict ' . json_encode([
            'decidedBy' => $ownerConsentVerdict->decidedBy,
            'consented' => $ownerConsentVerdict->consented,
            'failure' => $ownerConsentVerdict->failure,
            'consentingOwnerMessageIndex' => $ownerConsentVerdict->consentingOwnerMessageIndex,
            'callOutcome' => $agentDecisionCallOutcome instanceof AgentDecisionCallOutcome ? $agentDecisionCallOutcome->outcome : null,
        ]);
    }

    /** The owner-consent check of a tool call, with its turn event. */
    protected function runOwnerConsentCheck(): string
    {
        $conversation = $this->conversation([
            $this->ownerText(4, 'Can you remove the old attachment?'),
            $this->assistantText(5, 'Shall I delete the old attachment old-photo.png? This cannot be undone.'),
            $this->ownerText(6, 'Yes, delete it.'),
        ]);
        $agentToolCallProvenance = new AgentToolCallProvenance(conversationId: self::CONVERSATION_ID, rootConversationId: self::CONVERSATION_ID, conversation: $conversation);
        $ownerConsentVerdict = self::callProtectedMethod(new OwnerConsentsService(), 'runConsentCheck', [$agentToolCallProvenance, $this->ownerConsentRequest(), 3]);
        return 'verdict ' . json_encode(['decidedBy' => $ownerConsentVerdict->decidedBy, 'consented' => $ownerConsentVerdict->consented, 'failure' => $ownerConsentVerdict->failure]);
    }
}
