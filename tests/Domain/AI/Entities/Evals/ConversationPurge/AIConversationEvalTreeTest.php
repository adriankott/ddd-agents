<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals\ConversationPurge;

use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalTree;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalTreeConversation;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalTreeConversations;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalTrees;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * One conversation tree of the evaluation purge: built from the conversations the service read, decided by its
 * verdict. The checks run in a fixed order and the first that fails names the verdict.
 */
class AIConversationEvalTreeTest extends UnitTestCase
{
    protected const string ACTIVITY_CUTOFF = '2026-03-10 12:00:00';

    protected const string FRESH_HEARTBEAT_CUTOFF = '2026-03-12 11:30:00';

    public function testTheTreeCollectsItsMembersTheRootAccountTheNewestHeartbeatAndActivity(): void
    {
        $root = AIConversationEvalTreeConversation::forConversation(10, 7, AIConversationRunState::COMPLETED, false);
        $root->setActivityMoment('created', '2026-03-01 08:00:00');
        $root->setActivityMoment('runHeartbeatAt', '2026-03-02 09:00:00');
        $child = AIConversationEvalTreeConversation::forConversation(11, 8, null, false);
        $child->setActivityMoment('updated', '2026-03-03 10:00:00');
        $child->setActivityMoment('finalizedAt', '');
        $child->setActivityMoment('notAnActivityColumn', '2026-04-01 00:00:00');

        $tree = AIConversationEvalTree::fromConversations(10, self::buildConversations($child, $root, $child), '2026-03-02 23:00:00');

        self::assertSame([10, 11], $tree->conversationIds, 'the root first, every member once');
        self::assertSame(7, $tree->rootAccountId, 'the account of the root row');
        self::assertFalse($tree->hasRunInFlight);
        self::assertSame('2026-03-02 09:00:00', $tree->newestHeartbeatAt);
        self::assertSame('2026-03-03 10:00:00', $tree->newestActivityAt, 'an unknown column and an empty moment do not count');
        self::assertSame(['2026-03-03 10:00:00'], $child->getActivityMoments());
    }

    public function testTheNewestMessageCountsAsActivityAndARootWithoutARowHasNoAccount(): void
    {
        $child = AIConversationEvalTreeConversation::forConversation(11, 8, null, false);
        $child->setActivityMoment('created', '2026-03-01 08:00:00');

        $tree = AIConversationEvalTree::fromConversations(10, self::buildConversations($child), '2026-03-05 08:00:00');

        self::assertSame([10, 11], $tree->conversationIds);
        self::assertNull($tree->rootAccountId);
        self::assertSame('2026-03-05 08:00:00', $tree->newestActivityAt);
        self::assertSame(AIConversationEvalTree::VERDICT_SKIP_NOT_EVAL_ACCOUNT, $tree->decidePurgeVerdict([8], self::ACTIVITY_CUTOFF, self::FRESH_HEARTBEAT_CUTOFF));
    }

