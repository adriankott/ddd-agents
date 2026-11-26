<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Goals;

use DDD\Domain\AI\Entities\Conversations\Goals\AgentGoal;
use DDD\Domain\AI\Entities\Conversations\Goals\AgentGoals;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * The rules of the goal register ({@see AgentGoal}): the one write path {@see persistGoal()} (dedup of a restatement,
 * the initial status, the parent rules, the supersession of a reworded goal), the status transition
 * {@see transitionGoalStatus()}, the current goals of a scope, and closing goals. Every read and write goes through the
 * goal port {@see AgentGoalStoreService}, resolved through the container, so a host store gets the same rules.
 *
 * `AI.agentGoals.enabled` (`config/app/AI/agentGoals.php`, default off) means "goals on": while it is off the goal
 * tools are not offered and a direct call is refused.
 *
 * Override points of a host (a same-path subclass): {@see areStatementsNearDuplicates()} (when a new statement
 * supersedes a current goal instead of standing beside it) and {@see NEAR_DUPLICATE_SIMILARITY_PERCENT}.
 */
class AgentGoalsService extends Service
{
    /** @var string The config key of the switch of the goal register */
    public const string CONFIG_GOALS_ENABLED = 'AI.agentGoals.enabled';

    /**
     * @var float Two normalized statements at least this similar (`similar_text` percentage) are the same goal reworded:
     * the newer supersedes the older. Conservative on purpose: goals are few, and a false merge is worse than a rare
     * duplicate.
     */
    public const float NEAR_DUPLICATE_SIMILARITY_PERCENT = 85.0;

    /** @var string The refusal of a goal without a statement */
    public const string STATEMENT_MISSING_MESSAGE = 'A goal needs a statement.';

    /**
     * Whether the goal register is switched on (`AI.agentGoals.enabled`).
     *
     * @return bool
     */
    public static function isGoalsEnabled(): bool
    {
        return Config::get(self::CONFIG_GOALS_ENABLED) === true;
    }

    /**
     * Whether the goal store can read and write now ({@see AgentGoalStoreService::isGoalStoreAvailable()}): false while
     * the package's table is missing.
     *
     * @return bool
     */
    public function isGoalStoreAvailable(): bool
    {
        return $this->getAgentGoalStoreService()->isGoalStoreAvailable();
    }

    /**
     * THE write path of a goal. Cuts the statement, stamps the scope key and the statement hash, and for a new goal sets
     * the initial status from the provenance ({@see AgentGoal::deriveInitialStatus()}), checks the parent
     * ({@see assertParentRules()}) and compares it with the current goals of exactly its scope:
     * - the same statement (after normalization) is idempotent: the stored goal is returned, nothing is written —
     *   unless the restatement corrects it ({@see AgentGoal::isCorrectedByRestatement()}: another horizon, another
     *   target day, or a dropped goal stated again); then the old row is closed, the new one written and the old one
     *   pointed at it;
     * - a near-duplicate ({@see areStatementsNearDuplicates()}) is the same goal reworded: the new row is written first,
     *   then the old one is closed and pointed at it;
     * - anything else is a new row.
     * A stored goal (with an id) is written as it is, after the cut and the stamps. The caller sets the scope from the
     * acting account and the call's space, never from a free argument.
     *
     * @param AgentGoal $agentGoal
     * @return AgentGoal The written goal, or the stored one an exact restatement found
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function persistGoal(AgentGoal $agentGoal): AgentGoal
    {
        if (!isset($agentGoal->statement) || trim($agentGoal->statement) === '') {
            throw new BadRequestException(static::STATEMENT_MISSING_MESSAGE);
        }
        if ($agentGoal->deriveScopeKey() === null) {
            throw new BadRequestException('A goal belongs to an account.');
        }
        $agentGoal->prepareForPersistence();
        $agentGoalStoreService = $this->getAgentGoalStoreService();
        if (isset($agentGoal->id) && $agentGoal->id) {
            return $this->writeGoalCarryingItsId($agentGoalStoreService, $agentGoal);
        }
        $agentGoal->status = $agentGoal->deriveInitialStatus();
        $this->assertParentRules($agentGoal);
        $currentGoalsOfScope = $agentGoalStoreService->findCurrentGoalsForScope((int)$agentGoal->accountId, $agentGoal->agentSpaceId);
        foreach ($currentGoalsOfScope->getElements() as $currentGoal) {
            if ($currentGoal->deriveScopeKey() !== $agentGoal->scopeKey || !$currentGoal->isCurrentlyValid() || !isset($currentGoal->id)) {
                continue;
            }
            if ($currentGoal->statementHash === $agentGoal->statementHash) {
                if (!$currentGoal->isCorrectedByRestatement($agentGoal)) {
                    return $currentGoal;
                }
                $agentGoalStoreService->closeValidityWindow((int)$currentGoal->id, null, new DateTime('now'));
                $writtenGoal = $this->writeGoalCarryingItsId($agentGoalStoreService, $agentGoal);
                $agentGoalStoreService->recordSuccessorOfClosedGoal((int)$currentGoal->id, (int)$writtenGoal->id);
                return $writtenGoal;
            }
            if ($this->areStatementsNearDuplicates($currentGoal->statement, $agentGoal->statement)) {
                $writtenGoal = $this->writeGoalCarryingItsId($agentGoalStoreService, $agentGoal);
                $this->closeGoalValidityWindow($currentGoal, $writtenGoal);
                return $writtenGoal;
            }
        }
        return $this->writeGoalCarryingItsId($agentGoalStoreService, $agentGoal);
    }

    /**
     * Writes the goal and puts the written row's id on the goal that was passed: a caller tells a new write from an
     * unchanged restatement by that id (`addGoal`), whether or not the store sets it on the object it got.
     *
     * @param AgentGoalStoreService $agentGoalStoreService
     * @param AgentGoal $agentGoal
     * @return AgentGoal The written goal
     */
    protected function writeGoalCarryingItsId(AgentGoalStoreService $agentGoalStoreService, AgentGoal $agentGoal): AgentGoal
    {
        $writtenGoal = $agentGoalStoreService->writeGoal($agentGoal);
        if (isset($writtenGoal->id) && $writtenGoal->id) {
            $agentGoal->id = $writtenGoal->id;
        }
        return $writtenGoal;
    }

