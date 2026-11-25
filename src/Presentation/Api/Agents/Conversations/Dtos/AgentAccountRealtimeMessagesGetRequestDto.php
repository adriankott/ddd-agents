<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The account-level realtime read: the open messages of the realtime port addressed to the signed-in account (not to
 * one of its conversations) — what reaches a client that shows no conversation, e.g. a call's ring.
 */
class AgentAccountRealtimeMessagesGetRequestDto extends RequestDto
{
    /**
     * @var string|null The id of the reading client (random per mount of the agent window); a message addressed to one
     * client reaches only that client
     */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?string $clientId = null;
}
