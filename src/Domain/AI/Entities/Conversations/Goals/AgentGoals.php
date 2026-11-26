<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Goals;

use DDD\Domain\AI\Repo\DB\Conversations\Goals\DBAgentGoals;
use DDD\Domain\AI\Services\Conversations\Goals\AgentGoalStoreService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * Goals of the goal register ({@see AgentGoal}), in the order the store read them (the highest priority first).
 *
 * @property AgentGoal[] $elements
 * @method AgentGoal|null first()
 * @method AgentGoal[] getElements()
 * @method static AgentGoalStoreService getService()
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentGoals::class)]
class AgentGoals extends EntitySet implements LlmMarkdownRenderable
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    public const string SERVICE_NAME = AgentGoalStoreService::class;

    /** @var string The line of an empty register */
    public const string NO_GOALS_LINE = '(no goals)';

    /**
     * The goals of the set that are current and still held ({@see AgentGoal::isHeld()}), in the set's order.
     *
     * @return AgentGoals
     */
    public function getHeldGoals(): AgentGoals
    {
        $heldGoals = AgentGoals::newInstance();
        foreach ($this->getElements() as $agentGoal) {
            if ($agentGoal->isHeld()) {
                $heldGoals->add($agentGoal);
            }
        }
        return $heldGoals;
    }

    public function toLlmMarkdown(): string
    {
        $goalLines = [];
        foreach ($this->getElements() as $agentGoal) {
            $goalLines[] = $agentGoal->toLlmMarkdown();
        }
        return $goalLines === [] ? self::NO_GOALS_LINE : implode("\n", $goalLines);
    }
}
