<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\WebSearch;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The search engine's excerpts of ONE page: what the fast model reads beside the page text, and alone when the page
 * cannot be crawled. Keyed by the canonical URL ({@see WebPageMarkdown::canonicalUrlKey()}), so every spelling of a
 * page finds its excerpts.
 */
class WebPageExcerpt extends ValueObject
{
    /** The page URL as the search listed it. */
    public string $url = '';

    /** @var string[] The excerpts, non-empty, in the order the engine gave them. */
    public array $snippets = [];

    /** An excerpt of `$url` with the non-empty trimmed `$snippets`. */
    public static function fromUrlAndSnippets(string $url, array $snippets): static
    {
        $webPageExcerpt = static::newInstance();
        $webPageExcerpt->url = trim($url);
        foreach ($snippets as $snippet) {
            $snippet = trim((string)$snippet);
            if ($snippet !== '') {
                $webPageExcerpt->snippets[] = $snippet;
            }
        }
        return $webPageExcerpt;
    }

    public function uniqueKey(): string
    {
        return self::buildUniqueKeyForUrl($this->url);
    }

    /** The set key of the excerpt of a page: its canonical URL under this class. */
    public static function buildUniqueKeyForUrl(string $url): string
    {
        return self::uniqueKeyStatic(WebPageMarkdown::canonicalUrlKey($url));
    }
}
