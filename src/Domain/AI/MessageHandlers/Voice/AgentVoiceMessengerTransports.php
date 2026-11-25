<?php

declare(strict_types=1);

namespace DDD\Domain\AI\MessageHandlers\Voice;

use DDD\Domain\AI\MessageHandlers\Voice\SessionWorker\VoiceLiveSessionAttachMessage;

/**
 * The Messenger transports the voice channel's workers consume, and which message goes to which. Each handler binds
 * to its name (`#[AsMessageHandler(fromTransport: …)]`); an application defines the transports in its
 * `messenger.yaml` (DSN, retry), routes the messages of {@see AgentVoiceMessengerTransports::ROUTING_BY_MESSAGE_CLASS}
 * to them and runs a worker pool for each. The framework registers no routing and no transport: this class is the
 * list an application copies. A message the application does not route runs synchronously in the dispatching request:
 * - for {@see VoiceLiveSessionAttachMessage} that request would then stay with the call until it ends;
 * - for {@see AgentVoiceRingDeadlineMessage} the look at the ring runs at once, the moment the ring starts, sees a
 *   ring inside its window and does nothing (the delay of the dispatch is lost with the transport). Such a ring is
 *   never ended at its deadline: it stops in the clients by itself, but its call stays unanswered and not ended until
 *   the next turn of its conversation starts or its account is looked at again (a new ring, the open-call check),
 *   and the agent's follow-up of a call nobody answered comes that much later.
 *
 * The pool of {@see AgentVoiceMessengerTransports::RING_DEADLINE}: one short message per ring, delayed by the ring
 * length plus a few seconds, so the transport must keep delayed messages; any pool size, no limit per process. The
 * handler never throws and a repeated look is harmless, so no retry is needed.
 *
 * The pool of {@see AgentVoiceMessengerTransports::LIVE_SESSION}:
 * - one message = one running call, and the handler returns only when the call is over (minutes): the pool size is the
 *   number of calls at the same time;
 * - a worker process accompanies one call at a time and may take the next one afterwards: the session worker puts the
 *   state of its call back at the start of every attach, so the consumer needs no limit per process;
 * - the stop grace of the pool is above `AI.agentVoice` → `limits.maximumCallSeconds`, so a deploy does not cut a
 *   call: a SIGTERM during a call is passed on to the consumer when the call is over; a worker that dies leaves its
 *   message unacknowledged, and the successor that gets it takes the call over;
 * - the worker needs outbound WebSocket access to the provider's host.
 */
class AgentVoiceMessengerTransports
{
    /** @var string One running call per message ({@see VoiceLiveSessionAttachMessage}) */
    public const string LIVE_SESSION = 'voice_live_session';

    /** @var string One look at a ring right after its deadline per message ({@see AgentVoiceRingDeadlineMessage}) */
    public const string RING_DEADLINE = 'voice_ring_deadline';

    /** @var string[] Every transport of the voice channel */
    public const array ALL = [
        AgentVoiceMessengerTransports::LIVE_SESSION,
        AgentVoiceMessengerTransports::RING_DEADLINE,
    ];

    /** @var array<class-string, string> The routing an application's `messenger.yaml` carries: message class => transport */
    public const array ROUTING_BY_MESSAGE_CLASS = [
        VoiceLiveSessionAttachMessage::class => AgentVoiceMessengerTransports::LIVE_SESSION,
        AgentVoiceRingDeadlineMessage::class => AgentVoiceMessengerTransports::RING_DEADLINE,
    ];
}
