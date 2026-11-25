<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationPurposeRunsService;

/** The purpose questions the turn start asks, answered by the test. */
class PurposeRunsServiceWithScriptedAnswersDouble extends AIConversationPurposeRunsService
{
    public bool $purposeSeedsItsTaskPlan = false;

    public bool $purposeWorksAutonomously = false;

    public function __construct()
    {
    }

    public function conversationPurposeSeedsItsTaskPlan(AIConversation $conversation): bool
    {
        return $this->purposeSeedsItsTaskPlan;
    }

    public function conversationPurposeWorksAutonomously(AIConversation $conversation): bool
    {
        return $this->purposeWorksAutonomously;
    }
}
