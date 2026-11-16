<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\DoneCriterionRecheck;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The re-check of the open done criteria after a completed tool is recorded as the turn event `doneCriterionRecheck`:
 * per criterion leaf the re-check read, its key, criterion and the status before and after — so a re-check that did not
 * see the tool's write shows as a leaf still open.
 */
class DoneCriterionRecheckIsRecordedPerKeyTest extends UnitTestCase
{
    public function testTheVerdictOfEveryReReadCriterionLeafIsRecorded(): void
    {
        $taskPlanItems = TaskPlanItems::fromPurposeSeedDefinition([
            ['text' => 'Services saved', 'doneCriterion' => 'servicesSaved'],
            ['text' => 'Prices saved', 'doneCriterion' => 'pricesSaved'],
            ['text' => 'Agent-decided step'],
        ]);
        $recordService = new TaskPlanRecordServiceWithRecordedTurnEvents();
        $conversation = new AIConversation();
        $conversation->id = 900;

        $statusesBefore = $recordService->findStatusesOfCriterionLeaves($taskPlanItems, ['servicesSaved', 'pricesSaved']);
        self::assertSame(['1' => TaskPlanItem::STATUS_OPEN, '2' => TaskPlanItem::STATUS_OPEN], $statusesBefore);
        self::assertSame(['1' => TaskPlanItem::STATUS_OPEN], $recordService->findStatusesOfCriterionLeaves($taskPlanItems, ['servicesSaved']));

        $taskPlanItems->getElements()[0]->status = TaskPlanItem::STATUS_DONE;
        $recordService->recordRecheck($conversation, $taskPlanItems, $statusesBefore, 'saveServices', 1);

        $recordedEvents = $recordService->recordingTurnEventsService->recordedEvents;
        self::assertCount(1, $recordedEvents);
        self::assertSame(AIConversationTurnEvent::KIND_DONE_CRITERION_RECHECK, $recordedEvents[0]['kind']);
        self::assertSame('after:saveServices', $recordedEvents[0]['subject']);
        self::assertSame(1, $recordedEvents[0]['payload']['resolvedLeafCount']);
        self::assertSame([
            ['itemKey' => '1', 'doneCriterion' => 'servicesSaved', 'statusBefore' => TaskPlanItem::STATUS_OPEN, 'statusAfter' => TaskPlanItem::STATUS_DONE],
            ['itemKey' => '2', 'doneCriterion' => 'pricesSaved', 'statusBefore' => TaskPlanItem::STATUS_OPEN, 'statusAfter' => TaskPlanItem::STATUS_OPEN],
        ], $recordedEvents[0]['payload']['criteria']);
    }

    public function testARecheckWithoutACriterionLeafRecordsNothing(): void
    {
        $recordService = new TaskPlanRecordServiceWithRecordedTurnEvents();
        $conversation = new AIConversation();
        $conversation->id = 900;

        $recordService->recordRecheck($conversation, TaskPlanItems::fromPurposeSeedDefinition([['text' => 'Agent-decided step']]), [], 'saveServices', 0);

        self::assertSame([], $recordService->recordingTurnEventsService->recordedEvents);
    }
}
