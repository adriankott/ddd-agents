<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Base\Repo\DB\DBEntity;

/**
 * The agent-spaces seam of an evaluation hooks test: the spaces and their owners are set by the test, and every read
 * records whether the rights restrictions were lifted while it ran; nothing is read from a database.
 */
class AgentSpacesServiceWithKnownSpacesDouble extends AgentSpacesService
{
    /** @var array<int, AgentSpace> The stored spaces, by id */
    public array $agentSpacesById = [];

    /** @var array<int, Account> The owner of each space, by space id */
    public array $ownerAccountsByAgentSpaceId = [];

    /** @var bool[] Per read of a space: whether the rights restrictions were lifted */
    public array $rightsLiftedPerRead = [];

    /** @var int[] The ids of the conversations whose space relations were attached (0 = without an id) */
    public array $attachedConversationIds = [];

    public function find($entityId, bool $useEntityRegistrCache = true): ?AgentSpace
    {
        $this->rightsLiftedPerRead[] = !DBEntity::getApplyRightsRestrictions();
        return $this->agentSpacesById[(int)$entityId] ?? null;
    }

    public function findOwnerAccountOfAgentSpace(AgentSpace $agentSpace): ?Account
    {
        return $this->ownerAccountsByAgentSpaceId[(int)$agentSpace->id] ?? null;
    }

    public function attachAgentSpaceRelations(AIConversation $conversation, bool $bypassRegistry): void
    {
        $this->attachedConversationIds[] = (int)($conversation->id ?? 0);
    }
}
