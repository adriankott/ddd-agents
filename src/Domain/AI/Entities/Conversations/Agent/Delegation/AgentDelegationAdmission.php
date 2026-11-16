<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Delegation;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * One admission of the QUEUED delegation children of a tree into its free capacity
 * ({@see \DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService::admitQueuedDelegationChildrenAndDescribeAdmission()}):
 * how the tree's capacity lock went, how many slots were occupied when the admission counted, which queued children it
 * found and which it admitted. The spawn answers a queued child from it ({@see buildQueuedReasonText()}) and writes it
 * as the turn event `delegationAdmission`.
 *
 * @phpstan-consistent-constructor
 */
class AgentDelegationAdmission extends ValueObject
{
    /** The lock was obtained at the first attempt. */
    public const string LOCK_OBTAINED = 'obtained';

    /** The lock was obtained after one or more attempts that answered at once without the lock (GET_LOCK NULL or 0 without waiting). */
    public const string LOCK_OBTAINED_AFTER_RETRY = 'obtainedAfterRetry';

    /** The last attempt waited the remaining wait and did not get the lock: another admission of the tree held it. */
    public const string LOCK_TIMED_OUT = 'timedOut';

    /** Every attempt within the wait answered at once without the lock (GET_LOCK NULL or 0 without waiting). */
    public const string LOCK_REFUSED = 'refused';

    /** The tree's root conversation. */
    public int $rootConversationId = 0;

    /** How the capacity lock went (`LOCK_*`). */
    #[Choice(choices: [self::LOCK_OBTAINED, self::LOCK_OBTAINED_AFTER_RETRY, self::LOCK_TIMED_OUT, self::LOCK_REFUSED])]
    public string $lockOutcome = self::LOCK_OBTAINED;

    /** How many attempts the lock took. */
    public int $lockAttempts = 1;

    /** The cap the admission counted against. */
    public int $concurrencyCap = 0;

    /** The slots occupied when the admission counted, before it admitted (read also when the lock was not obtained). */
    public int $occupiedSlotCount = 0;

    /** @var int[] The ids of the QUEUED children the admission read, oldest first (up to the free slots; all of them without the lock) */
    public array $queuedChildIds = [];

    /** @var int[] The ids admitted now */
    public array $admittedChildIds = [];

    /** Whether this admission is the spawn's second one ({@see withRetry()}). */
    public bool $retried = false;

    public static function forTree(int $rootConversationId, int $concurrencyCap): static
    {
        $agentDelegationAdmission = new static();
        $agentDelegationAdmission->rootConversationId = $rootConversationId;
        $agentDelegationAdmission->concurrencyCap = $concurrencyCap;
        return $agentDelegationAdmission;
    }

    /** Whether the admission held the tree's lock (it admitted only then). */
    public function isLockObtained(): bool
    {
        return $this->lockOutcome === self::LOCK_OBTAINED || $this->lockOutcome === self::LOCK_OBTAINED_AFTER_RETRY;
    }

    /** Whether the child was admitted by this admission. */
    public function hasAdmittedChild(int $childConversationId): bool
    {
        return in_array($childConversationId, $this->admittedChildIds, true);
    }

    /** The slots taken once this admission's children run: the occupied ones and the ones it admitted. */
    public function getTakenSlotCountAfterAdmission(): int
    {
        return $this->occupiedSlotCount + count($this->admittedChildIds);
    }

    /** Whether a slot is still free after this admission. */
    public function hasFreeSlotAfterAdmission(): bool
    {
        return $this->getTakenSlotCountAfterAdmission() < $this->concurrencyCap;
    }

    /**
     * Whether one of `$childConversationIds` was left queued although the admission could have admitted it: the lock was
     * not obtained, or a slot is still free (the child was not among the queued rows the admission read).
     *
     * @param int[] $childConversationIds
     */
    public function leavesChildQueuedWhileSlotCouldBeFree(array $childConversationIds): bool
    {
        foreach ($childConversationIds as $childConversationId) {
            if (!$this->hasAdmittedChild($childConversationId) && (!$this->isLockObtained() || $this->hasFreeSlotAfterAdmission())) {
                return true;
            }
        }
        return false;
    }

    /**
     * This admission followed by `$retryAdmission` of the same tree: the children either admitted, the lock, counts and
     * queued rows of the retry.
     */
    public function withRetry(AgentDelegationAdmission $retryAdmission): self
    {
        $combinedAdmission = clone $retryAdmission;
        $combinedAdmission->admittedChildIds = array_values(array_unique(array_merge($this->admittedChildIds, $retryAdmission->admittedChildIds)));
        // the retry counted the first admission's children as occupied: the taken slots stay the retry's
        $combinedAdmission->occupiedSlotCount = max(0, $retryAdmission->getTakenSlotCountAfterAdmission() - count($combinedAdmission->admittedChildIds));
        $combinedAdmission->retried = true;
        return $combinedAdmission;
    }

    /**
     * Why a child of this admission was left queued, for the `delegate` call's answer: no slot was free (the real count),
     * the capacity lock was not obtained, or a slot was free and the child was not admitted yet.
     */
    public function buildQueuedReasonText(): string
    {
        $takenSlotsText = $this->getTakenSlotCountAfterAdmission() . ' of ' . $this->concurrencyCap . ' sub-agent slots of this conversation are taken';
        if (!$this->isLockObtained()) {
            return 'the admission could not run now, its lock was not obtained; ' . $takenSlotsText . '; it starts at the next admission';
        }
        if (!$this->hasFreeSlotAfterAdmission()) {
            return 'no slot is free: ' . $takenSlotsText . '; it starts when one of them ends';
        }
        return $takenSlotsText . '; it starts at the next admission';
    }

    /**
     * The payload of the turn event `delegationAdmission`.
     *
     * @return array<string, mixed>
     */
    public function buildTurnEventPayload(): array
    {
        return [
            'rootConversationId' => $this->rootConversationId,
            'lockOutcome' => $this->lockOutcome,
            'lockAttempts' => $this->lockAttempts,
            'concurrencyCap' => $this->concurrencyCap,
            'occupiedSlotCount' => $this->occupiedSlotCount,
            'queuedChildIds' => $this->queuedChildIds,
            'admittedChildIds' => $this->admittedChildIds,
            'retried' => $this->retried,
        ];
    }
}
