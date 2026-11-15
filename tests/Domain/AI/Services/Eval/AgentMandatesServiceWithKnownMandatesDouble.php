<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Mandates\AgentMandate;
use DDD\Domain\AI\Entities\Mandates\AgentMandates;
use DDD\Domain\AI\Services\Mandates\AgentMandatesService;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DatabaseRepoEntity;

/** The mandates seam of a teardown test: the mandates are set by the test, every read and write is counted, nothing is stored. */
class AgentMandatesServiceWithKnownMandatesDouble extends AgentMandatesService
{
    /** @var AgentMandate[] The account's mandates of the space */
    public array $knownMandates = [];

    /** @var int How often the mandates were read */
    public int $mandateReads = 0;

    /** @var array<int, array{id: int, status: string|null}> The writes, in order */
    public array $mandateWrites = [];

    public function findMandatesForAccount(int $accountId, ?AgentSpace $agentSpace = null): AgentMandates
    {
        $this->mandateReads++;
        $agentMandates = new AgentMandates();
        foreach ($this->knownMandates as $knownMandate) {
            $agentMandates->add($knownMandate);
        }
        return $agentMandates;
    }

    public function update(DefaultObject $entity, int $depth = DatabaseRepoEntity::UPDATE_DEFAULT_RECURSIVE_DEPTH): DefaultObject
    {
        if ($entity instanceof AgentMandate) {
            $this->mandateWrites[] = ['id' => (int)$entity->id, 'status' => $entity->status];
        }
        return $entity;
    }
}
