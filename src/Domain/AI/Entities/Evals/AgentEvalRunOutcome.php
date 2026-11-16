<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\AIConversation;

/**
 * What one evaluation run returns: the driven conversation — the lossless trace (loaded skills, tool calls with their
 * results and status, the final text, tokens and cost per message) — and what the reload of a stored conversation saw.
 * A recall run adds the ids of the conversations it seeded, an eviction run its owner turns ({@see AgentEvalEvictionTurns}).
 *
 * A plain object of one run; no container service.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalRunOutcome
{
    /** @var AIConversation The driven conversation; for a stored run the conversation as read back after the last turn */
    protected AIConversation $conversation;

    /**
     * @var mixed[] What the reload of a stored conversation saw, by key: `found`, and when found `sameInstance`,
     * `messages`, `toolCallStatuses` (`tool:status` per call) and `decisionRecords`; empty for a transient run
     */
    protected array $reloadDiagnostics = [];

    /** @var int[] The ids of the conversations a recall run seeded */
    protected array $seededConversationIds = [];

    /** @var AgentEvalEvictionTurns|null The owner turns of an eviction run; null for every other run */
    protected ?AgentEvalEvictionTurns $evictionTurns = null;

    /**
     * The outcome of a run that drove `$conversation`.
     *
     * @param AIConversation $conversation
     * @param mixed[] $reloadDiagnostics by key, as {@see $reloadDiagnostics}
     * @return static
     */
    public static function forConversation(AIConversation $conversation, array $reloadDiagnostics = []): static
    {
        $agentEvalRunOutcome = new static();
        $agentEvalRunOutcome->conversation = $conversation;
        $agentEvalRunOutcome->reloadDiagnostics = $reloadDiagnostics;
        return $agentEvalRunOutcome;
    }

    /** The driven conversation. */
    public function getConversation(): AIConversation
    {
        return $this->conversation;
    }

    /**
     * What the reload of a stored conversation saw; empty for a transient run.
     *
     * @return mixed[] by key
     */
    public function getReloadDiagnostics(): array
    {
        return $this->reloadDiagnostics;
    }

    /**
     * @param int[] $seededConversationIds
     * @return void
     */
    public function recordSeededConversationIds(array $seededConversationIds): void
    {
        $this->seededConversationIds = array_values($seededConversationIds);
    }

    /** @return int[] The ids of the conversations a recall run seeded */
    public function getSeededConversationIds(): array
    {
        return $this->seededConversationIds;
    }

    /**
     * @param AgentEvalEvictionTurns $agentEvalEvictionTurns the owner turns of an eviction run, in the order they ran
     * @return void
     */
    public function recordEvictionTurnSet(AgentEvalEvictionTurns $agentEvalEvictionTurns): void
    {
        $this->evictionTurns = $agentEvalEvictionTurns;
    }

    /** The owner turns of an eviction run; an empty set for every other run. */
    public function getEvictionTurnSet(): AgentEvalEvictionTurns
    {
        return $this->evictionTurns ?? AgentEvalEvictionTurns::newInstance();
    }

    /**
     * The owner turns of an eviction run as rows, each by key: `prompt`, `snapshot`, `newlyLoaded`, `evicted`,
     * `metrics` ({@see AgentEvalEvictionTurn::toArray()}); none for every other run.
     *
     * @deprecated read {@see getEvictionTurnSet()}; this reader stays for callers of the row form
     * @return array[]
     */
    public function getEvictionTurns(): array
    {
        return $this->getEvictionTurnSet()->toArray();
    }
}
