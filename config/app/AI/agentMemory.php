<?php

declare(strict_types=1);

/**
 * The package's durable memory store, read as `Config::get('AI.agentMemory')` by
 * {@see \DDD\Domain\AI\Services\Conversations\Memories\AgentMemoriesService::isMemoryStoreEnabled()}:
 *
 *     'enabled' => bool   // the package's memory store answers the core's memory questions and its tools are offered
 *
 * Off by default: the store needs its table (`AgentMemories`), so an application switches it on after the table
 * exists. While it is off, the core's memory questions are answered "no memory", nothing reads or writes the table and
 * the memory tools are not offered. An application ships its own file at this path, which REPLACES this one whole.
 * An application with a memory component of its own (a same-path subclass of the port) leaves it off.
 */
return [
    'enabled' => false,
];
