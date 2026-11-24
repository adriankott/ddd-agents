<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\ClientTools;

use DDD\Domain\AI\Entities\ClientTools\AgentUIScreen;
use DDD\Domain\AI\Entities\ClientTools\AgentUIState;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Infrastructure\Services\Service;

/**
 * The port of `Agent.UI` for the application's screen catalog on the server: which area a reported route is, and how
 * the record on it is described under the caller's rights. The state note the model reads names only what this
 * service returns ({@see AgentUIStateNotesService}).
 *
 * The default knows no catalog: the area is the route path itself (validated by {@see AgentUIState::ROUTE_PATH_PATTERN})
 * and no record is described. An application puts a same-path subclass under its own root namespace that maps its
 * routes to its areas and describes the record (its navigation model; a record the caller may not read is not
 * described). The client side of the catalog (`listScreens`, `openScreen`) is the application's client adapter.
 */
class AgentUIScreenCatalogService extends Service
{
    /**
     * The screen of `$agentUIState` for the conversation's account; null when the route is no screen of the catalog
     * (the note then names no area).
     */
    public function describeScreen(AgentUIState $agentUIState, AIConversation $conversation): ?AgentUIScreen
    {
        if (!AgentUIState::isValidRoutePath($agentUIState->routePath)) {
            return null;
        }
        $agentUIScreen = new AgentUIScreen();
        $agentUIScreen->areaKey = (string)$agentUIState->routePath;
        return $agentUIScreen;
    }
}
