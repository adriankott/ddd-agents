<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Memories\Curation;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One operation the memory curation's model proposes for one candidate memory: the extraction proposes ADD for every
 * candidate, the reconciliation decides ADD, UPDATE, INVALIDATE, NOOP or CONFLICT against the stored neighbours.
 */
class AgentMemoryOperation extends ValueObject
{
    /** A new memory. */
    public const string OPERATION_ADD = 'ADD';

    /** The candidate enriches a neighbour: the neighbour is closed, the candidate replaces it. */
    public const string OPERATION_UPDATE = 'UPDATE';

    /** The candidate replaces a neighbour whose value is no longer true. */
    public const string OPERATION_INVALIDATE = 'INVALIDATE';

    /** The candidate is already known: nothing is stored. */
    public const string OPERATION_NOOP = 'NOOP';

    /** The candidate contradicts a neighbour and nothing says which is right: the owner is asked, nothing is stored. */
    public const string OPERATION_CONFLICT = 'CONFLICT';

    /** @var string|null One of the OPERATION_* constants */
    public ?string $operation = null;

    /** @var string|null The memory type: DIRECTIVE, FACT or SOLUTION */
    public ?string $memoryType = null;

    /** @var string|null USER (about the owner as a person) or BUSINESS (about the business) */
    public ?string $subject = null;

    /** @var string|null The memory text */
    public ?string $content = null;

    /** @var string|null Who asserted it: USER_STATED, AGENT_INFERRED, … */
    public ?string $source = null;

    /** @var int|null The index of the neighbour the operation targets (the model writes `REF_<n>`) */
    public ?int $targetMemoryRef = null;

    /** @var string|null The first words of the targeted neighbour, as the model copied them: checked against the target */
    public ?string $targetEcho = null;

    /** @var string|null When the value became true: `YYYY`, `YYYY-MM` or `YYYY-MM-DD` */
    public ?string $effectiveFrom = null;

    /** @var string|null The model's short reason */
    public ?string $reason = null;

    /** @var bool The candidate is the owner's settled answer to a contradiction (routed to the resolution prompt) */
    public bool $resolvesConflict = false;

    /** @var int|null The id of the conversation message the candidate was said in */
    public ?int $sourceMessageId = null;

    /**
     * Whether the operation changes the store (anything but NOOP and an empty operation).
     *
     * @return bool
     */
    public function changesStore(): bool
    {
        return $this->operation !== null && $this->operation !== self::OPERATION_NOOP;
    }

    /**
     * Whether the operation is a CONFLICT the owner has to clarify.
     *
     * @return bool
     */
    public function requiresClarification(): bool
    {
        return $this->operation === self::OPERATION_CONFLICT;
    }

    /**
     * Whether the operation carries a content to store.
     *
     * @return bool
     */
    public function hasContent(): bool
    {
        return $this->content !== null && $this->content !== '';
    }

    /**
     * One operation from an entry of the model's decoded JSON: strings trimmed, empty strings as null, the operation,
     * type, subject and source upper case, `REF_<n>` read as `<n>`, a positive numeric source message id kept.
     *
     * @param array $operationEntry One decoded JSON object of the model's `operations` list
     * @return static
     */
    public static function fromDecodedEntry(array $operationEntry): static
    {
        $agentMemoryOperation = new static();
        $agentMemoryOperation->operation = static::asUpperNonEmptyStringOrNull($operationEntry['operation'] ?? null);
        $agentMemoryOperation->memoryType = static::asUpperNonEmptyStringOrNull($operationEntry['type'] ?? null);
        $agentMemoryOperation->subject = static::asUpperNonEmptyStringOrNull($operationEntry['subject'] ?? null);
        $agentMemoryOperation->content = static::asNonEmptyStringOrNull($operationEntry['content'] ?? null);
        $agentMemoryOperation->source = static::asUpperNonEmptyStringOrNull($operationEntry['source'] ?? null);
        $agentMemoryOperation->targetMemoryRef = static::parseTargetMemoryRef($operationEntry['targetMemoryRef'] ?? null);
        $agentMemoryOperation->targetEcho = static::asNonEmptyStringOrNull($operationEntry['targetEcho'] ?? null);
        $agentMemoryOperation->effectiveFrom = static::asNonEmptyStringOrNull($operationEntry['effectiveFrom'] ?? null);
        $agentMemoryOperation->reason = static::asNonEmptyStringOrNull($operationEntry['reason'] ?? null);
        $agentMemoryOperation->resolvesConflict = ($operationEntry['resolvesConflict'] ?? false) === true;
        $decodedSourceMessageId = $operationEntry['sourceMessageId'] ?? null;
        $agentMemoryOperation->sourceMessageId = is_numeric($decodedSourceMessageId) && (int)$decodedSourceMessageId > 0
            ? (int)$decodedSourceMessageId
            : null;
        return $agentMemoryOperation;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)spl_object_id($this));
    }

    protected static function asNonEmptyStringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    protected static function asUpperNonEmptyStringOrNull(mixed $value): ?string
    {
        $trimmed = static::asNonEmptyStringOrNull($value);
        return $trimmed === null ? null : strtoupper($trimmed);
    }

    protected static function parseTargetMemoryRef(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\s*REF_(\d+)\s*$/i', $value, $matches) === 1) {
            return (int)$matches[1];
        }
        return is_numeric($value) ? (int)$value : null;
    }
}
