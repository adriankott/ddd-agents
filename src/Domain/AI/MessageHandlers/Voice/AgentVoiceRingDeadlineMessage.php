<?php

declare(strict_types=1);

namespace DDD\Domain\AI\MessageHandlers\Voice;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;

/**
 * Looks at ONE ring right after its deadline. Without this look nothing would end a ring nobody answered until the
 * next turn of its conversation: the call would stay open for a client that reads its state, and the agent's
 * follow-up ("you did not pick up") would come minutes or hours late. Dispatched for every ring with a delay of the
 * ring length plus a grace ({@see \DDD\Domain\AI\Services\Voice\AgentVoiceCallStartsService::startRing()}); the handler
 * ends the call EXPIRED behind its exactly-once claim, which starts the follow-up of a call the agent itself started. A
 * ring that was answered, declined or already expired in the meantime makes it a no-op, and so does a look before the
 * deadline: a message the application does not route runs at once, in the dispatching process, and does nothing; the
 * ring then ends only at the next turn start of its conversation or the next look at its account
 * ({@see AgentVoiceMessengerTransports}). No cron.
 *
 * Ids on the wire only: the handler reloads the call.
 */
class AgentVoiceRingDeadlineMessage extends AppMessage
{
    /**
     * @var string The transport the handler consumes: the one {@see AgentVoiceMessengerTransports} declares for this
     * message, in the routing list an application copies
     */
    public const string TRANSPORT = AgentVoiceMessengerTransports::RING_DEADLINE;

    public int $agentVoiceCallId;

    public static string $messageHandler = AgentVoiceRingDeadlineHandler::class;

    public function __construct(int $agentVoiceCallId)
    {
        parent::__construct();
        $this->agentVoiceCallId = $agentVoiceCallId;
    }
}
