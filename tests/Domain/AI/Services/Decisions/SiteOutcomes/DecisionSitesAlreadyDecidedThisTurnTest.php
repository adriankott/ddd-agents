<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionRecord;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentPlanDynamicsDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReasoningEffortDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReplyLanguageDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Services\Conversations\Agent\AgentPlanDynamicsDecisionsService;
use DDD\Domain\AI\Services\Conversations\Agent\AgentReplyLanguageDecisionsService;
use DDD\Domain\AI\Services\Conversations\Agent\AgentSkillPreloadDecisionsService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerMessageScreeningService;

/**
 * "Already decided this turn": a chunk resume of the same owner turn asks no site twice. Three mechanisms, one per group
 * of sites:
 * 1. the working-state slice carries the owner message index (reply language, reasoning effort, plan dynamics);
 * 2. the turn-events table holds a `decision` event of the site for the initiator (the skill-preload roots pass and the
 *    loaded skills' sub-skill inputs) — one read per pass, keyed by site and initiator, never by subject;
 * 3. the decision log's cards (the owner-message injection check) — the log keeps the newest injection card past its
 *    cap, so newer cards never make the same message be checked again.
 * Plus the breaker-close rule and the refusal stamp per site, read off the outcome tables of the first package.
 *
 * Not here: the stall recovery's "once per turn" lives in the loop's own stall memory; "a failed event read counts as
 * decided" is a database failure this stage does not produce.
 */
class DecisionSitesAlreadyDecidedThisTurnTest extends DecisionSiteOutcomeTestCase
{
    // --- mechanism 1: the slice's owner message index ---------------------------------------------------------------

    public function testTheReplyLanguageSliceOfTheSameOwnerMessageAsksNothing(): void
    {
        $agentWorkingState = new AgentWorkingState();
        $replyLanguageDecision = new AgentReplyLanguageDecision();
        $replyLanguageDecision->ownerMessageIndex = 2;
        $replyLanguageDecision->languageCode = 'de';
        $replyLanguageDecision->source = AgentReplyLanguageDecision::SOURCE_WRITTEN;
        $replyLanguageDecision->decidedBy = AgentReplyLanguageDecision::DECIDED_BY_DECISION_MODEL;
        $agentWorkingState->replyLanguageDecision = $replyLanguageDecision;
        $agentReplyLanguageDecisionsService = new AgentReplyLanguageDecisionsService();

        self::assertNull($agentReplyLanguageDecisionsService->buildRequestInput($this->ownerTurnConversation($agentWorkingState), AgentDecisionModelSettings::current()), 'decided for @2: a chunk resume asks nothing');
        $replyLanguageDecision->ownerMessageIndex = 0;
        $languageQuestion = $agentReplyLanguageDecisionsService->buildRequestInput($this->ownerTurnConversation($agentWorkingState), AgentDecisionModelSettings::current());
        self::assertNotNull($languageQuestion);
        self::assertSame(2, $languageQuestion->ownerMessageIndex, 'a stamp of an older owner message: the new one is asked');
        self::assertSame('de', $languageQuestion->anchorLanguageCode, 'the older stamp is the sticky anchor');
    }

    public function testTheReasoningEffortSliceOfTheSameInitiatorAsksNothing(): void
    {
        $agentWorkingState = new AgentWorkingState();
        $reasoningEffortDecision = new AgentReasoningEffortDecision();
        $reasoningEffortDecision->ownerMessageIndex = 2;
        $agentWorkingState->reasoningEffortDecision = $reasoningEffortDecision;
        $reasoningEffortServiceDouble = new ReasoningEffortServiceWithScriptedReadsDouble();
        $reasoningEffortServiceDouble->runsThePackagesRequestInput = true;

        self::assertNull($reasoningEffortServiceDouble->buildReasoningEffortRequestInput($this->ownerTurnConversation($agentWorkingState), AgentDecisionModelSettings::current()));
        $reasoningEffortDecision->ownerMessageIndex = 0;
        $reasoningEffortQuestion = $reasoningEffortServiceDouble->buildReasoningEffortRequestInput($this->ownerTurnConversation($agentWorkingState), AgentDecisionModelSettings::current());
        self::assertNotNull($reasoningEffortQuestion);
        self::assertSame(2, $reasoningEffortQuestion->initiatorMessageIndex);
        self::assertTrue($reasoningEffortQuestion->askDecisionModel, 'the owner message has at least ten words: the word floor admits the questions');
        self::assertSame(AgentReasoningEffortDecision::PRECONDITION_WORD_FLOOR, $reasoningEffortQuestion->preconditionReason);
    }

