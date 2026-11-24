<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\WebSearch;

use DDD\Domain\AI\Entities\WebSearch\WebPageMarkdowns;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A reader of web pages as markdown that replaces the url-to-markdown endpoint of the web research: a package or the
 * host implements it as a service (registered by this tag, autoconfigured), e.g. a browser that reads a page over
 * HTTP first and renders it in a browser when the text is thin. While a source is available,
 * {@see AIWebSearchService::getWebPageAsMarkdown()} and {@see AIWebSearchService::getWebPagesAsMarkdown()} read every
 * page through it; without one they call the endpoint as before.
 *
 * The refusals before any read stay with the web research and run first (a search engine's results page, a host
 * without a DNS record, a private address): a source is handed only public URLs. A source reports a per-page failure
 * on the element (`ok` false and a `REASON_*` of {@see \DDD\Domain\AI\Entities\WebSearch\WebPageMarkdown}), never as
 * an exception; an exception makes the web research fall back to the endpoint for that read. It names how it read a
 * page in {@see \DDD\Domain\AI\Entities\WebSearch\WebPageMarkdown::$fetchedVia}.
 */
#[AutoconfigureTag(WebPageTextSource::TAG)]
interface WebPageTextSource
{
    public const string TAG = 'ai.web_page_text_source';

    /** Whether the source can read pages now (configured, its backend reachable as far as known without a request) */
    public function isAvailable(): bool;

    /**
     * The pages as markdown: one element per URL in the order asked (`url` set to the URL asked), a failure on its
     * element.
     *
     * @param string[] $urls public URLs, trimmed and non-empty
     * @param bool $noCache read the pages again instead of serving a cached read
     * @param float|null $timeoutSeconds the caller's bound for the whole read; null = the source's own
     */
    public function getWebPagesAsMarkdown(array $urls, bool $noCache = false, ?float $timeoutSeconds = null): WebPageMarkdowns;
}
