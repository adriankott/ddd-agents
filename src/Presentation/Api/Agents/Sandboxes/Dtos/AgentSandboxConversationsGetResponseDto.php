<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Sandboxes\Dtos;

use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxConversations;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The newest root conversations of the workspace's agent space */
class AgentSandboxConversationsGetResponseDto extends RestResponseDto
{
    /** @var AgentSandboxConversations The conversations, newest change first */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public AgentSandboxConversations $conversations;
}
