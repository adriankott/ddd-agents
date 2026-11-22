<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\WebSearch;

use DDD\Domain\AI\Entities\MCPPackages\ToolResultUntrustedSegments;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The markdown rendering of ONE web page, fetched through the host's url-to-markdown endpoint
 * (`AI.webSearch.endpoints.urlToMarkdown`). The endpoint reports a per-page failure in the payload ({@see $ok} false
 * and {@see $reason}), never as an HTTP error. Loaded by {@see \DDD\Domain\AI\Repo\Argus\WebSearch\ArgusWebPageMarkdown};
 * the agent's `getWebPageAsMarkdown` tool renders {@see buildLlmMarkdown()}.
 */
class WebPageMarkdown extends ValueObject
{
    /** The endpoint's failure reason: the URL resolved to a private or internal address. Never retryable. */
    public const string REASON_BLOCKED = 'BLOCKED';

    /** The endpoint's failure reason: the crawl timed out. One retry may succeed. */
    public const string REASON_TIMEOUT = 'TIMEOUT';

    /** The endpoint's failure reason: the site is unreachable or blocks the crawler. Not retryable now. */
    public const string REASON_FETCH_FAILED = 'FETCH_FAILED';

    /** The endpoint's failure reason: the page yielded empty markdown. */
    public const string REASON_NO_CONTENT = 'NO_CONTENT';

    /**
     * Detected here ({@see downgradeBotChallengeToFailure()}): the crawler received a bot-protection challenge page
     * that the endpoint reported as a success. The content is the challenge, not the site.
     */
    public const string REASON_BOT_CHALLENGE = 'BOT_CHALLENGE';

    /** Detected before any crawl: the host has no DNS record (no such website, or a typo in the address). */
    public const string REASON_UNRESOLVABLE = 'UNRESOLVABLE';

    /**
     * Detected before any crawl ({@see isSearchEngineResultsPageUrl()}): the URL is a search engine's results page.
     * A results page is no source; searching is `searchTheWeb`.
     */
    public const string REASON_SEARCH_RESULTS_PAGE = 'SEARCH_RESULTS_PAGE';

    /** Detected before any crawl: the application has switched page reads off (`AI.webSearch.capabilities.urlToMarkdown`). */
    public const string REASON_CAPABILITY_OFF = 'CAPABILITY_OFF';

    /**
     * @var string[][] Search engine host (a key ending in `.` matches every country domain) => the result-page path
     * prefixes on that host. Only the well-known engines.
     */
    protected const array SEARCH_ENGINE_RESULT_PATHS_BY_HOST = [
        'google.' => ['/search', '/webhp'],
        'bing.com' => ['/search', '/images/search', '/videos/search', '/news/search'],
        'duckduckgo.com' => ['/', '/html', '/lite'],
        'ecosia.org' => ['/search'],
        'search.yahoo.com' => ['/search'],
        'startpage.com' => ['/do/search', '/sp/search', '/do/dsearch'],
        'search.brave.com' => ['/search'],
        'yandex.com' => ['/search'],
        'yandex.ru' => ['/search'],
    ];

    /**
     * @var string[] Lower-cased markers of bot-protection challenge pages: well-known interstitial phrases only, so a
     * real page is never classified by its topic.
     */
    protected const array BOT_CHALLENGE_CONTENT_MARKERS = [
        'checking your browser',
        'just a moment...',
        'enable javascript and cookies to continue',
        'attention required! | cloudflare',
        'verify you are human',
        'ddos-guard',
        'cf-chl',
    ];

    /** A page longer than this (title and text) is never classified as a challenge page. */
    protected const int BOT_CHALLENGE_MAX_CHARS = 2000;

    /** The requested URL (echoed back by the endpoint). */
    public ?string $url = null;

    /** Whether the page was fetched and converted. */
    public ?bool $ok = null;

    /** The page title; present only on success. */
    public ?string $title = null;

    /** The page content as markdown; present only on success. */
    public ?string $markdown = null;

    /** The failure reason (one of the `REASON_*` constants); present only when {@see $ok} is false. */
    public ?string $reason = null;

    /**
     * One identity per page (the cache key of a page read and the merge key of search hits): the scheme and the
     * fragment dropped, the host lower-cased without a leading `www.`, trailing slashes dropped; path and query kept as
     * typed (`/shop` and `/shop?x=1` are different pages).
     */
    public static function canonicalUrlKey(string $url): string
    {
        $url = trim($url);
        $url = (string)preg_replace('~^[a-z][a-z0-9+.-]*://~i', '', $url);
        $url = (string)preg_replace('~#.*$~', '', $url);
        if (preg_match('~^([^/?]*)(.*)$~s', $url, $urlParts) !== 1) {
            return $url;
        }
        $host = strtolower($urlParts[1]);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        return $host . rtrim($urlParts[2], '/');
    }

    /** A failed page for `$url` with `$reason`, decided without a crawl. */
    public static function fromRefusedUrl(string $url, string $reason): static
    {
        $webPageMarkdown = static::newInstance();
        $webPageMarkdown->url = trim($url);
        $webPageMarkdown->ok = false;
        $webPageMarkdown->reason = $reason;
        return $webPageMarkdown;
    }

