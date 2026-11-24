<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Documents;

use DDD\Domain\AI\Entities\Documents\AgentDocumentSettings;
use DDD\Domain\AI\Entities\Documents\AgentDocumentText;
use DDD\Domain\AI\Entities\Drives\AgentDriveFileContent;
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\Service;
use DDD\Infrastructure\Untrusted\ArchiveInspection;
use DDD\Infrastructure\Untrusted\ArchiveInspector;
use DDD\Infrastructure\Untrusted\IsolatedProcess;
use DDD\Infrastructure\Untrusted\SafeXml;
use DDD\Infrastructure\Untrusted\UntrustedContentException;
use DOMDocument;
use DOMElement;
use DOMNode;
use Throwable;

/**
 * Reads the text of a document page by page — PDF (poppler `pdftotext`, OCR by `tesseract` for a PDF without a text
 * layer), Word, Excel and PowerPoint (OOXML), OpenDocument, plain text — and renders single PDF pages as images. The
 * bytes are foreign: the kind is taken from the bytes (never from the name or the type a client claimed), a PDF is
 * handed to poppler as an {@see IsolatedProcess}, an Office file is a ZIP checked by {@see ArchiveInspector} whose XML
 * is parsed by {@see SafeXml}; nothing is executed, macros are never read. The text is kept by the sha256 of the
 * bytes in the cache ({@see AgentDocumentSettings::$cacheGroup}).
 */
class AgentDocumentTextExtractionService extends Service
{
    public const string CONFIG_KEY = 'AI.documents';

    /** @var int Seconds one poppler or tesseract process may run */
    public const int PROCESS_TIMEOUT_SECONDS = 60;

    /** @var int PDF pages the OCR fallback reads at most */
    public const int MAX_OCR_PAGES = 20;

    /** @var int Fewer characters than this in a PDF's text layer: read by OCR */
    public const int MIN_TEXT_LAYER_CHARACTERS = 50;

    /** @var int The largest XML part of an Office or OpenDocument file that is read */
    public const int MAX_XML_PART_BYTES = 52_428_800;

    /** @var int The characters one page keeps at most (a huge sheet is cut) */
    public const int MAX_PAGE_CHARACTERS = 200_000;

    /** @var int The columns of a sheet row that are read at most */
    public const int MAX_SHEET_COLUMNS = 200;

    /** @var int How often a repeated OpenDocument row or cell is written at most */
    public const int MAX_ODF_REPEAT = 100;

    /** @var string[] File extensions read as plain text when the bytes are valid UTF-8 */
    public const array TEXT_FILE_EXTENSIONS = ['txt', 'text', 'md', 'markdown', 'csv', 'tsv', 'json', 'xml', 'html', 'htm', 'log', 'yaml', 'yml', 'ini', 'rtf', 'tex', 'srt', 'vtt'];

    protected const string CACHE_KEY_PREFIX = 'agentDocumentText.';

    protected const string OOXML_RELATIONSHIPS_NAMESPACE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    public function getSettings(): AgentDocumentSettings
    {
        return AgentDocumentSettings::fromConfigNode($this->readConfigNode(self::CONFIG_KEY));
    }

    /**
     * The text of a document, page by page. A document that cannot be read (an unsupported type, a damaged file, a
     * missing poppler) comes back with {@see AgentDocumentText::$failureReason} set, never as an exception.
     */
    public function extractDocumentText(AgentDriveFileContent $agentDriveFileContent, ?AgentDocumentSettings $settings = null): AgentDocumentText
    {
        $settings ??= $this->getSettings();
        $cacheKey = self::CACHE_KEY_PREFIX . $agentDriveFileContent->sha256 . '.' . $settings->maxExtractedPages . ($settings->ocrEnabled ? 'o' : '');
        $cache = $this->resolveCacheGroupSafely($settings->cacheGroup);
        if ($cache !== null) {
            try {
                $cachedDocumentText = $cache->get($cacheKey);
                if ($cachedDocumentText instanceof AgentDocumentText) {
                    return $cachedDocumentText;
                }
            } catch (Throwable) {
                // a cache outage only costs the extraction
            }
        }
        $agentDocumentText = $this->extractDocumentTextUncached($agentDriveFileContent, $settings);
        if ($cache !== null && ($agentDocumentText->hasText() || $agentDocumentText->documentKind === AgentDocumentText::KIND_UNSUPPORTED)) {
            try {
                $cache->set($cacheKey, $agentDocumentText, $settings->cacheTtlSeconds);
            } catch (Throwable) {
                // not kept: the next read extracts again
            }
        }
        return $agentDocumentText;
    }