    public function testThePlanDynamicsSliceOfTheSameInitiatorOrAnActivePlanWithOwedItemsAsksNothing(): void
    {
        $agentPlanDynamicsDecisionsService = new AgentPlanDynamicsDecisionsService();
        $agentWorkingState = new AgentWorkingState();
        $agentWorkingState->planDynamicsDecision = AgentPlanDynamicsDecision::build(2);
        self::assertNull($agentPlanDynamicsDecisionsService->buildRequestInput($this->ownerTurnConversation($agentWorkingState), AgentDecisionModelSettings::current(), false, false), 'decided for @2');

        $agentWorkingState = new AgentWorkingState();
        $agentWorkingState->taskPlan = $this->taskPlanWithOneOpenItem();
        self::assertNull($agentPlanDynamicsDecisionsService->buildRequestInput($this->ownerTurnConversation($agentWorkingState), AgentDecisionModelSettings::current(), false, false), 'an active plan with owed items is never asked again');

        $agentWorkingState = new AgentWorkingState();
        $agentWorkingState->planDynamicsDecision = AgentPlanDynamicsDecision::build(0);
        self::assertSame(2, $agentPlanDynamicsDecisionsService->buildRequestInput($this->ownerTurnConversation($agentWorkingState), AgentDecisionModelSettings::current(), false, false)?->initiatorMessageIndex, 'a stamp of an older owner message: the new one is asked');
        self::assertNull($agentPlanDynamicsDecisionsService->buildRequestInput($this->ownerTurnConversation(), AgentDecisionModelSettings::current(), false, true), 'a purpose that seeds its plan is never asked');
        self::assertNull($agentPlanDynamicsDecisionsService->buildRequestInput($this->ownerTurnConversation(), AgentDecisionModelSettings::current(), true, false), 'a purpose that works on without the owner is never asked');
    }

    // --- mechanism 2: the turn-events table --------------------------------------------------------------------------

    public function testAnExistingSkillPreloadDecisionEventStopsTheRootsPassAndTheLoadedSkillsSubSkillInputs(): void
    {
        $this->turnEventsServiceDouble->existingDecisionEvents = [AgentDecisionModelSettings::SITE_SKILL_PRELOAD . '@2'];
        $agentSkillPreloadDecisionsService = new AgentSkillPreloadDecisionsService();

        self::assertNull($agentSkillPreloadDecisionsService->buildSkillPreloadRequestInput($this->ownerTurnConversation(), AgentDecisionModelSettings::current()));
        self::assertTrue($agentSkillPreloadDecisionsService->buildLoadedSkillSubSkillPreloadRequestInputs($this->ownerTurnConversation(), AgentDecisionModelSettings::current())->isEmpty());
        self::assertSame(
            [AIConversationTurnEvent::KIND_DECISION . ' skillPreload@2', AIConversationTurnEvent::KIND_DECISION . ' skillPreload@2'],
            $this->turnEventsServiceDouble->hasEventForReads,
            'one read per pass, keyed by site and initiator — never by the subject (a pass-2 or loaded-skill event stops the roots pass too)'
        );
    }

    // --- mechanism 3: the decision log's cards -----------------------------------------------------------------------

    public function testTheInjectionCheckFindsItsCardEvenAfterAFullLogOfNewerCards(): void
    {
        $aIConversationOwnerMessageScreeningService = new AIConversationOwnerMessageScreeningService();
        $agentWorkingState = new AgentWorkingState();
        self::assertSame(2, $aIConversationOwnerMessageScreeningService->findOwnerMessageForInjectionCheck($this->ownerTurnConversation($agentWorkingState), AgentDecisionModelSettings::current())?->indexInConversation, 'not checked yet: the owner message @2 is found');
        $agentWorkingState->appendDecisionRecord($this->decisionCard(AgentDecisionModelSettings::SITE_STALL_RECOVERY, 2));
        self::assertSame(2, $aIConversationOwnerMessageScreeningService->findOwnerMessageForInjectionCheck($this->ownerTurnConversation($agentWorkingState), AgentDecisionModelSettings::current())?->indexInConversation, 'a card of another site for @2 is no check');
        $agentWorkingState->appendDecisionRecord($this->decisionCard(AgentDecisionModelSettings::SITE_OWNER_MESSAGE_INJECTION, 2));
        self::assertNull($aIConversationOwnerMessageScreeningService->findOwnerMessageForInjectionCheck($this->ownerTurnConversation($agentWorkingState), AgentDecisionModelSettings::current()), 'checked: its card is in the log');

        for ($newerCard = 1; $newerCard <= AgentWorkingState::MAX_DECISION_RECORDS - 1; $newerCard++) {
            $agentWorkingState->appendDecisionRecord($this->decisionCard(AgentDecisionModelSettings::SITE_STALL_RECOVERY, 2));
        }
        self::assertCount(AgentWorkingState::MAX_DECISION_RECORDS, $agentWorkingState->decisions);
        self::assertNull($aIConversationOwnerMessageScreeningService->findOwnerMessageForInjectionCheck($this->ownerTurnConversation($agentWorkingState), AgentDecisionModelSettings::current()), 'a full log of newer cards: still found');

        $agentWorkingState->appendDecisionRecord($this->decisionCard(AgentDecisionModelSettings::SITE_STALL_RECOVERY, 2));
        self::assertCount(AgentWorkingState::MAX_DECISION_RECORDS, $agentWorkingState->decisions, 'the cap holds');
        self::assertSame(AgentDecisionModelSettings::SITE_OWNER_MESSAGE_INJECTION, $agentWorkingState->decisions[0]->site, 'the injection card stays as the oldest kept card');
        self::assertNull($aIConversationOwnerMessageScreeningService->findOwnerMessageForInjectionCheck($this->ownerTurnConversation($agentWorkingState), AgentDecisionModelSettings::current()), 'one card over the cap: still found, nothing is checked again');

        for ($newerCard = 1; $newerCard <= AgentWorkingState::MAX_DECISION_RECORDS; $newerCard++) {
            $agentWorkingState->appendDecisionRecord($this->decisionCard(AgentDecisionModelSettings::SITE_STALL_RECOVERY, 2));
        }
        self::assertCount(AgentWorkingState::MAX_DECISION_RECORDS, $agentWorkingState->decisions);
        self::assertSame(AgentDecisionModelSettings::SITE_OWNER_MESSAGE_INJECTION, $agentWorkingState->decisions[0]->site, 'still kept after another full log');
        self::assertSame(AgentDecisionModelSettings::SITE_STALL_RECOVERY, $agentWorkingState->decisions[1]->site);
    }

