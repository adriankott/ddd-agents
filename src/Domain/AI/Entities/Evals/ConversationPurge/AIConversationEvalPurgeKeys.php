<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\ConversationPurge;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The ids one batch of the evaluation purge works on, by kind: the conversations of the purged trees and the ids of
 * their dependent rows that other tables point at. The module's kinds are the conversations, their messages and the
 * delegation batches they spawned; an application adds kinds of its own (a kind is any name its steps use, e.g. the
 * name of its table) through {@see setIdsOfKind()}. A step reads the ids of its kind with {@see getIdsOfKind()}; a kind
 * nobody set has no ids.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationEvalPurgeKeys extends ValueObject
{
    /** @var string The conversations of the purged trees */
    public const string KIND_CONVERSATION = 'conversation';

    /** @var string The messages of those conversations */
    public const string KIND_MESSAGE = 'message';

    /** @var string The delegation batches those conversations spawned */
    public const string KIND_DELEGATION_BATCH = 'delegationBatch';

    /**
     * @var array<string, int[]> The ids per kind, each list without duplicates in the order it was set. Read through
     *     {@see getIdsOfKind()}; the map itself is never handed out. It holds no struct: per kind a flat list of row
     *     ids, the shape of an id index (like the case ids per run folder of the evaluation results), not a list of
     *     records.
     */
    protected array $idsByKind = [];

    /**
     * Sets the ids of one kind, replacing what the kind held: cast to int, duplicates left out, the first occurrence's
     * order kept.
     *
     * @param array<int, int|string> $ids
     */
    public function setIdsOfKind(string $keyKind, array $ids): void
    {
        $this->idsByKind[$keyKind] = array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Adds ids to one kind after the ids it holds; an id the kind holds already is not added again.
     *
     * @param array<int, int|string> $ids
     */
    public function addIdsOfKind(string $keyKind, array $ids): void
    {
        $this->setIdsOfKind($keyKind, array_merge($this->idsByKind[$keyKind] ?? [], $ids));
    }

    /** @return int[] the ids a step of this key kind matches; none for a kind nobody set */
    public function getIdsOfKind(string $keyKind): array
    {
        return $this->idsByKind[$keyKind] ?? [];
    }

    public function countIdsOfKind(string $keyKind): int
    {
        return count($this->getIdsOfKind($keyKind));
    }

    /** @return string[] the kinds that were set, in the order they were first set */
    public function getKeyKinds(): array
    {
        return array_keys($this->idsByKind);
    }

    /** @return int[] the conversations of the purged trees */
    public function getConversationIds(): array
    {
        return $this->getIdsOfKind(static::KIND_CONVERSATION);
    }

    /** @return int[] the messages of those conversations */
    public function getMessageIds(): array
    {
        return $this->getIdsOfKind(static::KIND_MESSAGE);
    }

    /** @return int[] the delegation batches those conversations spawned */
    public function getDelegationBatchIds(): array
    {
        return $this->getIdsOfKind(static::KIND_DELEGATION_BATCH);
    }
}
