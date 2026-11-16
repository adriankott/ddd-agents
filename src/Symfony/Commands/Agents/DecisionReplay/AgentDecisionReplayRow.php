<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\DecisionReplay;

use DDD\Domain\Base\Entities\ValueObject;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One replayed decision: its key (`<conversation id>:<index of the call>` for a stored tool call, the case id of a
 * probe) and its named field values, in the order they were set. {@see toArray()} is the row as the result file
 * stores it; {@see buildTableCells()} is the row as the console table shows it — an application's row with its own
 * columns overrides it.
 *
 * A plain object of one command run; no container service.
 *
 * @phpstan-consistent-constructor
 */
#[Exclude]
class AgentDecisionReplayRow extends ValueObject
{
    /** @var string The field that holds the key of the row */
    public const string FIELD_KEY = 'key';

    /** @var array<string, string|int|float|bool|null> The field values by field name, in the order they were set */
    protected array $fieldValuesByName = [];

    /**
     * A row whose first field {@see FIELD_KEY} is `$rowKey`.
     *
     * @param string $rowKey
     * @return static
     */
    public static function forKey(string $rowKey): static
    {
        $agentDecisionReplayRow = static::newInstance();
        $agentDecisionReplayRow->fieldValuesByName[self::FIELD_KEY] = $rowKey;
        return $agentDecisionReplayRow;
    }

    /**
     * Sets the field `$fieldName` (a field set before keeps its position).
     *
     * @param string $fieldName
     * @param string|int|float|bool|null $fieldValue
     * @return static
     */
    public function setFieldValue(string $fieldName, string|int|float|bool|null $fieldValue): static
    {
        $this->fieldValuesByName[$fieldName] = $fieldValue;
        return $this;
    }

    /** The value of the field `$fieldName`; null when the row has none. */
    public function getFieldValue(string $fieldName): string|int|float|bool|null
    {
        return $this->fieldValuesByName[$fieldName] ?? null;
    }

    /** Whether the row carries the field `$fieldName` (a null value counts as carried). */
    public function hasField(string $fieldName): bool
    {
        return array_key_exists($fieldName, $this->fieldValuesByName);
    }

    /** The key of the row. */
    public function getRowKey(): string
    {
        return (string)($this->fieldValuesByName[self::FIELD_KEY] ?? '');
    }

    /**
     * The names of the row's fields, in order.
     *
     * @return string[]
     */
    public function getFieldNames(): array
    {
        return array_keys($this->fieldValuesByName);
    }

    /**
     * The stored document of one row of the result file: every field by name, in the order the fields were set.
     *
     * @return array<string, string|int|float|bool|null>
     */
    public function toArray(): array
    {
        return $this->fieldValuesByName;
    }

    /**
     * The cells of the row in the console table. The default: every field value in order — a number as written, true
     * and false as `yes` / `no`, null as an empty cell.
     *
     * @return string[]
     */
    public function buildTableCells(): array
    {
        $tableCells = [];
        foreach ($this->fieldValuesByName as $fieldValue) {
            $tableCells[] = match (true) {
                $fieldValue === null => '',
                is_bool($fieldValue) => $fieldValue ? 'yes' : 'no',
                default => (string)$fieldValue,
            };
        }
        return $tableCells;
    }
}
