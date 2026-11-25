<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Voice\Dtos;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCalls;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The answer of the admin list of calls. */
class AdminAgentVoiceCallsGetResponseDto extends RestResponseDto
{
    /**
     * @var AgentVoiceCalls The calls the caller may read that match the request's query options: per call its account,
     * direction, the times it was answered and ended, the end reason, the booked seconds and the conversation it ran on
     */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public AgentVoiceCalls $agentVoiceCalls;

    /**
     * @var AdminAgentVoiceCallsDerivedValues What the server derives per call: one entry per call of
     * {@see self::$agentVoiceCalls}, in the same order, each with the call's id, the state of the call at the time of
     * the answer (`RINGING`, `IN_PROGRESS`, `ENDED`; one reading of the clock for all rows) and the costs of its booked
     * seconds at the catalog's present price per session second (no costs in an entry = no price known, or nothing
     * booked; not a read of an application's cost ledger)
     */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public AdminAgentVoiceCallsDerivedValues $agentVoiceCallDerivedValues;
}
