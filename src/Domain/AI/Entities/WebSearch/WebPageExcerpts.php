<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\WebSearch;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The search excerpts of several pages ({@see WebPageExcerpt}), one per canonical URL, in the order added.
 *
 * @property WebPageExcerpt[] $elements
 * @method WebPageExcerpt[] getElements()
 * @method WebPageExcerpt|null getByUniqueKey(string $uniqueKey)
 */
class WebPageExcerpts extends ObjectSet
{
    /** The excerpt of the page `$url` (any spelling of it), or null. */
    public function findExcerptOfUrl(string $url): ?WebPageExcerpt
    {
        return $this->getByUniqueKey(WebPageExcerpt::buildUniqueKeyForUrl($url));
    }

    /** The excerpts of the page `$url`; empty when none. @return string[] */
    public function findSnippetsOfUrl(string $url): array
    {
        $webPageExcerpt = $this->findExcerptOfUrl($url);
        return $webPageExcerpt instanceof WebPageExcerpt ? $webPageExcerpt->snippets : [];
    }

    /** Adds or replaces the excerpt of its page: a later excerpt of the same page wins. */
    public function addOrReplaceExcerpt(WebPageExcerpt $webPageExcerpt): void
    {
        $existingExcerpt = $this->findExcerptOfUrl($webPageExcerpt->url);
        if ($existingExcerpt instanceof WebPageExcerpt) {
            $existingExcerpt->snippets = $webPageExcerpt->snippets;
            return;
        }
        $this->add($webPageExcerpt);
    }

    /** @return string[] the page URLs, in the order added */
    public function getUrls(): array
    {
        $urls = [];
        foreach ($this->getElements() as $webPageExcerpt) {
            $urls[] = $webPageExcerpt->url;
        }
        return $urls;
    }
}
