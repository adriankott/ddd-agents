<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\WebSearch;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The answer of the search engine to ONE query: the query, the filters it ran with, the ranked hits — or the
 * reason it brought none. An empty hit list is an answered search that found nothing; a failure reason means the
 * engine could not be asked (timeout, transport error). Loaded by {@see \DDD\Domain\AI\Repo\Argus\WebSearch\ArgusWebSearchHitList}.
 */
class WebSearchHitList extends ValueObject
{
    public string $query = '';

    public ?WebSearchHitListFilters $filters = null;

    public ?WebSearchHits $hits = null;

    /** Why the engine could not be asked; null when it answered (possibly with no hits). */
    public ?string $failureReason = null;

    /** Whether the engine answered this query (with or without hits). */
    public function isAnswered(): bool
    {
        return $this->failureReason === null;
    }

    /**
     * The content identity of this search: the normalized query (whitespace collapsed, case folded) and every filter
     * that changes the answer, hashed. Two spellings of the same question with the same filters are one search.
     */
    public function buildContentKey(): string
    {
        $normalizedQuery = mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $this->query)));
        return md5('HITS|' . ($this->filters?->buildCacheKeyPart() ?? '') . '|' . $normalizedQuery);
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->buildContentKey());
    }
}
