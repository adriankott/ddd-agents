<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\ClientTools;

use DDD\Domain\AI\Entities\ClientTools\AgentClientToolRequest;
use DDD\Domain\AI\Entities\ClientTools\AgentClientToolRequestType;
use DDD\Domain\AI\Entities\ClientTools\AgentClientToolRequestTypes;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\Service;

/**
 * The client tool request types of `Agent.UI` itself: read the screen (text or picture), list and open screens, mark
 * elements, the agent window's view mode — off unless the host switches it on (`AI.agentUI.windowModeRequests`) — and
 * the typed actions on the open screen, off unless the host switches them on (`AI.agentUI.screenActionRequests`).
 */
class AgentUIClientToolRequestTypeProviderService extends Service implements AgentClientToolRequestTypeProvider
{
    /** @var string The configuration of `Agent.UI` */
    public const string CONFIG_KEY = 'AI.agentUI';

    /** @var string Who registers the package's types */
    public const string REGISTERED_BY = 'agent.ui';

    public function getClientToolRequestTypes(): AgentClientToolRequestTypes
    {
        $agentClientToolRequestTypes = new AgentClientToolRequestTypes();
        foreach ([
            AgentClientToolRequestType::create(AgentClientToolRequest::TYPE_READ_SCREEN, 'Read the open screen as Markdown, or capture it as a picture (format, pictureSize, renderMode).', static::REGISTERED_BY),
            AgentClientToolRequestType::create(AgentClientToolRequest::TYPE_LIST_TARGETS, 'List the screens the user can open.', static::REGISTERED_BY),
            AgentClientToolRequestType::create(AgentClientToolRequest::TYPE_OPEN_TARGET, 'Open a screen (target, recordId, tab, filters), after the path guide.', static::REGISTERED_BY),
            AgentClientToolRequestType::create(AgentClientToolRequest::TYPE_HIGHLIGHT, 'Mark elements of the screen by their references.', static::REGISTERED_BY),
            AgentClientToolRequestType::create(
                AgentClientToolRequest::TYPE_SET_WINDOW_MODE,
                'Set the view mode of the agent window (mode).',
                static::REGISTERED_BY,
                $this->isWindowModeRequestsEnabled()
            ),
            AgentClientToolRequestType::create(
                AgentClientToolRequest::TYPE_ACT_ON_SCREEN,
                'Act on the open screen: setFilters (filters), or sort, openRow, switchTab, press on an element the screen declares for the agent (reference, direction).',
                static::REGISTERED_BY,
                $this->isScreenActionRequestsEnabled()
            ),
        ] as $agentClientToolRequestType) {
            $agentClientToolRequestTypes->add($agentClientToolRequestType);
        }
        return $agentClientToolRequestTypes;
    }

    /** Whether the host switched on the typed actions on the open screen (`AI.agentUI.screenActionRequests`, default false) */
    public function isScreenActionRequestsEnabled(): bool
    {
        $settings = Config::get(static::CONFIG_KEY);
        return is_array($settings) && ($settings['screenActionRequests'] ?? false) === true;
    }

    /** Whether the host switched on the view mode requests (`AI.agentUI.windowModeRequests`, default false) */
    public function isWindowModeRequestsEnabled(): bool
    {
        $settings = Config::get(static::CONFIG_KEY);
        return is_array($settings) && ($settings['windowModeRequests'] ?? false) === true;
    }
}
