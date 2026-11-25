<?php

declare(strict_types=1);

/**
 * The application's overlays of tools by tool NAME, read as `Config::get('AI.toolOverlays')`
 * ({@see \DDD\Domain\AI\Entities\MCPPackages\MCPToolOverlays}). An application replaces this file whole. Every key is
 * optional; a key left out keeps the tool's own declaration:
 *
 * - `accountRights`: the rights the tool requires INSTEAD of `#[MCPRequiresAccountRights]` on its method and package
 *   (`[]` = no right for this tool);
 * - `description`: what the model reads instead of the method docblock;
 * - `summary`: the routing line instead of `#[MCPToolSummary]`;
 * - `parameters`: parameter name => the description instead of the property docblock;
 * - `ownerFacingSubject`: what the run report calls what the tool saves, instead of `#[MCPToolOwnerFacingSubject]`.
 *
 * A tool name no installed package exposes is reported by `app:agent:check-installation` (warning).
 *
 * Example:
 *   'getTaskRuns' => ['accountRights' => [], 'summary' => 'The runs of the tasks that executed, newest first.'],
 *   'createRecurringTaskSchedule' => ['parameters' => ['task' => 'What each run does, in the owner\'s words.']],
 */
return [];
