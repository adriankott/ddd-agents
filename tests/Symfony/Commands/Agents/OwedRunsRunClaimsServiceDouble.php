<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService;

/** The run claims: a live claim on every conversation the test listed, none elsewhere. */
class OwedRunsRunClaimsServiceDouble extends AIConversationRunClaimsService
{
    /** @var int[] */
    public array $claimedConversationIds = [];

    public function hasLiveRunClaim(AIConversation $conversation): bool
    {
        return in_array((int)$conversation->id, $this->claimedConversationIds, true);
    }
}