    /**
     * Moves a current goal to `$newStatus`: a superseded version, an unknown status, a status the goal can never reach
     * ({@see AgentGoal::isStatusReachable()}) and BLOCKED without a note naming the blocker are refused; the change
     * itself is the goal's ({@see AgentGoal::applyStatusTransition()}).
     *
     * @param AgentGoal $agentGoal
     * @param string $newStatus
     * @param string|null $statusNote
     * @param bool $requiresUserAction
     * @param bool $retryable
     * @return AgentGoal
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function transitionGoalStatus(
        AgentGoal $agentGoal,
        string $newStatus,
        ?string $statusNote = null,
        bool $requiresUserAction = false,
        bool $retryable = false
    ): AgentGoal {
        if (!$agentGoal->isCurrentlyValid()) {
            throw new BadRequestException('This goal was replaced by a newer version — take the current #<id> from getGoals.');
        }
        if (!in_array($newStatus, AgentGoal::STATUSES, true)) {
            throw new BadRequestException('status is one of ' . implode(', ', AgentGoal::STATUSES) . '.');
        }
        if (!$agentGoal->isStatusReachable($newStatus)) {
            throw new BadRequestException(
                $newStatus === AgentGoal::STATUS_ACHIEVED
                    ? 'A VISION is never achieved — it orients. Mark the goals beneath it achieved instead.'
                    : 'Only a goal the agent holds can be BLOCKED — an owner goal is corrected (addGoal) or dropped.'
            );
        }
        if ($newStatus === AgentGoal::STATUS_BLOCKED && ($statusNote === null || trim($statusNote) === '')) {
            throw new BadRequestException('BLOCKED needs a statusNote naming the blocker.');
        }
        $agentGoal->applyStatusTransition($newStatus, $statusNote, $requiresUserAction, $retryable);
        return $this->getAgentGoalStoreService()->writeGoal($agentGoal);
    }

    /**
     * The current goals of the scope (the account level plus the space), the highest priority first.
     *
     * @param int $accountId
     * @param int|null $agentSpaceId
     * @param bool $heldOnly Only the goals that are still live (ACTIVE, PROPOSED, BLOCKED)
     * @return AgentGoals
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findCurrentGoalsForScope(int $accountId, ?int $agentSpaceId = null, bool $heldOnly = false): AgentGoals
    {
        return $this->getAgentGoalStoreService()->findCurrentGoalsForScope($accountId, $agentSpaceId, $heldOnly);
    }

    /**
     * A goal by id, read with the read rights of the authenticated account; null when there is none.
     *
     * @param int $goalId
     * @return AgentGoal|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findGoal(int $goalId): ?AgentGoal
    {
        if ($goalId <= 0) {
            return null;
        }
        $agentGoal = $this->getAgentGoalStoreService()->find($goalId, false);
        return $agentGoal instanceof AgentGoal ? $agentGoal : null;
    }

    /**
     * Invalidates every current goal of exactly this scope (the rows stay as history).
     *
     * @param int $accountId
     * @param int|null $agentSpaceId Empty = the account-level goals
     * @return int The number of goals invalidated
     */
    public function invalidateCurrentGoalsOfScope(int $accountId, ?int $agentSpaceId): int
    {
        return $this->getAgentGoalStoreService()->invalidateCurrentGoalsOfScope($accountId, $agentSpaceId);
    }

