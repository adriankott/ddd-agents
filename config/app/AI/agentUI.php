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
 */
return [
    'windowModeRequests' => false,
    'maxOpenScreenPerUserTurn' => 1,
];
