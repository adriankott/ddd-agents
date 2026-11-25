<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\DispatchTable;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\MCPPackages\ToolDefinitions;

/**
 * A conversation whose purpose and whose loaded tool definitions are given by the test: the purpose without a purpose
 * file, the definitions without a skill that binds a package.
 */
class DispatchTableConversation extends AIConversation
{
    /** The purpose of this conversation and of its tree; null = none */
    public ?AIConversationPurpose $stubbedPurpose = null;

    /** The definitions of the loaded tools; null = read from the loaded skills */
    public ?ToolDefinitions $stubbedLoadedToolDefinitions = null;

    public function findPurpose(): ?AIConversationPurpose
    {
        return $this->stubbedPurpose;
    }

    public function findPurposeOfConversationTree(?AgentRun $agentRun = null): ?AIConversationPurpose
    {
        return $this->stubbedPurpose;
    }

    public function buildLoadedToolDefinitions(): ToolDefinitions
    {
        return $this->stubbedLoadedToolDefinitions ?? parent::buildLoadedToolDefinitions();
    }
}