    /** Whether the bytes are a PDF (by their magic bytes). */
    public function isPdf(string $bytes): bool
    {
        return str_starts_with(ltrim(substr($bytes, 0, 1024)), '%PDF-');
    }

    /**
     * One page of a PDF as a PNG (poppler `pdftoppm`); null when the page does not exist or cannot be rendered.
     *
     * @throws InternalErrorException poppler is not installed
     */
    public function renderPdfPageImage(string $pdfBytes, int $pageNumber, int $resolutionDpi): ?string
    {
        $workingDirectory = IsolatedProcess::createWorkingDirectory('agent-document-page');
        try {
            $pdfPath = $workingDirectory . '/input.pdf';
            file_put_contents($pdfPath, $pdfBytes);
            $renderProcess = IsolatedProcess::create(
                'pdftoppm',
                ['-png', '-singlefile', '-r', (string)$resolutionDpi, '-f', (string)$pageNumber, '-l', (string)$pageNumber, $pdfPath, $workingDirectory . '/page'],
                $workingDirectory,
                static::PROCESS_TIMEOUT_SECONDS
            );
            $renderProcess->run();
            $pageImagePath = $workingDirectory . '/page.png';
            if (!$renderProcess->isSuccessful() || !is_file($pageImagePath)) {
                return null;
            }
            $pageImageBytes = file_get_contents($pageImagePath);
            return is_string($pageImageBytes) && $pageImageBytes !== '' ? $pageImageBytes : null;
        } finally {
            IsolatedProcess::removeWorkingDirectory($workingDirectory);
        }
    }

