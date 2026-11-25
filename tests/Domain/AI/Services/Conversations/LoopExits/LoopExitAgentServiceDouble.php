<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\LoopExits;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentReply;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentTransientMessageIds;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;

/**
 * The REAL agent loop of the package, entered the way a worker invocation enters it: only the edges around it
 * (the model call, the tool execution, the row writes, the claims) are the container doubles of the test.
 */
class LoopExitAgentServiceDouble extends AIConversationAgentService
{
    public function __construct()
    {
        // The parent service is container-managed and holds no constructor state.
    }

    public function runLoop(AIConversation $conversation, ?string $runToken, bool $asyncDelegations): AgentReply
    {
        return $this->runConversationLoop(
            AgentRun::forTurnEntry(),
            $conversation,
            true,
            AgentTransientMessageIds::startingAt($conversation->messages->getHighestMessageId()),
            null,
            null,
            false,
            $asyncDelegations,
            $runToken
        );
    }
}
