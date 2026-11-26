<?php

declare(strict_types=1);

/**
 * The client channel of `Agent.UI` — read as `Config::get('AI.agentUI')` by
 * {@see \DDD\Domain\AI\Services\ClientTools\AgentUIClientToolRequestTypeProviderService} and
 * {@see \DDD\Presentation\Mcp\Client\Agents\AgentUIMCPPackage}. An application replaces this file whole with its own
 * `config/app/AI/agentUI.php`; a missing key keeps its default.
 *
 * - `windowModeRequests`: whether the server may ask the client to change the view mode of the agent window
 *   (`SET_WINDOW_MODE`, the tool `setAgentWindowMode`). Off by default: the client steps the window aside on its own
 *   before it opens a screen, marks elements or takes a picture.
 * - `maxOpenScreenPerUserTurn`: how many screens `openScreen` opens for one message of the user (default 1). A further
 *   call is refused with a sentence, so the agent never walks the user through a chain of screens on its own.
 * - `screenActionRequests`: whether the server may ask the client to act on the open screen (`ACT_ON_SCREEN`, the
 *   tool `actOnScreen` of {@see \DDD\Presentation\Mcp\Client\Agents\AgentUIActionsMCPPackage}): set the screen's URL
 *   filters, sort a table, open a row, switch a tab, press a button the screen declares for the agent with the effect
 *   `view`. Off by default: switch it on once the client runs an actor (`ScreenAdapters.actions` of the npm package)
 *   and the screens declare their elements (`data-agent-action`). Nothing here saves, deletes or sends.
 * - `maxScreenActionsPerUserTurn`: how many actions `actOnScreen` carries out for one message of the user (default
 *   3: e.g. filter, sort, open a row). A further call is refused with a sentence.
 */
return [
    'windowModeRequests' => false,
    'maxOpenScreenPerUserTurn' => 1,
    'screenActionRequests' => false,
    'maxScreenActionsPerUserTurn' => 3,
];