    protected function extractDocumentTextUncached(AgentDriveFileContent $agentDriveFileContent, AgentDocumentSettings $settings): AgentDocumentText
    {
        $agentDocumentText = new AgentDocumentText();
        $agentDocumentText->sha256 = $agentDriveFileContent->sha256;
        $bytes = $agentDriveFileContent->getBytes();
        try {
            if ($this->isPdf($bytes)) {
                $this->extractPdfText($bytes, $agentDocumentText, $settings);
            } elseif (str_starts_with($bytes, "PK\x03\x04")) {
                $this->extractZipDocumentText($bytes, $agentDocumentText);
            } elseif (str_starts_with($bytes, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
                $agentDocumentText->failureReason = 'This is a legacy Office file (.doc, .xls or .ppt); only the current formats (.docx, .xlsx, .pptx) and OpenDocument are read. Ask the owner for the file in a current format or as a PDF.';
            } elseif ($this->isPlainText($bytes, $agentDriveFileContent)) {
                $agentDocumentText->documentKind = AgentDocumentText::KIND_TEXT;
                $this->setFlowPages($agentDocumentText, $this->splitTextIntoParagraphs($bytes));
            } else {
                $agentDocumentText->failureReason = 'The file is no document whose text can be read (PDF, Word, Excel, PowerPoint, OpenDocument or plain text).';
            }
        } catch (UntrustedContentException $untrustedContentException) {
            $agentDocumentText->failureReason = 'The file could not be read: ' . $untrustedContentException->getMessage();
        } catch (InternalErrorException $internalErrorException) {
            $agentDocumentText->failureReason = 'The document reader is not available on this host (' . $internalErrorException->getMessage() . ').';
        }
        $this->limitPages($agentDocumentText, $settings);
        if ($agentDocumentText->failureReason === null && !$agentDocumentText->hasText()) {
            $agentDocumentText->failureReason = $agentDocumentText->documentKind === AgentDocumentText::KIND_PDF
                ? 'The PDF has no readable text' . ($settings->ocrEnabled ? ', not even by OCR' : ' (OCR is switched off)') . '. Its pages can be looked at as images.'
                : 'The document holds no text.';
        }
        return $agentDocumentText;
    }

    /** @throws InternalErrorException poppler is not installed */
    protected function extractPdfText(string $pdfBytes, AgentDocumentText $agentDocumentText, AgentDocumentSettings $settings): void
    {
        $agentDocumentText->documentKind = AgentDocumentText::KIND_PDF;
        $agentDocumentText->pageWord = 'page';
        $workingDirectory = IsolatedProcess::createWorkingDirectory('agent-document-text');
        try {
            $pdfPath = $workingDirectory . '/input.pdf';
            file_put_contents($pdfPath, $pdfBytes);
            $agentDocumentText->totalPageCount = $this->readPdfPageCount($pdfPath, $workingDirectory);
            $textProcess = IsolatedProcess::create(
                'pdftotext',
                ['-enc', 'UTF-8', '-l', (string)$settings->maxExtractedPages, $pdfPath, '-'],
                $workingDirectory,
                static::PROCESS_TIMEOUT_SECONDS
            );
            $textProcess->run();
            if (!$textProcess->isSuccessful()) {
                throw new UntrustedContentException('the PDF is damaged or encrypted');
            }
            $pageTexts = explode("\f", $textProcess->getOutput());
            if (count($pageTexts) > 1 && trim((string)end($pageTexts)) === '') {
                array_pop($pageTexts);
            }
            foreach ($pageTexts as $pageIndex => $pageText) {
                $pageTexts[$pageIndex] = $this->normalizePdfPageText($pageText);
            }
            $agentDocumentText->pageTexts = $pageTexts;
            if (mb_strlen(trim(implode('', $pageTexts))) < static::MIN_TEXT_LAYER_CHARACTERS && $settings->ocrEnabled) {
                $ocrPageTexts = $this->readPdfPagesByOcr($pdfPath, $workingDirectory, min(static::MAX_OCR_PAGES, $settings->maxExtractedPages));
                if (mb_strlen(trim(implode('', $ocrPageTexts))) > mb_strlen(trim(implode('', $pageTexts)))) {
                    $agentDocumentText->pageTexts = $ocrPageTexts;
                    $agentDocumentText->readByOcr = true;
                }
            }
            $agentDocumentText->totalPageCount = max($agentDocumentText->totalPageCount, count($agentDocumentText->pageTexts));
        } finally {
            IsolatedProcess::removeWorkingDirectory($workingDirectory);
        }
    }

    /** The page count of a PDF (poppler `pdfinfo`); 0 when it cannot be read. */
    protected function readPdfPageCount(string $pdfPath, string $workingDirectory): int
    {
        try {
            $infoProcess = IsolatedProcess::create('pdfinfo', [$pdfPath], $workingDirectory, static::PROCESS_TIMEOUT_SECONDS);
            $infoProcess->run();
        } catch (InternalErrorException) {
            return 0;
        }
        if ($infoProcess->isSuccessful() && preg_match('/^Pages:\s+(\d+)/m', $infoProcess->getOutput(), $pageCountMatch) === 1) {
            return (int)$pageCountMatch[1];
        }
        return 0;
    }

    /**
     * @return string[] the OCR text of each page
     * @throws InternalErrorException poppler or tesseract is not installed
     */
    protected function readPdfPagesByOcr(string $pdfPath, string $workingDirectory, int $maxPages): array
    {
        $renderProcess = IsolatedProcess::create(
            'pdftoppm',
            ['-png', '-r', '200', '-f', '1', '-l', (string)$maxPages, $pdfPath, $workingDirectory . '/ocr'],
            $workingDirectory,
            static::PROCESS_TIMEOUT_SECONDS
        );
        $renderProcess->run();
        $pageImagePaths = glob($workingDirectory . '/ocr-*.png') ?: [];
        natsort($pageImagePaths);
        $ocrPageTexts = [];
        foreach ($pageImagePaths as $pageImagePath) {
            $ocrProcess = IsolatedProcess::create('tesseract', [$pageImagePath, 'stdout'], $workingDirectory, static::PROCESS_TIMEOUT_SECONDS);
            $ocrProcess->run();
            $ocrPageTexts[] = $ocrProcess->isSuccessful() ? $this->normalizePdfPageText($ocrProcess->getOutput()) : '';
        }
        return $ocrPageTexts;
    }

    protected function normalizePdfPageText(string $pageText): string
    {
        $pageText = (string)preg_replace('/\.{4,}/', ' … ', $pageText);
        $pageText = (string)preg_replace("/[ \t]+\n/", "\n", $pageText);
        return (string)preg_replace("/\n{3,}/", "\n\n", $pageText);
    }

    /** An OOXML (Word, Excel, PowerPoint) or OpenDocument file: a ZIP whose XML parts are read as text. */
    protected function extractZipDocumentText(string $zipBytes, AgentDocumentText $agentDocumentText): void
    {
        $workingDirectory = IsolatedProcess::createWorkingDirectory('agent-document-zip');
        try {
            $zipPath = $workingDirectory . '/input.zip';
            file_put_contents($zipPath, $zipBytes);
            $archiveInspection = ArchiveInspector::inspectZip($zipPath);
            if ($archiveInspection->hasEntry('word/document.xml')) {
                $this->extractWordText($zipPath, $agentDocumentText);
            } elseif ($archiveInspection->hasEntry('xl/workbook.xml')) {
                $this->extractSpreadsheetText($zipPath, $archiveInspection, $agentDocumentText);
            } elseif ($archiveInspection->hasEntry('ppt/presentation.xml')) {
                $this->extractPresentationText($zipPath, $agentDocumentText);
            } elseif ($archiveInspection->hasEntry('mimetype') && $archiveInspection->hasEntry('content.xml')) {
                $this->extractOpenDocumentText($zipPath, $agentDocumentText);
            } else {
                $agentDocumentText->failureReason = 'The file is a ZIP archive, not an Office or OpenDocument file; its entries are not read.';
            }
        } finally {
            IsolatedProcess::removeWorkingDirectory($workingDirectory);
        }
    }

    protected function readXmlPart(string $zipPath, string $entryName): ?DOMDocument
    {
        $xmlBytes = ArchiveInspector::readEntry($zipPath, $entryName, static::MAX_XML_PART_BYTES);
        return $xmlBytes === null ? null : SafeXml::loadXml($xmlBytes, static::MAX_XML_PART_BYTES);
    }

    protected function extractWordText(string $zipPath, AgentDocumentText $agentDocumentText): void
    {
        $agentDocumentText->documentKind = AgentDocumentText::KIND_WORD;
        $documentXml = $this->readXmlPart($zipPath, 'word/document.xml');
        $paragraphTexts = [];
        foreach ($documentXml?->getElementsByTagNameNS('*', 'p') ?? [] as $paragraphElement) {
            $paragraphTexts[] = $this->readWordParagraphText($paragraphElement);
        }
        $this->setFlowPages($agentDocumentText, $paragraphTexts);
    }

    /** The text of one Word paragraph: its `w:t` runs, tabs and line breaks (a nested paragraph is read on its own). */
    protected function readWordParagraphText(DOMNode $paragraphNode): string
    {
        $paragraphText = '';
        foreach ($paragraphNode->childNodes as $childNode) {
            if (!$childNode instanceof DOMElement) {
                continue;
            }
            $paragraphText .= match ($childNode->localName) {
                't' => $childNode->textContent,
                'tab' => "\t",
                'br', 'cr' => "\n",
                'p' => '',
                default => $this->readWordParagraphText($childNode),
            };
        }
        return $paragraphText;
    }

    protected function extractSpreadsheetText(string $zipPath, ArchiveInspection $archiveInspection, AgentDocumentText $agentDocumentText): void
    {
        $agentDocumentText->documentKind = AgentDocumentText::KIND_SPREADSHEET;
        $agentDocumentText->pageWord = 'sheet';
        $sharedStrings = [];
        if ($archiveInspection->hasEntry('xl/sharedStrings.xml')) {
            foreach ($this->readXmlPart($zipPath, 'xl/sharedStrings.xml')?->getElementsByTagNameNS('*', 'si') ?? [] as $sharedStringElement) {
                $sharedStrings[] = $this->readTextOfDescendants($sharedStringElement, 't');
            }
        }
        $sheetTargetsByRelationshipId = $this->readRelationshipTargets($zipPath, 'xl/_rels/workbook.xml.rels', 'xl');
        $workbookXml = $this->readXmlPart($zipPath, 'xl/workbook.xml');
        foreach ($workbookXml?->getElementsByTagNameNS('*', 'sheet') ?? [] as $sheetElement) {
            /** @var DOMElement $sheetElement */
            $relationshipId = $sheetElement->getAttributeNS(static::OOXML_RELATIONSHIPS_NAMESPACE, 'id');
            $sheetPath = $sheetTargetsByRelationshipId[$relationshipId] ?? null;
            $sheetXml = $sheetPath !== null ? $this->readXmlPart($zipPath, $sheetPath) : null;
            $agentDocumentText->pageNames[] = $sheetElement->getAttribute('name');
            $agentDocumentText->pageTexts[] = $sheetXml !== null ? $this->readSheetText($sheetXml, $sharedStrings) : '';
        }
        $agentDocumentText->totalPageCount = count($agentDocumentText->pageTexts);
    }

    /**
     * The rows of one sheet: the row number, then the cells tab-separated in their columns.
     *
     * @param string[] $sharedStrings
     */
    protected function readSheetText(DOMDocument $sheetXml, array $sharedStrings): string
    {
        $rowLines = [];
        $sheetCharacters = 0;
        foreach ($sheetXml->getElementsByTagNameNS('*', 'row') as $rowElement) {
            /** @var DOMElement $rowElement */
            $cellValuesByColumn = [];
            foreach ($rowElement->childNodes as $cellElement) {
                if (!$cellElement instanceof DOMElement || $cellElement->localName !== 'c') {
                    continue;
                }
                $columnIndex = $this->readColumnIndexOfCellReference($cellElement->getAttribute('r'), count($cellValuesByColumn));
                if ($columnIndex >= static::MAX_SHEET_COLUMNS) {
                    continue;
                }
                $cellValue = $this->readSheetCellValue($cellElement, $sharedStrings);
                if ($cellValue !== '') {
                    $cellValuesByColumn[$columnIndex] = str_replace(["\t", "\r", "\n"], ' ', $cellValue);
                }
            }
            if ($cellValuesByColumn === []) {
                continue;
            }
            $rowCells = array_fill(0, max(array_keys($cellValuesByColumn)) + 1, '');
            foreach ($cellValuesByColumn as $columnIndex => $cellValue) {
                $rowCells[$columnIndex] = $cellValue;
            }
            $rowLine = ($rowElement->getAttribute('r') !== '' ? $rowElement->getAttribute('r') : (string)(count($rowLines) + 1)) . "\t" . implode("\t", $rowCells);
            $rowLines[] = $rowLine;
            $sheetCharacters += strlen($rowLine);
            if ($sheetCharacters > static::MAX_PAGE_CHARACTERS) {
                break;
            }
        }
        return implode("\n", $rowLines);
    }

    /** The 0-based column of a cell reference such as `C7`; `$fallbackColumnIndex` when the cell names none. */
    protected function readColumnIndexOfCellReference(string $cellReference, int $fallbackColumnIndex): int
    {
        if (preg_match('/^([A-Z]{1,3})\d*$/', strtoupper($cellReference), $columnMatch) !== 1) {
            return $fallbackColumnIndex;
        }
        $columnIndex = 0;
        foreach (str_split($columnMatch[1]) as $columnLetter) {
            $columnIndex = $columnIndex * 26 + (ord($columnLetter) - 64);
        }
        return $columnIndex - 1;
    }

    /** @param string[] $sharedStrings */
    protected function readSheetCellValue(DOMElement $cellElement, array $sharedStrings): string
    {
        $cellType = $cellElement->getAttribute('t');
        if ($cellType === 'inlineStr') {
            return $this->readTextOfDescendants($cellElement, 't');
        }
        $rawValue = '';
        foreach ($cellElement->childNodes as $childNode) {
            if ($childNode instanceof DOMElement && $childNode->localName === 'v') {
                $rawValue = $childNode->textContent;
            }
        }
        return match ($cellType) {
            's' => $sharedStrings[(int)$rawValue] ?? '',
            'b' => $rawValue === '1' ? 'TRUE' : ($rawValue === '0' ? 'FALSE' : $rawValue),
            default => $rawValue,
        };
    }

    protected function extractPresentationText(string $zipPath, AgentDocumentText $agentDocumentText): void
    {
        $agentDocumentText->documentKind = AgentDocumentText::KIND_PRESENTATION;
        $agentDocumentText->pageWord = 'slide';
        $slideTargetsByRelationshipId = $this->readRelationshipTargets($zipPath, 'ppt/_rels/presentation.xml.rels', 'ppt');
        $presentationXml = $this->readXmlPart($zipPath, 'ppt/presentation.xml');
        foreach ($presentationXml?->getElementsByTagNameNS('*', 'sldId') ?? [] as $slideIdElement) {
            /** @var DOMElement $slideIdElement */
            $slidePath = $slideTargetsByRelationshipId[$slideIdElement->getAttributeNS(static::OOXML_RELATIONSHIPS_NAMESPACE, 'id')] ?? null;
            $slideXml = $slidePath !== null ? $this->readXmlPart($zipPath, $slidePath) : null;
            $paragraphTexts = [];
            foreach ($slideXml?->getElementsByTagNameNS('*', 'p') ?? [] as $paragraphElement) {
                $paragraphText = $this->readTextOfDescendants($paragraphElement, 't');
                if (trim($paragraphText) !== '') {
                    $paragraphTexts[] = $paragraphText;
                }
            }
            $agentDocumentText->pageTexts[] = implode("\n", $paragraphTexts);
        }
        $agentDocumentText->totalPageCount = count($agentDocumentText->pageTexts);
    }

    /**
     * The targets of the relationships of an OOXML part, by relationship id, as archive entry names.
     *
     * @return array<string, string> method-local lookup: relationship id => entry name
     */
    protected function readRelationshipTargets(string $zipPath, string $relationshipsEntryName, string $baseDirectory): array
    {
        $targetsByRelationshipId = [];
        foreach ($this->readXmlPart($zipPath, $relationshipsEntryName)?->getElementsByTagNameNS('*', 'Relationship') ?? [] as $relationshipElement) {
            /** @var DOMElement $relationshipElement */
            if ($relationshipElement->getAttribute('TargetMode') === 'External') {
                continue;
            }
            $target = $relationshipElement->getAttribute('Target');
            $targetsByRelationshipId[$relationshipElement->getAttribute('Id')] = str_starts_with($target, '/')
                ? ltrim($target, '/')
                : $baseDirectory . '/' . $target;
        }
        return $targetsByRelationshipId;
    }

    protected function extractOpenDocumentText(string $zipPath, AgentDocumentText $agentDocumentText): void
    {
        $mimeType = trim((string)ArchiveInspector::readEntry($zipPath, 'mimetype', 200));
        $contentXml = $this->readXmlPart($zipPath, 'content.xml');
        if ($contentXml === null) {
            return;
        }
        if (str_ends_with($mimeType, '.spreadsheet')) {
            $agentDocumentText->documentKind = AgentDocumentText::KIND_SPREADSHEET;
            $agentDocumentText->pageWord = 'sheet';
            foreach ($contentXml->getElementsByTagNameNS('*', 'table') as $tableElement) {
                /** @var DOMElement $tableElement */
                if ($tableElement->parentNode instanceof DOMElement && $tableElement->parentNode->localName !== 'spreadsheet') {
                    continue;
                }
                $agentDocumentText->pageNames[] = $tableElement->getAttributeNS($tableElement->namespaceURI ?? '', 'name');
                $agentDocumentText->pageTexts[] = $this->readOpenDocumentTableText($tableElement);
            }
        } elseif (str_ends_with($mimeType, '.presentation')) {
            $agentDocumentText->documentKind = AgentDocumentText::KIND_PRESENTATION;
            $agentDocumentText->pageWord = 'slide';
            foreach ($contentXml->getElementsByTagNameNS('*', 'page') as $pageElement) {
                $agentDocumentText->pageTexts[] = implode("\n", $this->readOpenDocumentParagraphs($pageElement));
            }
        } elseif (str_contains($mimeType, 'opendocument.text')) {
            $agentDocumentText->documentKind = AgentDocumentText::KIND_WORD;
            $this->setFlowPages($agentDocumentText, $this->readOpenDocumentParagraphs($contentXml->documentElement));
            return;
        } else {
            $agentDocumentText->failureReason = 'This OpenDocument type is not read (only text, spreadsheet and presentation).';
            return;
        }
        $agentDocumentText->totalPageCount = count($agentDocumentText->pageTexts);
    }

    /** @return string[] the texts of the `text:p` and `text:h` elements below a node, in order */
    protected function readOpenDocumentParagraphs(?DOMNode $parentNode): array
    {
        $paragraphTexts = [];
        foreach ($parentNode?->childNodes ?? [] as $childNode) {
            if (!$childNode instanceof DOMElement) {
                continue;
            }
            if ($childNode->localName === 'p' || $childNode->localName === 'h') {
                $paragraphTexts[] = $childNode->textContent;
                continue;
            }
            array_push($paragraphTexts, ...$this->readOpenDocumentParagraphs($childNode));
        }
        return $paragraphTexts;
    }

    protected function readOpenDocumentTableText(DOMElement $tableElement): string
    {
        $rowLines = [];
        $tableCharacters = 0;
        $rowNumber = 0;
        foreach ($tableElement->getElementsByTagNameNS('*', 'table-row') as $rowElement) {
            /** @var DOMElement $rowElement */
            $rowRepeat = max(1, (int)$rowElement->getAttributeNS($rowElement->namespaceURI ?? '', 'number-rows-repeated'));
            $rowCells = [];
            foreach ($rowElement->childNodes as $cellElement) {
                if (!$cellElement instanceof DOMElement || ($cellElement->localName !== 'table-cell' && $cellElement->localName !== 'covered-table-cell')) {
                    continue;
                }
                $cellRepeat = min(static::MAX_ODF_REPEAT, max(1, (int)$cellElement->getAttributeNS($cellElement->namespaceURI ?? '', 'number-columns-repeated')));
                $cellText = str_replace(["\t", "\r", "\n"], ' ', implode(' ', $this->readOpenDocumentParagraphs($cellElement)));
                for ($repeatIndex = 0; $repeatIndex < $cellRepeat && count($rowCells) < static::MAX_SHEET_COLUMNS; $repeatIndex++) {
                    $rowCells[] = $cellText;
                }
            }
            while ($rowCells !== [] && end($rowCells) === '') {
                array_pop($rowCells);
            }
            if ($rowCells === []) {
                $rowNumber += $rowRepeat;
                continue;
            }
            for ($repeatIndex = 0; $repeatIndex < min($rowRepeat, static::MAX_ODF_REPEAT); $repeatIndex++) {
                $rowNumber++;
                $rowLine = $rowNumber . "\t" . implode("\t", $rowCells);
                $rowLines[] = $rowLine;
                $tableCharacters += strlen($rowLine);
            }
            if ($tableCharacters > static::MAX_PAGE_CHARACTERS) {
                break;
            }
        }
        return implode("\n", $rowLines);
    }

    protected function readTextOfDescendants(DOMNode $parentNode, string $textLocalName): string
    {
        $text = '';
        if ($parentNode instanceof DOMElement || $parentNode instanceof DOMDocument) {
            foreach ($parentNode->getElementsByTagNameNS('*', $textLocalName) as $textElement) {
                $text .= $textElement->textContent;
            }
        }
        return $text;
    }

    protected function isPlainText(string $bytes, AgentDriveFileContent $agentDriveFileContent): bool
    {
        if (str_contains($bytes, "\0") || !mb_check_encoding($bytes, 'UTF-8')) {
            return false;
        }
        $fileExtension = strtolower(pathinfo($agentDriveFileContent->fileName, PATHINFO_EXTENSION));
        return in_array($fileExtension, static::TEXT_FILE_EXTENSIONS, true)
            || str_starts_with(strtolower((string)$agentDriveFileContent->contentType), 'text/');
    }

    /** @return string[] */
    protected function splitTextIntoParagraphs(string $text): array
    {
        $text = (string)preg_replace('/^\xEF\xBB\xBF/', '', str_replace(["\r\n", "\r"], "\n", $text));
        return explode("\n", $text);
    }

    /**
     * Pages of about {@see AgentDocumentText::FLOW_PAGE_CHARACTERS} for a document without pages of its own; a page
     * breaks between paragraphs (a longer paragraph is a page of its own).
     *
     * @param string[] $paragraphTexts
     */
    protected function setFlowPages(AgentDocumentText $agentDocumentText, array $paragraphTexts): void
    {
        $pageTexts = [];
        $currentPageText = '';
        foreach ($paragraphTexts as $paragraphText) {
            $paragraphText = rtrim($paragraphText);
            if ($currentPageText !== '' && mb_strlen($currentPageText) + mb_strlen($paragraphText) > AgentDocumentText::FLOW_PAGE_CHARACTERS) {
                $pageTexts[] = rtrim($currentPageText);
                $currentPageText = '';
            }
            $currentPageText .= ($currentPageText === '' ? '' : "\n") . $paragraphText;
        }
        if (trim($currentPageText) !== '') {
            $pageTexts[] = rtrim($currentPageText);
        }
        $agentDocumentText->pageTexts = $pageTexts;
        $agentDocumentText->totalPageCount = count($pageTexts);
    }

    /** Keeps at most {@see AgentDocumentSettings::$maxExtractedPages} pages, each at most {@see MAX_PAGE_CHARACTERS}. */
    protected function limitPages(AgentDocumentText $agentDocumentText, AgentDocumentSettings $settings): void
    {
        $agentDocumentText->totalPageCount = max($agentDocumentText->totalPageCount, count($agentDocumentText->pageTexts));
        $agentDocumentText->pageTexts = array_slice($agentDocumentText->pageTexts, 0, $settings->maxExtractedPages);
        $agentDocumentText->pageNames = array_slice($agentDocumentText->pageNames, 0, $settings->maxExtractedPages);
        foreach ($agentDocumentText->pageTexts as $pageIndex => $pageText) {
            if (mb_strlen($pageText) > static::MAX_PAGE_CHARACTERS) {
                $agentDocumentText->pageTexts[$pageIndex] = mb_substr($pageText, 0, static::MAX_PAGE_CHARACTERS);
            }
        }
    }

    protected function readConfigNode(string $configKey): mixed
    {
        return Config::get($configKey);
    }

    /** The Core cache of a group (null = Core's default group). */
    protected function resolveCacheGroup(?string $cacheGroup): ?Cache
    {
        return Cache::instance($cacheGroup);
    }

    protected function resolveCacheGroupSafely(?string $cacheGroup): ?Cache
    {
        try {
            return $this->resolveCacheGroup($cacheGroup);
        } catch (Throwable) {
            return null;
        }
    }
}
