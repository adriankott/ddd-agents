<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals\ConversationPurge;

use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeKeys;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeStep;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeSteps;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The step list of the purge in the order of a key list: an application interleaves its own steps with the module's
 * by naming every key in its order, and a step it does not name keeps its place after the named ones.
 */
class AIConversationEvalPurgeStepsTest extends UnitTestCase
{
    public function testTheStepsFollowTheKeyListAndUnnamedStepsFollowInTheirOwnOrder(): void
    {
        $hostCalls = AIConversationEvalPurgeStep::deleteRows('EntityCalls', 'aiConversationId', AIConversationEvalPurgeKeys::KIND_CONVERSATION);
        $hostRings = AIConversationEvalPurgeStep::deleteRows('EntityCallRings', 'callId', 'call');
        $turnEvents = AIConversationEvalPurgeStep::deleteRows('EntityAIConversationTurnEvents', 'aiConversationId', AIConversationEvalPurgeKeys::KIND_CONVERSATION);
        $messages = AIConversationEvalPurgeStep::deleteRows('EntityTempAIConversationMessages', 'id', AIConversationEvalPurgeKeys::KIND_MESSAGE, hasHeavyRows: true);
        $conversations = AIConversationEvalPurgeStep::deleteRows('EntityTempAIConversations', 'id', AIConversationEvalPurgeKeys::KIND_CONVERSATION, hasHeavyRows: true);
        $steps = AIConversationEvalPurgeSteps::newInstance();
        $steps->add($hostCalls, $turnEvents, $hostRings, $messages, $conversations);

        $orderedSteps = $steps->buildOrderedByStepKeys([
            $hostRings->getStepKey(),
            $hostCalls->getStepKey(),
            'delete:EntityGone.id',
            $messages->getStepKey(),
        ]);

        self::assertSame([$hostRings, $hostCalls, $messages, $turnEvents, $conversations], $orderedSteps->getElements(), 'a named key without a step is passed over');
        self::assertSame(
            [$hostCalls, $turnEvents, $hostRings, $messages, $conversations],
            $steps->getElements(),
            'the set the order is built from stays as it was'
        );
    }

    public function testStepsSharingAKeyKeepTheirOrderAtTheKeysPosition(): void
    {
        $firstDelete = AIConversationEvalPurgeStep::deleteRows('EntityNotes', 'aiConversationId', AIConversationEvalPurgeKeys::KIND_CONVERSATION);
        $secondDelete = AIConversationEvalPurgeStep::deleteRows('EntityNotes', 'aiConversationId', AIConversationEvalPurgeKeys::KIND_CONVERSATION);
        $conversations = AIConversationEvalPurgeStep::deleteRows('EntityTempAIConversations', 'id', AIConversationEvalPurgeKeys::KIND_CONVERSATION);
        $steps = AIConversationEvalPurgeSteps::newInstance();
        $steps->add($conversations, $firstDelete, $secondDelete);

        $orderedSteps = $steps->buildOrderedByStepKeys([$firstDelete->getStepKey(), $firstDelete->getStepKey()]);

        self::assertSame([$firstDelete, $secondDelete, $conversations], $orderedSteps->getElements());
    }

    public function testAnEmptyKeyListKeepsTheOrderAndAMergeAppendsTheOtherList(): void
    {
        $turnEvents = AIConversationEvalPurgeStep::deleteRows('EntityAIConversationTurnEvents', 'aiConversationId', AIConversationEvalPurgeKeys::KIND_CONVERSATION);
        $memories = AIConversationEvalPurgeStep::nullifyColumn('EntityAccountMemories', 'sourceMessageId', AIConversationEvalPurgeKeys::KIND_MESSAGE);
        $otherTurnEvents = AIConversationEvalPurgeStep::deleteRows('EntityAIConversationTurnEvents', 'id', 'turnEvent');
        $steps = AIConversationEvalPurgeSteps::newInstance();
        $steps->add($turnEvents, $memories);
        $otherSteps = AIConversationEvalPurgeSteps::newInstance();
        $otherSteps->add($otherTurnEvents);

        $mergedSteps = $steps->buildMergedWith($otherSteps);

        self::assertSame([$turnEvents, $memories], $steps->buildOrderedByStepKeys([])->getElements());
        self::assertSame([$turnEvents, $memories, $otherTurnEvents], $mergedSteps->getElements());
        self::assertSame(2, $steps->count(), 'the merge changes neither list');
        self::assertSame(['EntityAIConversationTurnEvents', 'EntityAccountMemories'], $mergedSteps->getTableNames());
        self::assertSame(['EntityAIConversationTurnEvents', 'EntityAccountMemories.sourceMessageId = NULL', 'EntityAIConversationTurnEvents'], $mergedSteps->getReportLabels());
        self::assertSame(['delete:EntityAIConversationTurnEvents.aiConversationId', 'nullify:EntityAccountMemories.sourceMessageId', 'delete:EntityAIConversationTurnEvents.id'], $mergedSteps->getStepKeys());
        self::assertSame($memories, $mergedSteps->findStepByStepKey('nullify:EntityAccountMemories.sourceMessageId'));
        self::assertNull($mergedSteps->findStepByStepKey('delete:EntityGone.id'));
    }
}
