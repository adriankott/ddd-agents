<?php

declare(strict_types=1);

namespace DDD\Domain\AI\MessageHandlers\Voice\SessionWorker;

use DDD\Domain\AI\MessageHandlers\Voice\AgentVoiceMessengerTransports;
use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;

/**
 * Attach the session worker to ONE running call: open the provider's sideband WebSocket on the session id, answer the
 * voice model's delegations through the agent, write the transcript, book the seconds — until the call is over
 * ({@see VoiceLiveSessionAttachHandler}). The application routes the message to the transport
 * {@see AgentVoiceMessengerTransports::LIVE_SESSION}.
 *
 * Ids on the wire, nothing else: the provider session id is the call's identity everywhere (creation response,
 * sideband URL, control actions). The handler finds the call again through the call store.
 */
class VoiceLiveSessionAttachMessage extends AppMessage
{
    /** @var string The provider's session id, which the call carries as its providerCallId */
    public string $providerSessionId;

    public static string $messageHandler = VoiceLiveSessionAttachHandler::class;

    public function __construct(string $providerSessionId)
    {
        parent::__construct();
        $this->providerSessionId = $providerSessionId;
    }
}
