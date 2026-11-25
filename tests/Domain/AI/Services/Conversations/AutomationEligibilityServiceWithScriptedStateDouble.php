<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationEligibility;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentEligibilityLogContext;
use DDD\Domain\AI\Services\Conversations\Eligibility\AgentAutomationEligibilityService;

/** An eligibility in which every space and account is in the state the test names; it counts its reads. */
class AutomationEligibilityServiceWithScriptedStateDouble extends AgentAutomationEligibilityService
{
    public string $fixtureState = AgentAutomationEligibility::STATE_ELIGIBLE;

    public int $eligibilityReadsCount = 0;

    public function findEligibilityForAgentSpace(AgentSpace $agentSpace, bool $useCache = true): AgentAutomationEligibility
    {
        return $this->buildEligibility();
    }

    public function findEligibilityForAccountId(int $accountId, bool $useCache = true): AgentAutomationEligibility
    {
        return $this->buildEligibility();
    }

    public function refusesAutomation(AgentAutomationEligibility $eligibility, string $operation, AgentEligibilityLogContext $logContext): bool
    {
        return !$eligibility->isEligible();
    }

    protected function buildEligibility(): AgentAutomationEligibility
    {
        $this->eligibilityReadsCount++;
        $eligibility = AgentAutomationEligibility::eligible();
        $eligibility->state = $this->fixtureState;
        return $eligibility;
    }
}
