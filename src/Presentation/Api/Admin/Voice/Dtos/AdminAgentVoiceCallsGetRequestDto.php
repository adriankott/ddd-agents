<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Voice\Dtos;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCalls;
use DDD\Presentation\Api\Agents\QueryOptions\DtoQueryOptionsOfApplicationClassTrait;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\QueryOptions\DtoQueryOptions;

/**
 * The request of the admin list of calls: the query options of the call set (`filters`, `orderBy`, `top`, `skip`,
 * `select`, `expand`), newest call first when no order is asked for.
 *
 * What a screen filters by: the account (`accountId eq '12'`), a date range (`created`, `startedAt` or `endedAt` with
 * `bw` or `ge` / `le`), the length of the call as its booked seconds (`bookedSeconds ge '60'`), the end reason
 * (`endReason eq 'LIMIT_REACHED'`), the direction and the conversation (`aiConversationId`). Each of these also sorts
 * (`orderBy=bookedSeconds desc`). The accounts whose calls the caller may read are a filter of the query the options
 * cannot widen (`AdminAgentVoiceCallsTrait::findAccountIdsOfListableVoiceCalls()`).
 *
 * The COSTS of a call are no column: they are filtered and sorted through `bookedSeconds`, which for one model orders
 * the calls the same way. The duration (`endedAt - startedAt`) and the state of a call are no columns either.
 *
 * What the server derives per row is worked out from the row's columns: the costs from `bookedSeconds` and
 * `voiceProvider`, the state from `startedAt`, `endedAt`, `ringDeadlineAt` and the creation time. A `select` that
 * leaves one of them out makes those values wrong (no costs, the default provider's price, a state read from missing
 * times): a screen that shows them sends no narrowing `select`.
 *
 * The options are those of the class the application uses for the call set, so a column its subclass adds is
 * filterable here.
 */
#[DtoQueryOptions(baseEntity: AgentVoiceCalls::class)]
class AdminAgentVoiceCallsGetRequestDto extends RequestDto
{
    use DtoQueryOptionsOfApplicationClassTrait;
}
