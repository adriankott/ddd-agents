<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\RecordedRuns;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The harness's own fields of a recorded run, stored at the top level of the recording: which harness recorded it,
 * the settings a replay must match and what the harness needs to drive the replay (the owner turns, the worker path, a
 * case, models). The field names and values are opaque to the package: a harness writes them, its replay reads them
 * back ({@see AgentRecordedRunRecording::getRunFieldValue()}) and a setting passed again is checked against them
 * ({@see AgentRecordedRunRecording::findReplayRefusal()}).
 *
 * @phpstan-consistent-constructor
 */
class AgentRecordedRunFields extends ValueObject
{
    /** The field every harness writes: its name; a replay of another harness's recording is refused. */
    public const string FIELD_HARNESS = 'harness';

    /** @var array<string, mixed> Field → value (scalars, null, or lists and maps of scalars), in stored order */
    protected array $valuesByField = [];

    /**
     * @param array<string, mixed> $valuesByField field → value, in the order they are stored
     * @return static
     */
    public static function fromValuesByField(array $valuesByField): static
    {
        $agentRecordedRunFields = static::newInstance();
        $agentRecordedRunFields->valuesByField = $valuesByField;
        return $agentRecordedRunFields;
    }

    /**
     * The fields with `$valuesByField` added after the present ones; a field present already keeps its place and gets
     * the new value.
     *
     * @param array<string, mixed> $valuesByField
     * @return static
     */
    public function withValues(array $valuesByField): static
    {
        return static::fromValuesByField(array_merge($this->valuesByField, $valuesByField));
    }

    /**
     * @param string $field
     * @return mixed the value of the field; null when the field is absent
     */
    public function getValue(string $field): mixed
    {
        return $this->valuesByField[$field] ?? null;
    }

    /**
     * The fields as they are stored in the recording, in their order.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->valuesByField;
    }
}
