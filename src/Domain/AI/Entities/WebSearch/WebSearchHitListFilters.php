<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\WebSearch;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The search settings one `searchTheWeb` call applies to every one of its queries: the engine's mode,
 * the country, a recency window, a domain allow/deny list, language codes, the number of hits per query and the size
 * of each excerpt. Part of every hit list's cache key — two calls that differ in a filter never share an answer.
 */
class WebSearchHitListFilters extends ValueObject
{
    /** The engine's standard search. */
    public const string MODE_WEB = 'web';

    /** The engine's lower-latency search — the default. */
    public const string MODE_FAST = 'fast';

    public const array MODES = [self::MODE_FAST, self::MODE_WEB];

    public const array RECENCY_WINDOWS = ['hour', 'day', 'week', 'month', 'year'];

    /** The engine's per-request limit for the domain and the language lists. */
    public const int MAX_LIST_ENTRIES = 20;

    public const int MIN_HITS_PER_QUERY = 1;

    public const int MAX_HITS_PER_QUERY = 20;

    public string $mode = self::MODE_FAST;

    /** ISO 3166-1 alpha-2, upper case; null = the engine's default. */
    public ?string $countryCode = null;

    /** One of {@see RECENCY_WINDOWS}; null = no recency filter. */
    public ?string $recencyWindow = null;

    /** @var string[] Domains to search in; an entry with a leading `-` excludes that domain. */
    public array $domains = [];

    /** @var string[] ISO 639-1 language codes. */
    public array $languageCodes = [];

    public int $maxHitsPerQuery = 8;

    /** Cap of the engine's excerpt per page, in tokens (the engine rarely returns more than about 1,800 characters). */
    public int $maxTokensPerPage = 4096;

    /**
     * Filters from the tool's raw arguments, every value clamped to what the engine accepts: an unknown mode falls back
     * to {@see MODE_FAST}, an unknown recency window and a malformed country are dropped, the lists are trimmed,
     * lower-cased, deduplicated and cut at {@see MAX_LIST_ENTRIES}, malformed language codes are dropped, the hit count
     * is clamped to 1–20. Pure.
     *
     * @param string[] $domains
     * @param string[] $languageCodes
     */
    public static function fromToolArguments(
        ?string $mode,
        ?string $countryCode,
        ?string $recencyWindow,
        array $domains,
        array $languageCodes,
        ?int $maxHitsPerQuery,
        int $defaultMaxHitsPerQuery,
        int $maxTokensPerPage,
    ): static {
        $filters = static::newInstance();
        $normalizedMode = strtolower(trim((string)$mode));
        $filters->mode = in_array($normalizedMode, self::MODES, true) ? $normalizedMode : self::MODE_FAST;
        $normalizedCountryCode = strtoupper(trim((string)$countryCode));
        $filters->countryCode = preg_match('/^[A-Z]{2}$/', $normalizedCountryCode) === 1 ? $normalizedCountryCode : null;
        $normalizedRecencyWindow = strtolower(trim((string)$recencyWindow));
        $filters->recencyWindow = in_array($normalizedRecencyWindow, self::RECENCY_WINDOWS, true) ? $normalizedRecencyWindow : null;
        $filters->domains = self::buildCleanLowerCaseList($domains);
        $filters->languageCodes = [];
        foreach (self::buildCleanLowerCaseList($languageCodes) as $languageCode) {
            if (preg_match('/^[a-z]{2}$/', $languageCode) === 1) {
                $filters->languageCodes[] = $languageCode;
            }
        }
        $filters->maxHitsPerQuery = max(self::MIN_HITS_PER_QUERY, min(self::MAX_HITS_PER_QUERY, $maxHitsPerQuery ?? $defaultMaxHitsPerQuery));
        $filters->maxTokensPerPage = max(1, $maxTokensPerPage);
        return $filters;
    }

    /**
     * The request body of the search endpoint for one query (`AI.webSearch.endpoints.search`): `query`, `max_results`,
     * `max_tokens_per_page`, `search_type`, and when set `country`, `search_recency_filter`, `search_domain_filter`,
     * `search_language_filter`. The wire shape of the endpoint, built right before the request.
     *
     * @return array<string, mixed>
     */
    public function buildSearchRequestBody(string $query): array
    {
        $requestBody = [
            'query' => $query,
            'max_results' => $this->maxHitsPerQuery,
            'max_tokens_per_page' => $this->maxTokensPerPage,
            'search_type' => $this->mode,
        ];
        if ($this->countryCode !== null) {
            $requestBody['country'] = $this->countryCode;
        }
        if ($this->recencyWindow !== null) {
            $requestBody['search_recency_filter'] = $this->recencyWindow;
        }
        if ($this->domains !== []) {
            $requestBody['search_domain_filter'] = $this->domains;
        }
        if ($this->languageCodes !== []) {
            $requestBody['search_language_filter'] = $this->languageCodes;
        }
        return $requestBody;
    }

    /** Every setting that changes what the engine returns, as one stable string for the cache key. */
    public function buildCacheKeyPart(): string
    {
        return implode('|', [
            $this->mode,
            $this->countryCode ?? '',
            $this->recencyWindow ?? '',
            implode(',', $this->domains),
            implode(',', $this->languageCodes),
            (string)$this->maxHitsPerQuery,
            (string)$this->maxTokensPerPage,
        ]);
    }

    /**
     * @param array<int, mixed> $entries
     * @return string[] trimmed, lower-cased, non-empty, first occurrence kept, at most {@see MAX_LIST_ENTRIES}
     */
    protected static function buildCleanLowerCaseList(array $entries): array
    {
        $cleanedEntries = [];
        foreach ($entries as $entry) {
            $cleanedEntry = strtolower(trim((string)$entry));
            if ($cleanedEntry !== '' && !in_array($cleanedEntry, $cleanedEntries, true)) {
                $cleanedEntries[] = $cleanedEntry;
            }
        }
        return array_slice($cleanedEntries, 0, self::MAX_LIST_ENTRIES);
    }
}
