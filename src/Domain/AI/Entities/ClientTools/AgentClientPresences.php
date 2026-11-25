<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\ClientTools;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The presence statements of the clients of one conversation, newest first.
 *
 * @property AgentClientPresence[] $elements
 * @method AgentClientPresence[] getElements()
 * @method AgentClientPresence|null getByUniqueKey(string $uniqueKey)
 * @method AgentClientPresence|null first()
 */
class AgentClientPresences extends ObjectSet
{
    /** The statements fresh at `$now` */
    public function filterFresh(\DateTimeInterface $now): static
    {
        $agentClientPresences = new static();
        foreach ($this->getElements() as $agentClientPresence) {
            if ($agentClientPresence->isFresh($now)) {
                $agentClientPresences->add($agentClientPresence);
            }
        }
        return $agentClientPresences;
    }

    /**
     * The client a client tool request goes to among these: the one the user sent from last, else the newest visible
     * one, else the newest one; null for an empty set.
     */
    public function pickTargetPresence(): ?AgentClientPresence
    {
        $lastSentFrom = null;
        foreach ($this->getElements() as $agentClientPresence) {
            if ($agentClientPresence->lastOwnerSendAt !== null
                && ($lastSentFrom === null || $agentClientPresence->lastOwnerSendAt > $lastSentFrom->lastOwnerSendAt)
            ) {
                $lastSentFrom = $agentClientPresence;
            }
        }
        if ($lastSentFrom !== null) {
            return $lastSentFrom;
        }
        $picked = null;
        foreach ($this->getElements() as $agentClientPresence) {
            if ($picked === null || static::isPreferredOver($agentClientPresence, $picked)) {
                $picked = $agentClientPresence;
            }
        }
        return $picked;
    }

    /** Whether the candidate wins: a visible client before a hidden one, then the newer statement */
    protected static function isPreferredOver(AgentClientPresence $candidate, AgentClientPresence $current): bool
    {
        $candidateVisible = $candidate->visible === true;
        $currentVisible = $current->visible === true;
        if ($candidateVisible !== $currentVisible) {
            return $candidateVisible;
        }
        return $candidate->lastSeenAt > $current->lastSeenAt;
    }
}
