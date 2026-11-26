<?php

declare(strict_types=1);

/**
 * The agent's goal register, read as `Config::get('AI.agentGoals')` by
 * {@see \DDD\Domain\AI\Services\Conversations\Goals\AgentGoalsService::isGoalsEnabled()}:
 *
 *     'enabled' => bool   // goals are on: the goal tools and the skill Agent.Goals are offered
 *
 * "On" says that the agent keeps the owner's goals, not which table holds them: every read and write goes through the
 * goal store ({@see \DDD\Domain\AI\Services\Conversations\Goals\AgentGoalStoreService}, the host port). The default
 * store keeps its rows in the package's table (`AgentGoals`); while that table does not exist yet, the tools answer
 * "not available" and nothing fails (`app:agent:check-installation` names the missing table as a warning). A host whose
 * goals live in rows of its own puts a same-path subclass of the store under its root namespace and switches goals on
 * without the package's table. Off by default: the goal tools are not offered and a direct call is refused. An
 * application ships its own file at this path, which REPLACES this one whole.
 */
return [
    'enabled' => false,
];
