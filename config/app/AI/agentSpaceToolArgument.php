<?php

declare(strict_types=1);

/**
 * The argument by which the package's tools name the space a call targets, read as `Config::get('AI.agentSpaceToolArgument')`
 * ({@see \DDD\Domain\AI\Entities\AgentSpaces\AgentSpaceToolArgument}). An application replaces this file whole.
 *
 * - `name`: the argument's name on the wire (default `agentSpaceId`). The schema of every package tool shows the
 *   argument under it; the executor fills it from the conversation's space and passes the call's arguments to
 *   `AgentSpacesService::findAgentSpaceOfToolCall()`, which reads its value as a space id by default — an application
 *   whose value is an id of its own entity resolves it in its same-path subclass of that service.
 * - `description`: what the model reads about the argument (null = the package's text).
 *
 * Example: `return ['name' => 'workspaceId', 'description' => 'The workspace the call works on.'];`
 */
return [
    'name' => 'agentSpaceId',
    'description' => null,
];