    // --- the close rule and the refusal stamp, one row per site ------------------------------------------------------

    /**
     * Read off the outcome tables: does the site ask to close the breaker after a parsable answer, after an unparsable
     * answer, and what does it write when our own gate refused the call?
     */
    public function testTheCloseRuleAndTheRefusalStampPerSite(): void
    {
        $turnStartCells = TurnStartDecisionsOutcomeTableCells::CELLS;
        $oneChildCells = OneChildDecisionSitesOutcomeTableCells::CELLS;
        $rowsBySite = [
            'replyLanguage' => [$turnStartCells, 'replyLanguage'],
            'skillPreload roots' => [$turnStartCells, 'skillPreload'],
            'skillPreload follow-up pass' => [$oneChildCells, 'skillPreloadFollowUpPass'],
            'skillPreload loaded skill child' => [$turnStartCells, 'loadedSkillSubSkills'],
            'skillPreload after the model load' => [$oneChildCells, 'subSkillPassAfterModelLoad'],
            'reasoningEffort' => [$turnStartCells, 'reasoningEffort'],
            'planDynamics' => [$turnStartCells, 'planDynamics'],
            'ownerMessageInjection' => [$turnStartCells, 'ownerMessageInjection'],
            'stallRecovery' => [$oneChildCells, 'stallRecovery'],
            'turnClosing' => [$oneChildCells, 'turnClosing'],
            'ownerConsent' => [$oneChildCells, 'ownerConsentDecision'],
        ];
        $closeRulesAndRefusalStamps = [];
        foreach ($rowsBySite as $siteName => [$cells, $rowPrefix]) {
            $closeRulesAndRefusalStamps[$siteName] = self::describeCloseRuleAndRefusal($cells, $rowPrefix);
        }
        self::assertSame(DecisionSitesAlreadyDecidedThisTurnCells::CLOSE_RULES_AND_REFUSAL_STAMPS, $closeRulesAndRefusalStamps);
    }

    /** @param array<string, string[]> $cells */
    public static function describeCloseRuleAndRefusal(array $cells, string $rowPrefix): string
    {
        $closesOnParsable = in_array('breaker close', $cells[$rowPrefix . ' × usable'], true);
        $closesOnUnparsable = in_array('breaker close', $cells[$rowPrefix . ' × unparsable'], true);
        $closeRule = match (true) {
            $closesOnParsable && $closesOnUnparsable => 'closes on any answer',
            $closesOnParsable => 'closes on a parsable answer only',
            default => 'never closes',
        };
        $refusalCells = array_values(array_filter($cells[$rowPrefix . ' × refused'], static fn (string $cell): bool => str_starts_with($cell, 'card ') || str_starts_with($cell, 'result ') || str_starts_with($cell, 'event ')));
        return $closeRule . ' | refused: ' . ($refusalCells === [] ? 'nothing written' : implode('; ', $refusalCells));
    }

    protected function decisionCard(string $site, int $ownerMessageIndex): AgentDecisionRecord
    {
        $agentDecisionRecord = new AgentDecisionRecord();
        $agentDecisionRecord->site = $site;
        $agentDecisionRecord->ownerMessageIndex = $ownerMessageIndex;
        $agentDecisionRecord->decidedBy = 'jev';
        $agentDecisionRecord->applied = false;
        $agentDecisionRecord->outcome = [];
        return $agentDecisionRecord;
    }

    protected function taskPlanWithOneOpenItem(): TaskPlan
    {
        $taskPlanItem = new TaskPlanItem();
        $taskPlanItem->key = '1';
        $taskPlanItem->text = 'Answer the six reviews';
        $taskPlanItem->status = TaskPlanItem::STATUS_OPEN;
        $taskPlanItems = new TaskPlanItems();
        $taskPlanItems->add($taskPlanItem);
        $taskPlan = new TaskPlan();
        $taskPlan->items = $taskPlanItems;
        return $taskPlan;
    }
}
