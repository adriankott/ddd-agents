<?php

declare(strict_types=1);

/**
 * The agent's document reading — read by
 * {@see \DDD\Domain\AI\Services\Documents\AgentDocumentTextExtractionService} as `Config::get('AI.documents')`
 * ({@see \DDD\Domain\AI\Entities\Documents\AgentDocumentSettings}). An application replaces this file whole with its
 * own `config/app/AI/documents.php`; a missing key keeps its default.
 *
 * A document of a drive (PDF, Word, Excel, PowerPoint, OpenDocument, plain text) reaches the model as its extracted
 * text, page by page (`readDriveDocument`); page images only when asked for (`viewDriveDocumentPages`, PDF). The bytes
 * come from the drive store through the backend, never from a URL. Nothing of the document is executed: a PDF is read
 * by poppler (`pdftotext`, `pdftoppm`) as an isolated process, an Office or OpenDocument file is a ZIP whose XML is
 * read as text (macros are never run, never extracted).
 *
 * `enabled` false: the read tools refuse. `maxDocumentBytes`: a larger document is refused. `maxExtractedPages`: the
 * pages (sheets, slides) whose text is kept. `maxCharactersPerRead`: one read returns at most this much text and names
 * the next page. `ocr`: a PDF without a text layer is read by tesseract (at most 20 pages).
 * `cacheGroup` / `cacheTtlSeconds`: the extracted text is kept by the document's sha256 (null = Core's default group;
 * a shared group such as `'redis'` lets every worker reuse it).
 * `pageImages`: on or off, the most images per call, the resolution in DPI. Each image then goes through the tool
 * picture store (`AI.toolCallImageStore`): wire profile, tiers, retention.
 * Hosts install poppler-utils (and tesseract for OCR) in the image that runs the workers.
 */
return [
    'enabled' => true,
    'maxDocumentBytes' => 26_214_400,
    'maxExtractedPages' => 500,
    'maxCharactersPerRead' => 40_000,
    'ocr' => true,
    'cacheGroup' => null,
    'cacheTtlSeconds' => 86_400,
    'pageImages' => [
        'enabled' => true,
        'maxPerCall' => 4,
        'resolutionDpi' => 110,
    ],
];