    public function testTheVerdictsRunInTheirOrder(): void
    {
        $runningChild = AIConversationEvalTreeConversation::forConversation(11, 7, AIConversationRunState::RUNNING, false);
        $runningChild->setActivityMoment('runHeartbeatAt', '2026-03-12 11:45:00');
        self::assertSame(
            AIConversationEvalTree::VERDICT_SKIP_NOT_EVAL_ACCOUNT,
            self::buildOldTree($runningChild)->decidePurgeVerdict([99], self::ACTIVITY_CUTOFF, self::FRESH_HEARTBEAT_CUTOFF),
            'an account outside the evaluation accounts goes first'
        );
        self::assertSame(AIConversationEvalTree::VERDICT_SKIP_RUN_IN_FLIGHT, self::buildOldTree($runningChild)->decidePurgeVerdict(['7'], self::ACTIVITY_CUTOFF, self::FRESH_HEARTBEAT_CUTOFF));

        $tokenChild = AIConversationEvalTreeConversation::forConversation(11, 7, AIConversationRunState::COMPLETED, true);
        self::assertSame(AIConversationEvalTree::VERDICT_SKIP_RUN_IN_FLIGHT, self::buildOldTree($tokenChild)->decidePurgeVerdict([7], self::ACTIVITY_CUTOFF, self::FRESH_HEARTBEAT_CUTOFF), 'a run token is a run in flight');

        $beatingChild = AIConversationEvalTreeConversation::forConversation(11, 7, AIConversationRunState::COMPLETED, false);
        $beatingChild->setActivityMoment('runHeartbeatAt', '2026-03-12 11:45:00');
        self::assertSame(AIConversationEvalTree::VERDICT_SKIP_FRESH_HEARTBEAT, self::buildOldTree($beatingChild)->decidePurgeVerdict([7], self::ACTIVITY_CUTOFF, self::FRESH_HEARTBEAT_CUTOFF));

        $recentChild = AIConversationEvalTreeConversation::forConversation(11, 7, AIConversationRunState::COMPLETED, false);
        $recentChild->setActivityMoment('lastInboundMessageAt', self::ACTIVITY_CUTOFF);
        self::assertSame(AIConversationEvalTree::VERDICT_SKIP_RECENT_ACTIVITY, self::buildOldTree($recentChild)->decidePurgeVerdict([7], self::ACTIVITY_CUTOFF, self::FRESH_HEARTBEAT_CUTOFF), 'activity at the cutoff keeps the tree');

        $idleChild = AIConversationEvalTreeConversation::forConversation(11, 7, AIConversationRunState::COMPLETED, false);
        self::assertSame(AIConversationEvalTree::VERDICT_PURGE, self::buildOldTree($idleChild)->decidePurgeVerdict([7], self::ACTIVITY_CUTOFF, self::FRESH_HEARTBEAT_CUTOFF));
    }

    public function testATreeWithoutAnyStampIsKept(): void
    {
        $root = AIConversationEvalTreeConversation::forConversation(10, 7, null, false);

        $tree = AIConversationEvalTree::fromConversations(10, self::buildConversations($root), null);

        self::assertSame(AIConversationEvalTree::VERDICT_SKIP_RECENT_ACTIVITY, $tree->decidePurgeVerdict([7], self::ACTIVITY_CUTOFF, self::FRESH_HEARTBEAT_CUTOFF), 'no measurable age — never guessed old');
    }

    public function testTheTreesOfABatchNameTheirRootsAndEveryConversationOnce(): void
    {
        $firstTree = AIConversationEvalTree::fromConversations(10, self::buildConversations(AIConversationEvalTreeConversation::forConversation(11, 7, null, false)), null);
        $secondTree = AIConversationEvalTree::fromConversations(20, self::buildConversations(AIConversationEvalTreeConversation::forConversation(11, 7, null, false)), null);
        $trees = AIConversationEvalTrees::newInstance();
        $trees->add($firstTree, $secondTree);

        self::assertSame([10, 20], $trees->getRootConversationIds());
        self::assertSame([10, 11, 20], $trees->getConversationIds());
    }

    /** A tree of root 10 (account 7, old) and the given child. */
    protected static function buildOldTree(AIConversationEvalTreeConversation $child): AIConversationEvalTree
    {
        $root = AIConversationEvalTreeConversation::forConversation(10, 7, AIConversationRunState::COMPLETED, false);
        $root->setActivityMoment('created', '2026-03-01 08:00:00');
        return AIConversationEvalTree::fromConversations(10, self::buildConversations($root, $child), '2026-03-01 09:00:00');
    }

    protected static function buildConversations(AIConversationEvalTreeConversation ...$treeConversations): AIConversationEvalTreeConversations
    {
        $aIConversationEvalTreeConversations = AIConversationEvalTreeConversations::newInstance();
        foreach ($treeConversations as $treeConversation) {
            $aIConversationEvalTreeConversations->add($treeConversation);
        }
        return $aIConversationEvalTreeConversations;
    }
}
