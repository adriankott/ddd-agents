<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\Common\Dtos;

use DDD\Domain\AI\Entities\MCPPackages\LlmRendering\LlmLineRenderable;
use DDD\Domain\AI\Entities\MCPPackages\LlmRendering\LlmTextRendering;
use DDD\Presentation\Mcp\Admin\Common\AdminToolResultLines;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolResponsePlatformNoteProvider;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultUntrustedSegments;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The result of every find and list tool of the admin agent: the window first, then one line per record as the record
 * renders itself (LlmLineRenderable), then what the lines refer to and how to narrow a long list. The DTO composes; it
 * formats no field of a record.
 *
 * Example (subjectLine set by a list that belongs to one record, e.g. the orders of a customer):
 *   Customer #88 "Example Ltd"
 *   Rows 1–2 of 2, newest first. End of the list.
 *   #141 Spring delivery · customer #88 · 2026-03-07 · SHIPPED
 *   #50 Winter delivery · customer #88 · 2025-12-08 · SHIPPED
 *   Customers: #88 Example Ltd
 */
class AdminRecordListResponseDto extends RestResponseDto implements LlmMarkdownRenderable, MCPToolResponsePlatformNoteProvider
{
    /** @var string|null The record the list belongs to, as the first line (`Customer #88 "Example Ltd"`): a reader that sees only the head of the result still knows whose list it is */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $subjectLine = null;

    /** @var int[] The ids of the records of this page, in order */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public array $ids = [];

    /** @var string[] One line per record of this page, in order */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public array $lines = [];

    /** @var int How many records match over every page */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public int $totalCount = 0;

    /** @var int Rows before this page */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public int $skip = 0;

    /** @var string The order of the rows, e.g. "newest first" */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $orderDescription = '';

    /** @var string The answer when nothing matches, e.g. "No orders match." */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $emptyText = '';

    /** @var string[] Lines after the rows that say what the rows refer to, e.g. the names of the customers */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public array $legendLines = [];

    /** @var string|null The arguments that narrow the list; shown when more records match than one page can hold */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $narrowingHint = null;

    /** @var string|null Set when the lines carry text written by users: what that text is; the lines are then marked untrusted */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $untrustedSource = null;

    /** @var bool Whether each line is led by its position in the whole list (`3. …`), for a ranking */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public bool $showsPosition = false;

    /** @var bool Whether the page ended early because its rows reached the size of one result */
    protected bool $isFull = false;

    /** @var int The characters the added rows take */
    protected int $rowsCharacters = 0;

    /**
     * Names the record the list belongs to (the first line of the result, also of an empty one).
     *
     * @param string $entityLabel e.g. "Order"
     * @param int $id
     * @param string|null $name
     * @return void
     */
    public function setSubject(string $entityLabel, int $id, ?string $name): void
    {
        $this->subjectLine = $entityLabel . ' #' . $id . ($name !== null && $name !== '' ? ' ' . LlmTextRendering::displayValue($name) : '');
    }

    /**
     * Adds one record with the line it renders for itself. A record whose line no longer fits the size of one result
     * (AdminToolResultLines::MAX_ROWS_CHARACTERS) is not added, and no record after it: the page ends there and the
     * window line names the next skip.
     *
     * @param int $id
     * @param LlmLineRenderable $record
     * @return bool Whether the record was added; false ends the page
     */
    public function addRecord(int $id, LlmLineRenderable $record): bool
    {
        return $this->addRecordLine($id, $record->toLlmLine());
    }

    /**
     * addRecord() for a line the record rendered in a variant of its own (e.g. a line with the record's items).
     *
     * @param int $id
     * @param string $recordLine The record's own line, starting with `#<id>`
     * @return bool Whether the record was added; false ends the page
     */
    public function addRecordLine(int $id, string $recordLine): bool
    {
        if ($this->isFull) {
            return false;
        }
        $line = ($this->showsPosition ? ($this->skip + count($this->lines) + 1) . '. ' : '') . $recordLine;
        if ($this->lines !== [] && $this->rowsCharacters + mb_strlen($line) + 1 > AdminToolResultLines::MAX_ROWS_CHARACTERS) {
            $this->isFull = true;
            return false;
        }
        $this->ids[] = $id;
        $this->lines[] = $line;
        $this->rowsCharacters += mb_strlen($line) + 1;
        return true;
    }

    /**
     * @return string
     */
    public function toLlmMarkdown(): string
    {
        $subjectLines = $this->subjectLine !== null ? [$this->subjectLine] : [];
        if ($this->totalCount === 0) {
            return implode("\n", array_merge($subjectLines, [$this->emptyText]));
        }
        $resultLines = [...$subjectLines, AdminToolResultLines::windowLine($this->skip, count($this->lines), $this->totalCount, $this->orderDescription)];
        if ($this->lines !== []) {
            $resultLines[] = $this->untrustedSource !== null
                ? AdminToolResultLines::wrapUntrustedLines($this->untrustedSource, $this->lines)
                : implode("\n", $this->lines);
        }
        foreach ($this->legendLines as $legendLine) {
            $resultLines[] = $legendLine;
        }
        if ($this->narrowingHint !== null && $this->totalCount > AdminToolResultLines::MAX_PAGE_SIZE) {
            $resultLines[] = $this->narrowingHint;
        }
        return implode("\n", $resultLines);
    }

    /**
     * @return string|null The reading rule for untrusted passages, when this result carries one
     */
    public function buildPlatformNoteForModel(): ?string
    {
        return $this->untrustedSource !== null && $this->lines !== [] ? ToolResultUntrustedSegments::READING_RULE : null;
    }
}
