<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\Agent\AgentClientContextService;
use RuntimeException;

/** An application's record lookup that fails. */
class AgentClientContextServiceThrowingDouble extends AgentClientContextService
{
    public function describeRecordOfClientContext(AIConversation $conversation, string $recordKind, string $recordId): ?string
    {
        throw new RuntimeException('record store unreachable');
    }
}
