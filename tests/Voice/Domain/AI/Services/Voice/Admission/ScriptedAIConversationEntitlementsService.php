<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Admission;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRightsInterface;
use DDD\Domain\AI\Services\Conversations\Entitlements\AIConversationEntitlementsService;

/**
 * The entitlements of the package with the composed rights the test hands in, recording which read was asked:
 * "current" (the signed-in account) or "conversation:<id>".
 */
class ScriptedAIConversationEntitlementsService extends AIConversationEntitlementsService
{
    public ?AgentAccountRightsInterface $rightsOfCurrentAccount = null;

    public ?AgentAccountRightsInterface $rightsOfConversation = null;

    public bool $rightsAnsweredByTheApplication = false;

    /** @var string[] */
    public array $askedReads = [];

    public function findComposedAccountRightsOfCurrentAccount(): ?AgentAccountRightsInterface
    {
        $this->askedReads[] = 'current';

        return $this->rightsOfCurrentAccount;
    }

    public function findComposedAccountRightsOfConversation(AIConversation $conversation): ?AgentAccountRightsInterface
    {
        $this->askedReads[] = 'conversation:' . $conversation->id;

        return $this->rightsOfConversation;
    }

    public function isAccountRightsAnsweredByTheApplication(): bool
    {
        $this->askedReads[] = 'answeredByTheApplication';

        return $this->rightsAnsweredByTheApplication;
    }
}
