<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\InternalErrorException;

/**
 * Where and for whom one evaluation run works: the agent space of the case, the account that owns it (the run
 * authenticates it and bills its model calls to it) and an open, read-only map of the application's own values (the id
 * of its entity the space stands for, the entity itself).
 *
 * Built by {@see \DDD\Domain\AI\Services\Eval\AgentEvalRunHooksService::resolveEvalScope()}. The agent space may not
 * be stored yet: a run that stores nothing writes no agent space row, so an application whose spaces are created on
 * first use resolves a scope without an id (and maybe without a space object) and the hooks store the space only when
 * the run stores a conversation or the harness calls a tool. {@see recordStoredAgentSpace()} is the one change the
 * scope takes: from "not stored" to "stored".
 *
 * A plain object of one run; no container service.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalScope
{
    /** @var AgentSpace|null The agent space of the run when one is loaded or assembled (stored or not); null = none known yet */
    protected ?AgentSpace $agentSpace = null;

    /** @var int|null The id of the stored agent space; null until the space is stored (or for a run at account level) */
    protected ?int $agentSpaceId = null;

    /** @var Account The account the run works for */
    protected Account $ownerAccount;

    /** @var array<string, mixed> The application's own values, by key */
    protected array $applicationValuesByKey = [];

    /**
     * The scope of a run in `$agentSpace` (null = no space object yet, or account level) for `$ownerAccount`.
     *
     * @param AgentSpace|null $agentSpace a stored space, a space object without a row, or null
     * @param Account $ownerAccount the account the run works for
     * @param array<string, mixed> $applicationValuesByKey the application's own values (read back with {@see getApplicationValue()})
     * @return static
     */
    public static function forAgentSpace(?AgentSpace $agentSpace, Account $ownerAccount, array $applicationValuesByKey = []): static
    {
        $agentEvalScope = new static();
        $agentEvalScope->agentSpace = $agentSpace;
        $agentEvalScope->agentSpaceId = isset($agentSpace->id) ? (int)$agentSpace->id : null;
        $agentEvalScope->ownerAccount = $ownerAccount;
        $agentEvalScope->applicationValuesByKey = $applicationValuesByKey;
        return $agentEvalScope;
    }

    /** The id of the stored agent space; null while it is not stored (or for a run at account level). */
    public function getAgentSpaceId(): ?int
    {
        return $this->agentSpaceId;
    }

    /** The agent space object of the run (stored or not); null when none is known yet. */
    public function getAgentSpace(): ?AgentSpace
    {
        return $this->agentSpace;
    }

    /** The agent space when it is stored; null otherwise. A row and a harness call name only a stored space. */
    public function getStoredAgentSpace(): ?AgentSpace
    {
        return $this->agentSpaceId !== null ? $this->agentSpace : null;
    }

    /** Whether the agent space of the run is stored. */
    public function hasStoredAgentSpace(): bool
    {
        return $this->agentSpaceId !== null;
    }

    /** The account the run works for: it is authenticated for the run and billed for its model calls. */
    public function getOwnerAccount(): Account
    {
        return $this->ownerAccount;
    }

    /** The id of the account the run works for. */
    public function getOwnerAccountId(): int
    {
        return (int)$this->ownerAccount->id;
    }

    /** Whether the application put a value under `$key`. */
    public function hasApplicationValue(string $key): bool
    {
        return array_key_exists($key, $this->applicationValuesByKey);
    }

    /** The application's value under `$key`; null when there is none. */
    public function getApplicationValue(string $key): mixed
    {
        return $this->applicationValuesByKey[$key] ?? null;
    }

    /**
     * Every value of the application, by key.
     *
     * @return array<string, mixed>
     */
    public function getApplicationValues(): array
    {
        return $this->applicationValuesByKey;
    }

    /**
     * Records that the agent space of the run is stored now (the hooks stored it before a conversation or a harness
     * call needed it). Recording the same space again changes nothing.
     *
     * @param AgentSpace $storedAgentSpace the stored space
     * @return void
     * @throws InternalErrorException when the space has no id, or the scope holds another stored space already
     */
    public function recordStoredAgentSpace(AgentSpace $storedAgentSpace): void
    {
        if (!isset($storedAgentSpace->id)) {
            throw new InternalErrorException('An agent space without an id is not stored.');
        }
        if ($this->agentSpaceId !== null && $this->agentSpaceId !== (int)$storedAgentSpace->id) {
            throw new InternalErrorException("The run works in the agent space {$this->agentSpaceId} and keeps it.");
        }
        $this->agentSpaceId = (int)$storedAgentSpace->id;
        $this->agentSpace = $storedAgentSpace;
    }
}
