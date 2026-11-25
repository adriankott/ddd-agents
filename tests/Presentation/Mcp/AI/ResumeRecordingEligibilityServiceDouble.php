<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\AI;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationEligibility;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentEligibilityLogContext;
use DDD\Domain\AI\Services\Conversations\Eligibility\AgentAutomationEligibilityService;

/**
 * An eligibility in which every space and account is in the state and for the reason the test names. It records
 * which scope was read and which operation it was asked to refuse.
 */
class ResumeRecordingEligibilityServiceDouble extends AgentAutomationEligibilityService
{
    public string $fixtureState = AgentAutomationEligibility::STATE_ELIGIBLE;

    public ?string $fixtureReason = null;

    /** @var bool Whether a scope that is not eligible refuses; false stands for an application that lets the operation through */
    public bool $fixtureRefusesWhenNotEligible = true;

    /** @var string[] The scopes whose eligibility was read, in order, each with whether the cache was allowed */
    public array $eligibilityReads = [];

    /** @var string[] The operations the service was asked to refuse, in order, each with the schedule it was asked for */
    public array $refusalQuestions = [];

    public function findEligibilityForAgentSpace(AgentSpace $agentSpace, bool $useCache = true): AgentAutomationEligibility
    {
        $this->eligibilityReads[] = 'space ' . $agentSpace->id . ($useCache ? ', cache allowed' : ', read fresh');
        return $this->buildEligibility();
    }

    public function findEligibilityForAccountId(int $accountId, bool $useCache = true): AgentAutomationEligibility
    {
        $this->eligibilityReads[] = 'account ' . $accountId . ($useCache ? ', cache allowed' : ', read fresh');
        return $this->buildEligibility();
    }

    public function refusesAutomation(AgentAutomationEligibility $eligibility, string $operation, AgentEligibilityLogContext $logContext): bool
    {
        $this->refusalQuestions[] = $operation . ' of schedule ' . $logContext->scheduleId;
        return $this->fixtureRefusesWhenNotEligible && !$eligibility->isEligible();
    }

    protected function buildEligibility(): AgentAutomationEligibility
    {
        $eligibility = AgentAutomationEligibility::eligible();
        $eligibility->state = $this->fixtureState;
        $eligibility->reason = $this->fixtureReason;
        return $eligibility;
    }
}
