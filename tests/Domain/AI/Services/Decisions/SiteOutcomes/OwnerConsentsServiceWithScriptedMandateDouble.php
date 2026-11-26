<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentMandateCoverage;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentRequest;
use DDD\Domain\AI\Entities\Mandates\AgentMandate;
use DDD\Domain\AI\Services\Conversations\Agent\OwnerConsentsService;

/**
 * The owner-consent service with the standing mandate and the host's coverage answer scripted, and the tool's window and
 * refusal floor read as "none" (no stored working state): the mandate path and the consent path without a database.
 */
class OwnerConsentsServiceWithScriptedMandateDouble extends OwnerConsentsService
{
    /** The standing mandate the lookup finds; null = none (never granted, revoked, expired) */
    public ?AgentMandate $standingMandate = null;

    /** The host's answer; null = the package default (covered) */
    public ?OwnerConsentMandateCoverage $scriptedCoverage = null;

    /** @var string[] the action classes the lookup was asked for, in call order */
    public array $lookedUpActionClasses = [];

    protected function findStandingMandateCoveringStep(AgentToolCallProvenance $callProvenance, string $actionClass): ?AgentMandate
    {
        $this->lookedUpActionClasses[] = $actionClass;
        return $this->standingMandate;
    }

    protected function decideMandateCoverage(AgentMandate $agentMandate, AgentToolCallProvenance $callProvenance, OwnerConsentRequest $request): OwnerConsentMandateCoverage
    {
        return $this->scriptedCoverage ?? parent::decideMandateCoverage($agentMandate, $callProvenance, $request);
    }

    public function findOwnerConsentWindowStart(AIConversation $conversation, string $toolName): int
    {
        return -1;
    }

    public function findOwnerConsentRefusedThrough(AIConversation $conversation, string $toolName): int
    {
        return -1;
    }
}
