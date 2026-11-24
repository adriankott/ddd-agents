<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Documents;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The settings of the agent's document reading (`AI.documents`, `config/app/AI/documents.php`): how large a document
 * may be, how much text one read returns, where the extracted text is kept, and the page images a read may ask for.
 * Read by {@see \DDD\Domain\AI\Services\Documents\AgentDocumentTextExtractionService::getSettings()}.
 *
 * @phpstan-consistent-constructor
 */
class AgentDocumentSettings extends ValueObject
{
    /** @var bool Whether documents are read at all (off = the read tools refuse) */
    public bool $enabled = true;

    /** @var int The largest document read, in bytes */
    public int $maxDocumentBytes = 26_214_400;

    /** @var int The most pages (sheets, slides) of one document whose text is extracted */
    public int $maxExtractedPages = 500;

    /** @var int The most characters one read returns (the next pages are named for a further read) */
    public int $maxCharactersPerRead = 40_000;

    /** @var bool Whether a PDF without a text layer is read by OCR (tesseract) */
    public bool $ocrEnabled = true;

    /** @var string|null The cache group of the extracted text; null = Core's default group */
    public ?string $cacheGroup = null;

    /** @var int The TTL of the extracted text in the cache, in seconds */
    public int $cacheTtlSeconds = 86_400;

    /** @var bool Whether page images may be asked for (PDF) */
    public bool $pageImagesEnabled = true;

    /** @var int The most page images of one call */
    public int $maxPageImagesPerCall = 4;

    /** @var int The resolution page images are rendered at, in DPI */
    public int $pageImageResolutionDpi = 110;

    /**
     * The settings of a config node (`AI.documents`); a missing or invalid key keeps its default.
     *
     * @param mixed $configNode
     * @return static
     */
    public static function fromConfigNode(mixed $configNode): static
    {
        $settings = new static();
        $configNode = is_array($configNode) ? $configNode : [];
        $settings->enabled = static::readBool($configNode, 'enabled', $settings->enabled);
        $settings->maxDocumentBytes = static::readPositiveInt($configNode, 'maxDocumentBytes', $settings->maxDocumentBytes);
        $settings->maxExtractedPages = static::readPositiveInt($configNode, 'maxExtractedPages', $settings->maxExtractedPages);
        $settings->maxCharactersPerRead = static::readPositiveInt($configNode, 'maxCharactersPerRead', $settings->maxCharactersPerRead);
        $settings->ocrEnabled = static::readBool($configNode, 'ocr', $settings->ocrEnabled);
        $settings->cacheGroup = is_string($configNode['cacheGroup'] ?? null) && trim($configNode['cacheGroup']) !== '' ? trim($configNode['cacheGroup']) : null;
        $settings->cacheTtlSeconds = static::readPositiveInt($configNode, 'cacheTtlSeconds', $settings->cacheTtlSeconds);
        $pageImages = is_array($configNode['pageImages'] ?? null) ? $configNode['pageImages'] : [];
        $settings->pageImagesEnabled = static::readBool($pageImages, 'enabled', $settings->pageImagesEnabled);
        $settings->maxPageImagesPerCall = min(12, static::readPositiveInt($pageImages, 'maxPerCall', $settings->maxPageImagesPerCall));
        $settings->pageImageResolutionDpi = max(36, min(300, static::readPositiveInt($pageImages, 'resolutionDpi', $settings->pageImageResolutionDpi)));
        return $settings;
    }

    /** @param array<string, mixed> $node */
    protected static function readBool(array $node, string $key, bool $default): bool
    {
        return is_bool($node[$key] ?? null) ? $node[$key] : $default;
    }

    /** @param array<string, mixed> $node */
    protected static function readPositiveInt(array $node, string $key, int $default): int
    {
        return is_int($node[$key] ?? null) && $node[$key] > 0 ? $node[$key] : $default;
    }
}
