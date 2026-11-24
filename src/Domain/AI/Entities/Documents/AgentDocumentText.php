<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Documents;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The extracted text of one document, page by page (a PDF page, a sheet, a slide; a Word or text file is one page per
 * about {@see FLOW_PAGE_CHARACTERS} characters). Kept by the document's sha256 in the cache, so a further read of the
 * same bytes is not extracted again. The text is foreign: it reaches the model as an untrusted tool result.
 *
 * @phpstan-consistent-constructor
 */
class AgentDocumentText extends ValueObject
{
    public const string KIND_PDF = 'pdf';
    public const string KIND_WORD = 'word';
    public const string KIND_SPREADSHEET = 'spreadsheet';
    public const string KIND_PRESENTATION = 'presentation';
    public const string KIND_TEXT = 'text';
    public const string KIND_UNSUPPORTED = 'unsupported';

    /** @var int The characters of one page of a document without pages of its own (Word, OpenDocument text, plain text) */
    public const int FLOW_PAGE_CHARACTERS = 3_000;

    /** @var int The length of the short document id */
    public const int SHORT_DOCUMENT_ID_LENGTH = 12;

    /** @var string The sha256 of the document's bytes */
    public string $sha256 = '';

    /** @var string The kind ({@see KIND_PDF} …) */
    public string $documentKind = self::KIND_UNSUPPORTED;

    /** @var string What a page is called for this kind: page, sheet or slide */
    public string $pageWord = 'page';

    /** @var string[] The text of each page, in order (page 1 first) */
    public array $pageTexts = [];

    /** @var string[] The name of each page where the document names them (sheet names), else empty */
    public array $pageNames = [];

    /** @var int The pages of the document (more than {@see $pageTexts} when it was cut at the page cap) */
    public int $totalPageCount = 0;

    /** @var bool Whether the text was read by OCR */
    public bool $readByOcr = false;

    /** @var string|null Why no text could be read (an unsupported type, a damaged file); null when text was read */
    public ?string $failureReason = null;

    public function getShortDocumentId(): string
    {
        return substr($this->sha256, 0, self::SHORT_DOCUMENT_ID_LENGTH);
    }

    public function countExtractedPages(): int
    {
        return count($this->pageTexts);
    }

    public function hasText(): bool
    {
        foreach ($this->pageTexts as $pageText) {
            if (trim($pageText) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * The text of the pages from `$fromPage` on for the model: a header line per page, stopping before the page that
     * would pass `$maxCharacters` (the first page is always given, cut at the limit). Returns the text and sets the next
     * unread page in {@see AgentDocumentPagesRead}.
     */
    public function renderPagesForModel(int $fromPage, ?int $toPage, int $maxCharacters): AgentDocumentPagesRead
    {
        $agentDocumentPagesRead = new AgentDocumentPagesRead();
        $lastPage = min($this->countExtractedPages(), $toPage ?? PHP_INT_MAX);
        $fromPage = max(1, $fromPage);
        $agentDocumentPagesRead->fromPage = $fromPage;
        $renderedParts = [];
        $renderedCharacters = 0;
        for ($pageNumber = $fromPage; $pageNumber <= $lastPage; $pageNumber++) {
            $pageName = ($this->pageNames[$pageNumber - 1] ?? '') !== '' ? ' "' . $this->pageNames[$pageNumber - 1] . '"' : '';
            $pagePart = "--- {$this->pageWord} {$pageNumber}{$pageName} ---\n" . trim($this->pageTexts[$pageNumber - 1]);
            if ($renderedParts !== [] && $renderedCharacters + mb_strlen($pagePart) > $maxCharacters) {
                $agentDocumentPagesRead->nextPage = $pageNumber;
                break;
            }
            if (mb_strlen($pagePart) > $maxCharacters) {
                $pagePart = mb_substr($pagePart, 0, $maxCharacters) . "\n[… the rest of this {$this->pageWord} is cut]";
            }
            $renderedParts[] = $pagePart;
            $renderedCharacters += mb_strlen($pagePart);
            $agentDocumentPagesRead->toPage = $pageNumber;
        }
        if ($agentDocumentPagesRead->nextPage === null && $agentDocumentPagesRead->toPage < $this->countExtractedPages() && $toPage === null) {
            $agentDocumentPagesRead->nextPage = $agentDocumentPagesRead->toPage + 1;
        }
        $agentDocumentPagesRead->text = implode("\n\n", $renderedParts);
        return $agentDocumentPagesRead;
    }
}
