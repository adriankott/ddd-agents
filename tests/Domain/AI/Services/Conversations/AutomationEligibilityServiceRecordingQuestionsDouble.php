<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationEligibility;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentEligibilityLogContext;

/**
 * The scripted eligibility that also names which source decided, and records which scope was read (and whether
 * fresh) and which operation it was asked to refuse.
 */
class AutomationEligibilityServiceRecordingQuestionsDouble extends AutomationEligibilityServiceWithScriptedStateDouble
{
    /** @var string|null The source that decided the state; {@see AgentAutomationEligibility::SIGNAL_SOURCE_AGENT_SPACE} for the space, null for the account */
    public ?string $fixtureSignalSource = null;

    /** @var bool|null The application's answer to a refusal question; null: it refuses every scope that is not eligible */
    public ?bool $fixtureRefuses = null;

    /** @var string[] The scopes whose eligibility was read, in order, each with whether the cache was allowed */
    public array $recordedReads = [];

    /** @var string[] The operations the service was asked to refuse, in order, each with the state it was asked about */
    public array $recordedQuestions = [];

    public function findEligibilityForAgentSpace(AgentSpace $agentSpace, bool $useCache = true): AgentAutomationEligibility
    {
        $this->recordedReads[] = 'space ' . $agentSpace->id . ($useCache ? ', cache allowed' : ', read fresh');
        return parent::findEligibilityForAgentSpace($agentSpace, $useCache);
    }

    public function findEligibilityForAccountId(int $accountId, bool $useCache = true): AgentAutomationEligibility
    {
        $this->recordedReads[] = 'account ' . $accountId . ($useCache ? ', cache allowed' : ', read fresh');
        return parent::findEligibilityForAccountId($accountId, $useCache);
    }

    public function refusesAutomation(AgentAutomationEligibility $eligibility, string $operation, AgentEligibilityLogContext $logContext): bool
    {
        $this->recordedQuestions[] = $operation . ' of schedule ' . $logContext->scheduleId . ' on a scope that is ' . $eligibility->state;
        return $this->fixtureRefuses ?? parent::refusesAutomation($eligibility, $operation, $logContext);
    }

    protected function buildEligibility(): AgentAutomationEligibility
    {
        $eligibility = parent::buildEligibility();
        $eligibility->signalSource = $this->fixtureSignalSource;
        return $eligibility;
    }
}
