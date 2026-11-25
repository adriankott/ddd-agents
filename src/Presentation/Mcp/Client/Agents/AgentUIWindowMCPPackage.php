<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents;

use DDD\Domain\AI\Entities\ClientTools\AgentClientToolAnswer;
use DDD\Domain\AI\Entities\ClientTools\AgentClientToolRequest;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRights;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPRequiresAccountRights;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolExecutionTimeout;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolReturnsTrustedContent;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolSummary;
use DDD\Domain\AI\Services\ClientTools\AgentClientToolRequestsService;
use DDD\Domain\AI\Services\ClientTools\AgentUIClientToolRequestTypeProviderService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentUIToolResponseDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\SetAgentWindowModeRequestDto;
use Throwable;

/**
 * The view mode of the agent window (`Agent.UI`, request type `SET_WINDOW_MODE`): available only while the host
 * switched the request type on (`AI.agentUI.windowModeRequests`). Without it the client steps the window aside on its
 * own before it opens a screen, marks elements or takes a picture, and restores it afterwards. The modes are the
 * application's (its agent-window adapter); an unknown mode is refused by the client.
 */
#[MCPRequiresAccountRights(AgentAccountRights::USE_UI)]
#[MCPToolExecutionTimeout(15, 'waits for the user\'s client (at most 8 s)')]
class AgentUIWindowMCPPackage extends MCPPackage
{
    public static ?string $name = 'agent.ui.window';

    public static ?string $description = 'Set the view mode of the agent window in the user\'s client.';

    /** @var int How long setAgentWindowMode waits */
    public const int WINDOW_MODE_WAIT_SECONDS = 8;

    public const string WINDOW_MODE_SET = 'The agent window is now in mode %s.';

    /** Offered only while the host switched on the view mode requests */
    public static function isAvailable(): bool
    {
        try {
            /** @var AgentUIClientToolRequestTypeProviderService $agentUIClientToolRequestTypeProviderService */
            $agentUIClientToolRequestTypeProviderService = DDDService::instance()->getService(AgentUIClientToolRequestTypeProviderService::class);
            return $agentUIClientToolRequestTypeProviderService->isWindowModeRequestsEnabled();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Sets the view mode of the agent window: only when the user asks for it ("make the chat smaller", "move it
     * aside"). The client steps the window aside on its own for openScreen, highlightOnScreen and pictures.
     *
     * @example {"mode": "aside"}
     * @example {"mode": "restore"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ, resultIsReusedWithinTurn: false)]
    #[MCPToolSummary('Sets the view mode of the agent window in the user\'s client (a mode the application knows, or restore).')]
    #[MCPToolReturnsTrustedContent('a fixed sentence of the server')]
    public function setAgentWindowMode(
        SetAgentWindowModeRequestDto $requestDto,
        AgentClientToolRequestsService $agentClientToolRequestsService,
        AgentToolCallProvenance $agentToolCallProvenance
    ): AgentUIToolResponseDto {
        $mode = trim((string)$requestDto->mode);
        if (preg_match(AgentUIMCPPackage::TOKEN_PATTERN, $mode) !== 1) {
            return AgentUIMCPPackage::buildLineResponse(sprintf(AgentUIMCPPackage::INVALID_ARGUMENT, 'mode'), 'INVALID_ARGUMENT');
        }
        $answer = AgentUIMCPPackage::sendClientToolRequest($agentClientToolRequestsService, $agentToolCallProvenance, AgentClientToolRequest::TYPE_SET_WINDOW_MODE, ['mode' => $mode], static::WINDOW_MODE_WAIT_SECONDS);
        if ($answer->kind !== AgentClientToolAnswer::KIND_ANSWERED) {
            return AgentUIMCPPackage::buildUnansweredResponse($answer, static::WINDOW_MODE_WAIT_SECONDS, false);
        }
        return AgentUIMCPPackage::buildLineResponse(sprintf(static::WINDOW_MODE_SET, $mode), (string)$answer->outcome);
    }
}
