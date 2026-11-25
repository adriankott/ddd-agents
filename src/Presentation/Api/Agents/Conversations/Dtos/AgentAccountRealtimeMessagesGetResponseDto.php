<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeListenerCredential;
use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeMessageDeliveries;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * What a client that shows no conversation needs of the realtime port: the open messages addressed to the signed-in
 * account (for every client of the account or for the reading one), and the credential to listen on the host's
 * transport without a conversation.
 */
class AgentAccountRealtimeMessagesGetResponseDto extends RestResponseDto
{
    /**
     * @var AgentRealtimeMessageDeliveries The open messages of the account's mailbox for the reading client (types other
     * packages registered, e.g. a call's ring), expired ones left out; a client acts on one type and key once
     */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public AgentRealtimeMessageDeliveries $realtimeMessages;

    /**
     * @var AgentRealtimeListenerCredential|null What the client needs to listen on the host's transport for the account;
     * null without a transport (the client then repeats this read)
     */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?AgentRealtimeListenerCredential $realtimeListenerCredential = null;
}
