<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The identifying fields a case row starts with, in their stored order: for a tools case `skill`, `case`, `tool`, for
 * a not-applicable row also `flavor` and the scope, for a selection case `skill` and `case`, for a recall case `id`.
 * The row factories of {@see AgentEvalCaseRow} put these fields first and their own keys after them.
 *
 * The scope field carries the name {@see SCOPE_FIELD_NAME}. An application whose rows name the scope with its own key
 * redeclares the constant on its same-path class; the header is built with `newInstance()`
 * ({@see forCase()}, {@see withoutFields()}), which gives that class.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalCaseRowHeader extends ValueObject
{
    /** @var string The key under which a row names the scope of its case */
    public const string SCOPE_FIELD_NAME = 'agentSpaceId';

    /** @var array<string, string|int|float|bool|null> The identifying fields in their order (a flat map of scalars) */
    protected array $identifyingFields = [];

    /** A header without a field yet. */
    public static function withoutFields(): static
    {
        return static::newInstance();
    }

    /** The header of a tools case: `skill`, `case`, `tool`. */
    public static function forCase(string $skillName, string $caseId, string $toolLabel): static
    {
        return static::withoutFields()->addField('skill', $skillName)->addField('case', $caseId)->addField('tool', $toolLabel);
    }

    /** Appends one field (a field the header carries keeps its place and takes the new value). */
    public function addField(string $key, string|int|float|bool|null $value): static
    {
        $this->identifyingFields[$key] = $value;
        return $this;
    }

    /** Appends `flavor`. */
    public function addFlavor(string $flavor): static
    {
        return $this->addField('flavor', $flavor);
    }

    /** Appends the scope under {@see SCOPE_FIELD_NAME}. */
    public function addScope(?int $scopeId): static
    {
        return $this->addField(static::SCOPE_FIELD_NAME, $scopeId);
    }

    /** The value of one field; null when the header does not carry it. */
    public function getValue(string $key): string|int|float|bool|null
    {
        return $this->identifyingFields[$key] ?? null;
    }

    /**
     * The fields in their order: the head of the stored document of a case row ({@see AgentEvalCaseRow::toArray()}).
     *
     * @return array<string, string|int|float|bool|null>
     */
    public function toArray(): array
    {
        return $this->identifyingFields;
    }
}
