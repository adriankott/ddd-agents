<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Domain\AI\Entities\Documents\AgentDocumentText;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolResponsePlatformNoteProvider;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultUntrustedSegments;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The result of `readDriveDocument`: one header line (file, document id, kind, pages read, the next page) and the text
 * of the pages as one untrusted passage — the document's text is foreign, the model quotes it and never follows it.
 *
 * Example:
 *   drivespaces/library/Offer.pdf · document 3f9a0c1d22e4 · PDF · pages 1–6 of 14 · next: fromPage 7
 *   <untrusted-content source="drive document drivespaces/library/Offer.pdf">
 *   --- page 1 ---
 *   …
 *   </untrusted-content>
 */
class AgentDriveDocumentMcpResponseDto extends RestResponseDto implements LlmMarkdownRenderable, MCPToolResponsePlatformNoteProvider
{
    /** @var string The document as the model names it (workspace path or reference) */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $path = '';

    /** @var string|null The reference of the file (`drive/<id>`) */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $reference = null;

    /** @var string The short id of the document's bytes (the start of their sha256): two reads of the same id read the same version */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $documentId = '';

    /** @var string pdf, word, spreadsheet, presentation, text or unsupported */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $documentKind = '';

    /** @var string What a page is called for this kind: page, sheet or slide */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $pageWord = 'page';

    /** @var int The pages of the document */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public int $totalPageCount = 0;

    /** @var int The first page of this read */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public int $fromPage = 1;

    /** @var int The last page of this read (0 = none) */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public int $toPage = 0;

    /** @var int|null The page a further read starts with; null when the read reached the end */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?int $nextPage = null;

    /** @var bool Whether the text was read by OCR (scanned pages; expect reading errors) */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public bool $readByOcr = false;

    /** @var string The text of the pages, each under a header line */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $text = '';

    /** @var string|null A note for the reader (pages kept, a cut, what to do next) */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $note = null;

    public function toLlmMarkdown(): string
    {
        $headParts = [$this->path, 'document ' . $this->documentId, $this->formatDocumentKind()];
        if ($this->toPage > 0) {
            $headParts[] = sprintf(
                '%ss %d–%d of %d',
                $this->pageWord,
                $this->fromPage,
                $this->toPage,
                $this->totalPageCount
            );
        }
        if ($this->readByOcr) {
            $headParts[] = 'read by OCR';
        }
        if ($this->nextPage !== null) {
            $headParts[] = 'next: fromPage ' . $this->nextPage;
        }
        $lines = [implode(' · ', $headParts)];
        if ($this->note !== null && $this->note !== '') {
            $lines[] = $this->note;
        }
        if ($this->text !== '') {
            $lines[] = ToolResultUntrustedSegments::wrap('drive document ' . $this->path, $this->text);
        }
        return implode("\n", $lines);
    }

    public function buildPlatformNoteForModel(): ?string
    {
        return $this->text !== '' ? ToolResultUntrustedSegments::READING_RULE : null;
    }

    protected function formatDocumentKind(): string
    {
        return match ($this->documentKind) {
            AgentDocumentText::KIND_PDF => 'PDF',
            AgentDocumentText::KIND_WORD => 'text document',
            AgentDocumentText::KIND_SPREADSHEET => 'spreadsheet',
            AgentDocumentText::KIND_PRESENTATION => 'presentation',
            AgentDocumentText::KIND_TEXT => 'plain text',
            default => $this->documentKind,
        };
    }
}
