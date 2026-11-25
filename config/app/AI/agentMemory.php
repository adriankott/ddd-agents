<?php

declare(strict_types=1);

/**
 * The agent's durable memory, read as `Config::get('AI.agentMemory')` by
 * {@see \DDD\Domain\AI\Services\Conversations\Memories\AgentMemoriesService::isMemoryStoreEnabled()}:
 *
 *     'enabled' => bool   // memory is on: the core asks the memory store and the memory tools are offered
 *
 * "On" says that the agent has a memory, not which table holds it: every answer comes from the memory store
 * ({@see \DDD\Domain\AI\Services\Conversations\Memories\AgentMemoryStoreService}, the host port). The default
 * store keeps its rows in the package's table (`AgentMemories`): switch memory on after that table exists. A host
 * whose memories live in rows of its own puts a same-path subclass of the store under its root namespace and switches
 * memory on without the package's table. Off by default: the core's memory questions are answered "no memory",
 * nothing reads or writes a memory row and the memory tools are not offered. An application ships its own file at
 * this path, which REPLACES this one whole.
 */
return [
    'enabled' => false,
];
