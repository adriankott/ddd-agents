<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\RunRelease;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationEligibility;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\Base\Entities\Entity;

/**
 * The conversations service of the release stage: the automation eligibility of the conversation's scope is scripted
 * (a scripted queue, eligible when empty; every read is recorded with its answer and whether it was cached), the suppression stamp is
 * recorded, nothing is read or written.
 */
class RunReleaseConversationsServiceDouble extends AIConversationsService
{
    public RunReleaseEventLog $eventLog;

    /** @var AgentAutomationEligibility[] the answers of the eligibility reads, in call order; the last one repeats (none: eligible) */
    public array $eligibilityAnswers = [];

    public function __construct(RunReleaseEventLog $eventLog)
    {
        // The parent service is container-managed and holds no constructor state.
        $this->eventLog = $eventLog;
    }

    public function findAutomationEligibilityForConversation(AIConversation $conversation, bool $useCache = true): AgentAutomationEligibility
    {
        $eligibility = count($this->eligibilityAnswers) > 1
            ? array_shift($this->eligibilityAnswers)
            : ($this->eligibilityAnswers[0] ?? AgentAutomationEligibility::eligible());
        $this->eventLog->record('eligibility ' . $eligibility->state . ($useCache ? ' cached' : ' fresh'));
        return $eligibility;
    }

    public function recordSuppressionOfConversation(AIConversation $conversation, string $suppressionReason): void
    {
        $this->eventLog->record('recordSuppression ' . $suppressionReason);
    }

    public function find(string|int|null $entityId, bool $useEntityRegistrCache = true): ?Entity
    {
        return null;
    }
}