    /**
     * Closes `$closedGoal` because `$successorGoal` (written, with an id) replaces it; a goal another writer closed
     * first stays as it is.
     *
     * @param AgentGoal $closedGoal
     * @param AgentGoal $successorGoal
     * @return int The number of rows closed (0 or 1)
     */
    public function closeGoalValidityWindow(AgentGoal $closedGoal, AgentGoal $successorGoal): int
    {
        return $this->getAgentGoalStoreService()->closeValidityWindow((int)$closedGoal->id, (int)$successorGoal->id, new DateTime('now'));
    }

    /**
     * Whether two statements are the same goal reworded: their normalized forms are at least
     * {@see NEAR_DUPLICATE_SIMILARITY_PERCENT} similar (`similar_text`). Pure. A host may compare by meaning instead.
     *
     * @param string $statement
     * @param string $otherStatement
     * @return bool
     */
    protected function areStatementsNearDuplicates(string $statement, string $otherStatement): bool
    {
        $normalizedStatement = AgentGoal::normalizeStatement($statement);
        $normalizedOtherStatement = AgentGoal::normalizeStatement($otherStatement);
        if ($normalizedStatement === '' || $normalizedOtherStatement === '') {
            return false;
        }
        similar_text($normalizedStatement, $normalizedOtherStatement, $similarityPercent);
        return $similarityPercent >= static::NEAR_DUPLICATE_SIMILARITY_PERCENT;
    }

    /**
     * The parent rules of a new goal: the parent exists, is readable and current, belongs to the same account and to
     * the goal's space or the account level, may hold the goal ({@see AgentGoal::mayHaveParent()}), and the goal adds at
     * most one level within {@see AgentGoal::MAX_TREE_DEPTH} (the ancestors are walked here).
     *
     * @param AgentGoal $agentGoal
     * @return void
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function assertParentRules(AgentGoal $agentGoal): void
    {
        if (!isset($agentGoal->parentGoalId) || !$agentGoal->parentGoalId) {
            return;
        }
        $parentGoal = $this->findGoal((int)$agentGoal->parentGoalId);
        if ($parentGoal === null
            || !$parentGoal->isCurrentlyValid()
            || (int)$parentGoal->accountId !== (int)$agentGoal->accountId
            || ($parentGoal->isAgentSpaceBound() && (int)$parentGoal->agentSpaceId !== (int)$agentGoal->agentSpaceId)
        ) {
            throw new BadRequestException('Parent goal not found — take its current #<id> from getGoals.');
        }
        if (!$agentGoal->mayHaveParent($parentGoal)) {
            throw new BadRequestException('An owner goal never hangs under a goal the agent holds.');
        }
        $parentDepth = 1;
        $ancestorGoal = $parentGoal;
        while (isset($ancestorGoal->parentGoalId) && $ancestorGoal->parentGoalId && $parentDepth < AgentGoal::MAX_TREE_DEPTH) {
            $ancestorGoal = $this->findGoal((int)$ancestorGoal->parentGoalId);
            if ($ancestorGoal === null) {
                break;
            }
            $parentDepth++;
        }
        if ($parentDepth + 1 > AgentGoal::MAX_TREE_DEPTH) {
            throw new BadRequestException('A goal tree has at most ' . AgentGoal::MAX_TREE_DEPTH . ' levels.');
        }
    }

    /**
     * The goal port, resolved through the container (a host's same-path subclass when it has one).
     *
     * @return AgentGoalStoreService
     */
    protected function getAgentGoalStoreService(): AgentGoalStoreService
    {
        /** @var AgentGoalStoreService $agentGoalStoreService */
        $agentGoalStoreService = DDDService::instance()->getService(AgentGoalStoreService::class);
        return $agentGoalStoreService;
    }
}
