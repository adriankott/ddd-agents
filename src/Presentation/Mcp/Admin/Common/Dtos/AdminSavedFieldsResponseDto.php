<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\Common\Dtos;

use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The result of every value edit of the admin agent: what was saved, what was not, what already had the value
 *. One DTO for every writing
 * tool, so every write of the agent reads the same.
 *
 * Every text is a constant, pinned verbatim by AdminToolTextsTest: a model acts on the exact sentence, so a change is
 * a reviewed change, and the docblock of a constant says why the sentence exists.
 *
 * Example (two fields saved, one dropped):
 *   Saved order #88 "Spring delivery".
 *   startDate: was 2026-04-01, now 2026-05-01
 *   motto (de): was "Jeder Kilometer zählt", now "Gemeinsam aufs Rad"
 *   slug: NOT saved, order #79 uses it; the stored value stays "spring-2026"
 *   Tell the operator the old and the new value of each line. This result is the new state of these fields: do not read the record again to check it.
 *
 * Example (nothing to save):
 *   No change: endDate is already 2026-10-31.
 */
class AdminSavedFieldsResponseDto extends RestResponseDto implements LlmMarkdownRenderable
{
    /** @var string The first line of a result that saved something: kind, id and name of the record, so the model reports the record it changed and not the one it was asked about by name */
    public const string SAVED_HEADLINE = 'Saved %s #%s "%s".';

    /** @var string The first line of a result that created a record: the id is what every later call needs */
    public const string CREATED_HEADLINE = 'Created %s #%s "%s".';

    /** @var string One saved field. In words, never an arrow: arrows beside values were read inverted (reference application) */
    public const string SAVED_FIELD_LINE = '%s: was %s, now %s';

    /**
     * @var string One field that was dropped while others were saved. Its own line, because refusing the whole call
     * for one bad field cost eleven retries in one run of the reference application, and a dropped field without a
     * line was reported as saved.
     */
    public const string NOT_SAVED_FIELD_LINE = '%s: NOT saved, %s; the stored value stays %s';

    /** @var string Why a field that was passed as an empty text is not saved: an empty text never clears a value, clearing is its own flag */
    public const string REASON_PASSED_EMPTY = 'it was passed empty';

    /**
     * @var string A passed value that equals the stored one. A completed result, not a refusal: a refusal here sent
     * models into retries and invented statements (reference application).
     */
    public const string NO_CHANGE_FIELD_LINE = 'No change: %s is already %s.';

    /** @var string A call that passed no value to save */
    public const string NOTHING_PASSED_LINE = 'No change: no value to save was passed for %s #%s "%s".';

    /**
     * @var string The last line of a result that saved something. Without its second sentence the reference
     * application saw 4.5 re-reads of the record per run: the model checked its own write. Its first sentence makes
     * the answer name the old value too: without it the model reported only the new value in 7 of 11 measured
     * renames (2026-11-19), although the skill asks for the `was …, now …` lines.
     */
    public const string NEW_STATE_NOTE = 'Tell the operator the old and the new value of each line. This result is the new state of these fields: do not read the record again to check it.';

    /** @var string The kind of record, as the result names it, e.g. "order" */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $entityLabel = '';

    /** @var int The id of the record */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public int $entityId = 0;

    /** @var string The name of the record after the save */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $entityName = '';

    /** @var string[] One line per saved field */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public array $savedFieldLines = [];

    /** @var string[] One line per field that was dropped */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public array $notSavedFieldLines = [];

    /** @var string[] One line per passed value that equals the stored one */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public array $noChangeLines = [];

    /** @var string[] What else the operator has to know about the new state, e.g. items that now lie outside the period */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public array $noteLines = [];

    /** @var bool Whether the call created the record (the result then leads with "Created" and shows the record in its note lines) */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public bool $isCreated = false;

    /**
     * @var bool|null False when the call completed and wrote nothing. Loop control (the executor hands it to the agent
     * loop beside the text): the call counts as "ran, saved nothing", never as a completed write.
     */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?bool $applied = null;

    /**
     * @var int|null Loop control, nothing for you to act on: the step floor the agent loop grants this turn (set by a
     * write that starts a longer setup)
     */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?int $turnMinimumSteps = null;

    /**
     * @param string $fieldLabel e.g. "startDate" or "motto (de)"
     * @param string $was The stored value before, as a result shows it
     * @param string $now The stored value after the save
     * @return void
     */
    public function addSavedField(string $fieldLabel, string $was, string $now): void
    {
        $this->savedFieldLines[] = sprintf(static::SAVED_FIELD_LINE, $fieldLabel, $was, $now);
    }

    /**
     * @param string $fieldLabel
     * @param string $reason Why the field was dropped, without a full stop
     * @param string $storedValue The value that stays stored
     * @return void
     */
    public function addNotSavedField(string $fieldLabel, string $reason, string $storedValue): void
    {
        $this->notSavedFieldLines[] = sprintf(static::NOT_SAVED_FIELD_LINE, $fieldLabel, $reason, $storedValue);
    }

    /**
     * @param string $fieldLabel
     * @param string $storedValue
     * @return void
     */
    public function addUnchangedField(string $fieldLabel, string $storedValue): void
    {
        $this->noChangeLines[] = sprintf(static::NO_CHANGE_FIELD_LINE, $fieldLabel, $storedValue);
    }

    /**
     * @return string
     */
    public function toLlmMarkdown(): string
    {
        $lines = [];
        if ($this->isCreated || $this->savedFieldLines !== []) {
            $lines[] = sprintf($this->isCreated ? static::CREATED_HEADLINE : static::SAVED_HEADLINE, $this->entityLabel, $this->entityId, $this->entityName);
            array_push($lines, ...$this->savedFieldLines, ...$this->notSavedFieldLines, ...$this->noChangeLines, ...$this->noteLines);
            $lines[] = static::NEW_STATE_NOTE;
            return implode("\n", $lines);
        }
        array_push($lines, ...$this->noChangeLines, ...$this->notSavedFieldLines, ...$this->noteLines);
        if ($lines === []) {
            $lines[] = sprintf(static::NOTHING_PASSED_LINE, $this->entityLabel, $this->entityId, $this->entityName);
        }
        return implode("\n", $lines);
    }
}
