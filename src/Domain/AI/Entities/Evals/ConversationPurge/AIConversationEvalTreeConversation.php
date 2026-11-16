<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\ConversationPurge;

use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * One conversation of a tree as the evaluation purge reads it: its account, its run facts and its activity moments.
 * The purge service fills it from the conversation row it read ({@see forConversation()}, then
 * {@see setActivityMoment()} per column of {@see ACTIVITY_COLUMNS}); {@see AIConversationEvalTree} decides on the
 * whole tree. Every moment is a UTC `Y-m-d H:i:s` string as the database stores it, so moments compare as strings.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationEvalTreeConversation extends ValueObject
{
    /** @var string[] The conversation columns that count as activity (besides the newest message's `created`). */
    public const array ACTIVITY_COLUMNS = ['created', 'updated', 'lastInboundMessageAt', 'runHeartbeatAt', 'finalizedAt', 'agentTurnOwedAt', 'reportOwedAt'];

    public int $id = 0;

    public ?int $accountId = null;

    /** @var string|null The stored run state ({@see AIConversationRunState}) */
    public ?string $runState = null;

    /** @var bool The conversation holds a run token */
    public bool $hasRunToken = false;

    public ?string $created = null;

    public ?string $updated = null;

    public ?string $lastInboundMessageAt = null;

    public ?string $runHeartbeatAt = null;

    public ?string $finalizedAt = null;

    public ?string $agentTurnOwedAt = null;

    public ?string $reportOwedAt = null;

    public static function forConversation(int $conversationId, ?int $accountId, ?string $runState, bool $hasRunToken): static
    {
        $aIConversationEvalTreeConversation = static::newInstance();
        $aIConversationEvalTreeConversation->id = $conversationId;
        $aIConversationEvalTreeConversation->accountId = $accountId;
        $aIConversationEvalTreeConversation->runState = $runState;
        $aIConversationEvalTreeConversation->hasRunToken = $hasRunToken;
        return $aIConversationEvalTreeConversation;
    }

    /**
     * Sets the moment of one column of {@see ACTIVITY_COLUMNS}; an empty value counts as none, a column outside the
     * list is ignored.
     */
    public function setActivityMoment(string $activityColumnName, ?string $moment): void
    {
        if (!in_array($activityColumnName, static::ACTIVITY_COLUMNS, true)) {
            return;
        }
        $this->$activityColumnName = $moment === null || $moment === '' ? null : $moment;
    }

    /** @return string[] the moments of {@see ACTIVITY_COLUMNS} this conversation carries, in the order of the list */
    public function getActivityMoments(): array
    {
        $activityMoments = [];
        foreach (static::ACTIVITY_COLUMNS as $activityColumnName) {
            if ($this->$activityColumnName !== null) {
                $activityMoments[] = $this->$activityColumnName;
            }
        }
        return $activityMoments;
    }

    /** Whether a run of the conversation is in flight: a non-terminal run state or a run token. */
    public function hasRunInFlight(): bool
    {
        return in_array($this->runState, AIConversationRunState::NON_TERMINAL, true) || $this->hasRunToken;
    }
}
