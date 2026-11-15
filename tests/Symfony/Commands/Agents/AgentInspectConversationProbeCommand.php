<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Symfony\Commands\Agents\AgentInspectConversationCommand;
use DDD\Symfony\Commands\Agents\Inspect\ConversationHeaderRow;

/**
 * The inspector of an application that knows its space by a key of its own and prints that key in the header; the
 * conversations service is the test's double when the test hands one in.
 */
class AgentInspectConversationProbeCommand extends AgentInspectConversationCommand
{
    /** @var string The label of the application's space row */
    public const string SPACE_ROW_LABEL = 'site';

    /** @var string The name of the probe; its own, so the real command's registration is never touched */
    public const string PROBE_COMMAND_NAME = 'agents-test:inspect-probe';

    /** @var AIConversationsService|null The conversations service the lookup goes through; null = the module's */
    public ?AIConversationsService $conversationsServiceDouble = null;

    protected function getAIConversationsService(): AIConversationsService
    {
        return $this->conversationsServiceDouble ?? parent::getAIConversationsService();
    }

    protected function describeAgentSpaceRow(AIConversation $conversation): ConversationHeaderRow
    {
        return new ConversationHeaderRow(self::SPACE_ROW_LABEL, 'site of space ' . ($conversation->agentSpaceId ?? 'none'));
    }
}
