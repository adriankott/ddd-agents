<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Services\Conversations\Agent\AgentSkillPreloadDecisionsService;
use DDD\Domain\AI\Services\Conversations\Agent\AgentStallRecoveryService;
use DDD\Domain\AI\Services\Conversations\Purposes\AIConversationPurposesService;
use DDD\Domain\AI\Services\Skills\AISkillsService;
use DDD\Tests\Agents\Domain\AI\Services\Eval\AISkillsServiceWithSubSkillsDouble;
use ReflectionClass;
use ReflectionProperty;

/**
 * The run reaches every catalog build of the turn start, the admission, the sub-skill pass after a load of the chat
 * model and the loop's loadable names: inside a run the root-purpose memo is the run's
 * (`AgentRun::$purposeNameByRootConversationId`), the process memo of `AIConversationPurposesService` is never read or
 * written.
 */
class TurnStartRunThreadingTest extends DecisionSiteOutcomeTestCase
{
    public function testTheTurnStartHandsItsRunToEverySkillPreloadCallIncludingTheFollowUpPassAndTheAdmission(): void
    {
        $skillsServiceDouble = new AISkillsServiceWithSubSkillsDouble();
        $skillsServiceDouble->subSkillNamesBySkillName = [self::ROOT_SKILL => [self::SUB_SKILL_ADDRESS, self::SUB_SKILL_LINKS]];
        $this->replaceService(AISkillsService::class, $skillsServiceDouble);
        $turnStartBatchService = $this->turnStartBatchService(TurnStartDecisionsOutcomeTableTest::ALL_TURN_START_SITES);
        $this->decisionModelDouble->answersByLabel['primary'] = ['skill' => strtolower(self::ROOT_SKILL)];
        $turnStartBatchService->skillPreloadServiceDouble->scriptedInputs['pass2:' . self::ROOT_SKILL] = $this->subSkillPassQuestion('pass2');
        $agentRun = AgentRun::forTurnEntry();

        $turnStartBatchService->decideTurnStart($agentRun, $this->ownerTurnConversation(), null);

        $receivedAgentRuns = $turnStartBatchService->skillPreloadServiceDouble->receivedAgentRuns;
        self::assertCount(7, $receivedAgentRuns, 'the roots input, the loaded-skill inputs, the roots application, its admission, the follow-up input, the follow-up application, the loaded-skill application');
        foreach ($receivedAgentRuns as $position => $receivedAgentRun) {
            self::assertSame($agentRun, $receivedAgentRun, 'call #' . $position . ' got the turn\'s run');
        }
    }

    public function testEveryThreadedCatalogBuildReadsTheRunAndNeverTheProcessMemo(): void
    {
        $processMemo = new ReflectionProperty(AIConversationPurposesService::class, 'purposeNameByRootConversationId');
        $processMemoBefore = $processMemo->getValue();
        $agentRun = AgentRun::forTurnEntry();
        $conversation = $this->catalogBuildRecordingConversation();
        $agentSkillPreloadDecisionsService = new AgentSkillPreloadDecisionsService();

        self::assertNull($agentSkillPreloadDecisionsService->buildSkillPreloadRequestInput($conversation, AgentDecisionModelSettings::current(), agentRun: $agentRun));
        self::assertTrue($agentSkillPreloadDecisionsService->buildLoadedSkillSubSkillPreloadRequestInputs($conversation, AgentDecisionModelSettings::current(), $agentRun)->isEmpty());
        self::assertSame('', $agentSkillPreloadDecisionsService->preloadSubSkillsOfSkillsLoadedByTheChatModel($conversation, [self::ROOT_SKILL], $agentRun));
        (new AgentStallRecoveryService())->buildLoadableCapabilityNames($agentRun, $conversation, []);
        self::assertCount(4, $conversation->catalogBuildAgentRuns, 'the roots pass, the loaded-skill inputs, the pass after a load of the chat model, the loop\'s loadable names');

        $purposedConversation = $this->catalogBuildRecordingConversation();
        $purposedConversation->purposeName = 'Fixture.Purpose';
        $taskPlanItem = new TaskPlanItem();
        $taskPlanItem->text = 'Search terms';
        $taskPlanItem->status = TaskPlanItem::STATUS_OPEN;
        $taskPlanItem->key = '1';
        $taskPlanItem->skillNames = [self::OTHER_ROOT_SKILL];
        $taskPlanItems = new TaskPlanItems();
        $taskPlanItems->add($taskPlanItem);
        $taskPlan = new TaskPlan();
        $taskPlan->items = $taskPlanItems;
        $agentWorkingState = new AgentWorkingState();
        $agentWorkingState->taskPlan = $taskPlan;
        $purposedConversation->agentWorkingState = $agentWorkingState;
        (new AgentSkillPreloadDecisionsService())->admitSkillsForNextPlanItem($purposedConversation, null, null, $agentRun);
        self::assertCount(1, $purposedConversation->catalogBuildAgentRuns, 'the plan-item admission');

        foreach (array_merge($conversation->catalogBuildAgentRuns, $purposedConversation->catalogBuildAgentRuns) as $position => $catalogBuildAgentRun) {
            self::assertSame($agentRun, $catalogBuildAgentRun, 'catalog build #' . $position . ' read the run');
        }
        self::assertSame($processMemoBefore, $processMemo->getValue(), 'the process memo is untouched by a run');
    }

    protected function catalogBuildRecordingConversation(): CatalogBuildRecordingConversation
    {
        $messages = new AIConversationMessages();
        foreach ($this->ownerTurnConversation()->messages->getElements() as $message) {
            $messages->add($message);
        }
        /** @var CatalogBuildRecordingConversation $conversation */
        $conversation = (new ReflectionClass(CatalogBuildRecordingConversation::class))->newInstanceWithoutConstructor();
        $conversation->id = self::CONVERSATION_ID;
        $conversation->messages = $messages;
        return $conversation;
    }
}
