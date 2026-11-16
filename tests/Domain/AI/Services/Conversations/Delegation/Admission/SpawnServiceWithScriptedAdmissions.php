<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Delegation\Admission;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationAdmission;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationSpawnService;

/**
 * The spawn service whose admissions answer from a script, in order, and which keeps the admission it would write as
 * the turn event instead of writing it.
 */
class SpawnServiceWithScriptedAdmissions extends AIConversationDelegationSpawnService
{
    /** @var AgentDelegationAdmission[] the admissions the tree's queue answers, in order */
    public array $scriptedAdmissions = [];

    public int $admissionCount = 0;

    public ?AgentDelegationAdmission $recordedAdmission = null;

    /** @var int[] */
    public array $recordedSpawnedChildIds = [];

    /** @param int[] $spawnedChildIds */
    public function admitSpawnedChildren(AIConversation $parentConversation, array $spawnedChildIds): AgentDelegationAdmission
    {
        return $this->admitSpawnedChildrenAndRecordAdmission(AgentRun::forTurnEntry(), $parentConversation, $spawnedChildIds);
    }

    public function promoteQueuedDelegationsAndDescribeAdmission(int $rootConversationId): AgentDelegationAdmission
    {
        $this->admissionCount++;
        return array_shift($this->scriptedAdmissions) ?? AgentDelegationAdmission::forTree($rootConversationId, 5);
    }

    protected function recordDelegationAdmissionTurnEvent(AgentRun $agentRun, AIConversation $parentConversation, AgentDelegationAdmission $agentDelegationAdmission, array $spawnedChildIds): void
    {
        $this->recordedAdmission = $agentDelegationAdmission;
        $this->recordedSpawnedChildIds = $spawnedChildIds;
    }
}
