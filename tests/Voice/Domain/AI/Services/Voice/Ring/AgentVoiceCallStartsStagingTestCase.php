<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Ring;

use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeAudience;
use DDD\Domain\AI\Services\Conversations\Eligibility\AgentAutomationEligibilityService;
use DDD\Domain\AI\Services\Eval\EvalOutboundRecipientsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallAdmissionService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStartsService;
use DDD\Infrastructure\Services\DDDService;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * The staging of the ring ({@see AgentVoiceRingStagingTestCase}) plus the gates the call start asks: the admission
 * port ({@see FixedRefusalAgentVoiceCallAdmissionServiceDouble}, admitting by default), the eligibility gate and the
 * eval guard of the package (mocks that refuse and block nothing by default). A client of the owner's account
 * listens on the fake transport unless a test says otherwise.
 */
abstract class AgentVoiceCallStartsStagingTestCase extends AgentVoiceRingStagingTestCase
{
    protected FixedRefusalAgentVoiceCallAdmissionServiceDouble $admissionService;

    protected AgentAutomationEligibilityService&MockObject $eligibilityService;

    protected EvalOutboundRecipientsService&MockObject $evalOutboundRecipientsService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admissionService = new FixedRefusalAgentVoiceCallAdmissionServiceDouble();
        $this->replaceService(AgentVoiceCallAdmissionService::class, $this->admissionService);
        $this->eligibilityService = $this->createMock(AgentAutomationEligibilityService::class);
        $this->replaceService(AgentAutomationEligibilityService::class, $this->eligibilityService);
        $this->evalOutboundRecipientsService = $this->createMock(EvalOutboundRecipientsService::class);
        $this->replaceService(EvalOutboundRecipientsService::class, $this->evalOutboundRecipientsService);
        $this->realtimeChannel->listeningByMailboxName['account-' . static::OWNER_ACCOUNT_ID] = true;
    }

    protected function getCallStartsService(): AgentVoiceCallStartsService
    {
        /** @var AgentVoiceCallStartsService $agentVoiceCallStartsService */
        $agentVoiceCallStartsService = DDDService::instance()->getService(AgentVoiceCallStartsService::class);
        return $agentVoiceCallStartsService;
    }

    public static function getMailboxNameOf(AgentRealtimeAudience $agentRealtimeAudience): string
    {
        return $agentRealtimeAudience->getMailboxName();
    }
}
