<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Realtime;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Realtime messages, keyed by type and key ({@see AgentRealtimeMessage::uniqueKey()}): a second message of the same
 * type and key replaces the first.
 *
 * @property AgentRealtimeMessage[] $elements
 * @method AgentRealtimeMessage[] getElements()
 * @method AgentRealtimeMessage|null getByUniqueKey(string $uniqueKey)
 * @method AgentRealtimeMessage|null first()
 */
class AgentRealtimeMessages extends ObjectSet
{
    /** The messages of one type, in the set's order */
    public function filterByMessageType(string $messageType): static
    {
        $agentRealtimeMessages = new static();
        foreach ($this->getElements() as $agentRealtimeMessage) {
            if ($agentRealtimeMessage->messageType === $messageType) {
                $agentRealtimeMessages->add($agentRealtimeMessage);
            }
        }
        return $agentRealtimeMessages;
    }

    public function findMessage(string $messageType, string $messageKey): ?AgentRealtimeMessage
    {
        return $this->getByUniqueKey(AgentRealtimeMessage::uniqueKeyStatic($messageType . '|' . $messageKey));
    }
}
