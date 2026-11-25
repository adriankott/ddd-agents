<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\RunRelease;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationNotificationsService;

/** The sub-agent notifications of the release stage: no notification was lost, nothing is written. */
class RunReleaseDelegationNotificationsServiceDouble extends AIConversationDelegationNotificationsService
{
    public function __construct()
    {
        // The parent service is container-managed and holds no constructor state.
    }

    public function deliverLostNotificationsOfParent(AIConversation $parentConversation): int
    {
        return 0;
    }
}