    /**
     * Turns a "successful" fetch that returned a bot-protection interstitial into a failure. A real page may mention
     * these phrases in passing, so only a SHORT page (typical of an interstitial) is downgraded.
     */
    public function downgradeBotChallengeToFailure(): void
    {
        if (!($this->ok ?? false)) {
            return;
        }
        $titleAndContentLowercased = mb_strtolower(trim((string)($this->title ?? '') . "\n" . (string)($this->markdown ?? '')));
        if (mb_strlen($titleAndContentLowercased) > static::BOT_CHALLENGE_MAX_CHARS) {
            return;
        }
        foreach (static::BOT_CHALLENGE_CONTENT_MARKERS as $botChallengeContentMarker) {
            if (str_contains($titleAndContentLowercased, $botChallengeContentMarker)) {
                $this->ok = false;
                $this->reason = self::REASON_BOT_CHALLENGE;
                $this->markdown = null;
                return;
            }
        }
    }

    /**
     * The page for the model's context: a header line (title — url), the page text marked as untrusted, capped at
     * `$maxContentChars` with an explicit truncation notice (never a silent cut). Call only on a successful fetch.
     */
    public function buildLlmMarkdown(int $maxContentChars): string
    {
        $headerLine = trim((string)($this->title ?? '')) !== ''
            ? '# ' . trim((string)$this->title) . ' — ' . $this->url
            : '# ' . $this->url;
        $markdownContent = (string)($this->markdown ?? '');
        $totalContentChars = mb_strlen($markdownContent);
        $truncationNotice = '';
        if ($totalContentChars > $maxContentChars) {
            $markdownContent = mb_substr($markdownContent, 0, $maxContentChars);
            $truncationNotice = "\n\n[Page truncated: showing " . number_format($maxContentChars) . ' of '
                . number_format($totalContentChars) . ' characters — the page continues beyond this point.]';
        }
        // the page's text is written by the site: marked; how to read the marks is the tool's platform note
        return $headerLine . "\n\n" . ToolResultUntrustedSegments::wrap((string)$this->url, $markdownContent) . $truncationNotice;
    }

    /**
     * Whether the URL is a search engine's RESULTS page (not a site's own page). Pure classification of host and
     * path; an unparsable URL is not a results page.
     */
    public static function isSearchEngineResultsPageUrl(string $url): bool
    {
        $host = strtolower((string)parse_url(trim($url), PHP_URL_HOST));
        $path = rtrim((string)parse_url(trim($url), PHP_URL_PATH), '/') ?: '/';
        if ($host === '') {
            return false;
        }
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        foreach (static::SEARCH_ENGINE_RESULT_PATHS_BY_HOST as $engineHost => $resultPathPrefixes) {
            $hostMatches = str_ends_with($engineHost, '.')
                ? str_starts_with($host, $engineHost) || str_contains($host, '.' . $engineHost)
                : ($host === $engineHost || str_ends_with($host, '.' . $engineHost));
            if (!$hostMatches) {
                continue;
            }
            foreach ($resultPathPrefixes as $resultPathPrefix) {
                if ($path === $resultPathPrefix || ($resultPathPrefix !== '/' && str_starts_with($path, $resultPathPrefix . '/'))) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Why the fetch failed and what to do about it, for the model: whether ONE retry is worth it (timeout) or not
     * (blocked, unreachable, empty), and to go on without the page. Call when {@see $ok} is false.
     */
    public function buildFailureExplanationForAgent(): string
    {
        $urlLabel = (string)($this->url ?? 'the URL');
        $continueWithoutThePage = 'Continue with what you already know and, for a missing fact, searchTheWeb (never for '
            . 'the content of this page; ONE value asked from exactly this page is read by '
            . 'extractInformationFromWebPagesWithFastLLM with this URL, when that tool is available to you — it reads such '
            . 'a page from its search excerpt). Do not make the failed read the topic of a message.';
        return match ($this->reason) {
            self::REASON_SEARCH_RESULTS_PAGE => "{$urlLabel} is a search engine's results page, not a source — this tool reads "
                . 'ONE website page. NEVER read a results page; to search, use searchTheWeb.',
            self::REASON_UNRESOLVABLE => "The address {$urlLabel} has no DNS record right now — no such website, or a typo "
                . 'in the address; this is NOT a private address. NEVER retry it. ' . $continueWithoutThePage,
            self::REASON_BLOCKED => "The URL {$urlLabel} was refused for security (no safe public destination). NEVER retry "
                . 'it. ' . $continueWithoutThePage,
            self::REASON_TIMEOUT => "Fetching {$urlLabel} timed out. You may retry ONCE; if it times out again, "
                . $continueWithoutThePage,
            self::REASON_FETCH_FAILED => "The page {$urlLabel} could not be fetched (unreachable, or it blocks automated readers). "
                . 'Do not retry. ' . $continueWithoutThePage,
            self::REASON_NO_CONTENT => "The page {$urlLabel} loaded but yielded no readable content (empty or fully scripted page). "
                . 'Do not retry. ' . $continueWithoutThePage,
            self::REASON_BOT_CHALLENGE => "The site {$urlLabel} is protected by a bot-protection challenge — the crawler "
                . 'only receives a "checking your browser" page, never the content. Do NOT retry this tool on that '
                . 'domain, and do NOT search the web for the content of that site. When this page was one of several to '
                . 'read, read the next one; otherwise say that this site cannot be read automatically.',
            self::REASON_CAPABILITY_OFF => 'Reading web pages is switched off in this application. Do not retry; answer '
                . 'from the search results or what you already know.',
            default => "The page {$urlLabel} could not be read right now. Do not retry immediately. " . $continueWithoutThePage,
        };
    }
}
