<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\ConversationPurge;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One conversation tree of an evaluation account as the evaluation purge sees it: the root (a top-level conversation,
 * or a child whose root row is already gone), its delegation children (`rootConversationId` = the root) and the
 * supervisor tandems of any of them (`supervisedConversationId`), with the run facts that decide whether the tree may
 * go.
 *
 * Pure: built from the conversations the service read by {@see fromConversations()}, decided by
 * {@see decidePurgeVerdict()}; the service reads the rows and deletes. All moments are UTC `Y-m-d H:i:s` strings as the
 * database stores them, so they compare as strings.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationEvalTree extends ValueObject
{
    /** @var string Every check passed: the tree is deleted with all its dependent rows. */
    public const string VERDICT_PURGE = 'purge';

    /** @var string The root does not belong to a configured evaluation account — never touched. */
    public const string VERDICT_SKIP_NOT_EVAL_ACCOUNT = 'skipNotEvalAccount';

    /** @var string A conversation of the tree is RUNNING / AWAITING_DELEGATES / QUEUED or holds a run token. */
    public const string VERDICT_SKIP_RUN_IN_FLIGHT = 'skipRunInFlight';

    /** @var string A conversation of the tree wrote a run heartbeat inside the fresh-heartbeat window. */
    public const string VERDICT_SKIP_FRESH_HEARTBEAT = 'skipFreshHeartbeat';

    /** @var string The newest activity of the tree (any conversation stamp or message) is younger than the age limit. */
    public const string VERDICT_SKIP_RECENT_ACTIVITY = 'skipRecentActivity';

    /** @var string[] The conversation columns that count as activity (besides the newest message's `created`). */
    public const array ACTIVITY_COLUMNS = AIConversationEvalTreeConversation::ACTIVITY_COLUMNS;

    public int $rootConversationId = 0;

    /** @var int|null The account of the root conversation. */
    public ?int $rootAccountId = null;

    /** @var int[] Every conversation id of the tree, the root first. */
    public array $conversationIds = [];

    /** @var bool A conversation of the tree has a non-terminal run state or a run token. */
    public bool $hasRunInFlight = false;

    /** @var string|null The newest `runHeartbeatAt` of the tree. */
    public ?string $newestHeartbeatAt = null;

    /** @var string|null The newest activity moment of the tree: every {@see ACTIVITY_COLUMNS} value and the newest message. */
    public ?string $newestActivityAt = null;

    /**
     * The tree of `$rootConversationId` from its conversations. The root is always the first conversation of the tree,
     * also when its row was not read; its account is the root row's.
     *
     * @param AIConversationEvalTreeConversations $treeConversations every conversation of the tree that was read
     * @param string|null $newestMessageCreatedAt `MAX(created)` over the messages of every conversation of the tree
     */
    public static function fromConversations(int $rootConversationId, AIConversationEvalTreeConversations $treeConversations, ?string $newestMessageCreatedAt): static
    {
        $aIConversationEvalTree = static::newInstance();
        $aIConversationEvalTree->rootConversationId = $rootConversationId;
        $aIConversationEvalTree->conversationIds = [$rootConversationId];
        $aIConversationEvalTree->newestActivityAt = static::newerMoment(null, $newestMessageCreatedAt);
        foreach ($treeConversations->getElements() as $treeConversation) {
            $aIConversationEvalTree->addConversation($treeConversation);
        }
        return $aIConversationEvalTree;
    }

    /** Counts one conversation into the tree: its id, its account when it is the root, its run facts and activity. */
    protected function addConversation(AIConversationEvalTreeConversation $treeConversation): void
    {
        if ($treeConversation->id === $this->rootConversationId) {
            $this->rootAccountId = $treeConversation->accountId;
        } elseif (!in_array($treeConversation->id, $this->conversationIds, true)) {
            $this->conversationIds[] = $treeConversation->id;
        }
        if ($treeConversation->hasRunInFlight()) {
            $this->hasRunInFlight = true;
        }
        $this->newestHeartbeatAt = static::newerMoment($this->newestHeartbeatAt, $treeConversation->runHeartbeatAt);
        foreach ($treeConversation->getActivityMoments() as $activityMoment) {
            $this->newestActivityAt = static::newerMoment($this->newestActivityAt, $activityMoment);
        }
    }

    /**
     * Whether the tree may be deleted now. The checks run in this order, the first that fails names the verdict.
     *
     * @param int[] $evalAccountIds the configured evaluation accounts
     * @param string $activityCutoff UTC moment: activity at or after it keeps the tree
     * @param string $freshHeartbeatCutoff UTC moment: a heartbeat after it keeps the tree, whatever the age limit says
     */
    public function decidePurgeVerdict(array $evalAccountIds, string $activityCutoff, string $freshHeartbeatCutoff): string
    {
        if ($this->rootAccountId === null || !in_array($this->rootAccountId, array_map('intval', $evalAccountIds), true)) {
            return static::VERDICT_SKIP_NOT_EVAL_ACCOUNT;
        }
        if ($this->hasRunInFlight) {
            return static::VERDICT_SKIP_RUN_IN_FLIGHT;
        }
        if ($this->newestHeartbeatAt !== null && $this->newestHeartbeatAt > $freshHeartbeatCutoff) {
            return static::VERDICT_SKIP_FRESH_HEARTBEAT;
        }
        // a tree without any stamp has no measurable age — kept, never guessed old
        if ($this->newestActivityAt === null || $this->newestActivityAt >= $activityCutoff) {
            return static::VERDICT_SKIP_RECENT_ACTIVITY;
        }
        return static::VERDICT_PURGE;
    }

    /** The later of two `Y-m-d H:i:s` moments; null and empty values do not count. */
    protected static function newerMoment(?string $currentMoment, ?string $candidateMoment): ?string
    {
        if ($candidateMoment === null || $candidateMoment === '') {
            return $currentMoment;
        }
        return $currentMoment === null || $candidateMoment > $currentMoment ? $candidateMoment : $currentMoment;
    }
}
