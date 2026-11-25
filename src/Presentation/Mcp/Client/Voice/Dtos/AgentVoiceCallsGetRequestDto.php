<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Voice\Dtos;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCalls;
use DDD\Presentation\Api\Agents\QueryOptions\DtoQueryOptionsOfApplicationClassTrait;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use DDD\Presentation\Base\QueryOptions\DtoQueryOptions;

/**
 * Input of `getVoiceCalls`: the query options over the call log of the account (`filters` on `startedAt`, `top`,
 * `skip`, …) plus ONE switch, `onlyRunning`, which reads a different query (the calls ringing or running right now).
 *
 * The options are those of the class the application uses for the call set, so a column its subclass adds is
 * filterable here.
 */
#[DtoQueryOptions(baseEntity: AgentVoiceCalls::class)]
class AgentVoiceCallsGetRequestDto extends RequestDto
{
    use DtoQueryOptionsOfApplicationClassTrait;

    /** @var bool True = only the calls ringing or running RIGHT NOW (the paging and filter options are ignored then); false (default) = the call history, newest first */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public bool $onlyRunning = false;
}
