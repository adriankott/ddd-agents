<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Admission;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\Base\Entities\Entity;
use RuntimeException;

/** The space service answering the billing entity of a conversation the test hands in; can be made to fail. */
class ScriptedAgentSpacesService extends AgentSpacesService
{
    public ?Entity $billingEntity = null;

    public ?string $failureMessage = null;

    /** @var int[] */
    public array $askedConversationIds = [];

    public function findBillingEntityOfConversation(AIConversation $conversation): ?Entity
    {
        $this->askedConversationIds[] = (int)$conversation->id;
        if ($this->failureMessage !== null) {
            throw new RuntimeException($this->failureMessage);
        }

        return $this->billingEntity;
    }
}
