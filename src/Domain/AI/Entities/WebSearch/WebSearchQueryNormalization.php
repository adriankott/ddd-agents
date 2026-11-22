<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\WebSearch;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One query of a `searchTheWeb` call as the engine receives it: plain words. A model writes search-engine operators
 * into queries (`site:`, `OR`, quoted phrases, `intitle:`); the search endpoint takes words and filters, so the
 * operators are removed here, a `site:` domain is kept for the call's `domains` filter, and what was removed is named
 * for the note to the model. Pure.
 */
class WebSearchQueryNormalization extends ValueObject
{
    /** Above this many words a query reads as a keyword chain: the result notes that short queries find more. */
    public const int MAX_WORDS_OF_A_SHORT_QUERY = 10;

    /** Operators whose value stays in the query as a plain word (`intitle:pricing` → `pricing`). */
    public const array PREFIX_OPERATORS_KEPT_AS_WORDS = ['intitle', 'allintitle', 'inurl', 'allinurl', 'intext', 'allintext', 'filetype', 'ext'];

    /** The query as the engine receives it. */
    public string $query = '';

    /** The query as the model wrote it. */
    public string $rawQuery = '';

    /** @var string[] Domains of `site:` operators, lower case; an excluded domain (`-site:`) with a leading `-`. */
    public array $siteDomains = [];

    /** @var string[] The operators removed, as named for the model (`site:`, `OR`, `quotes`, `intitle:`). */
    public array $removedOperators = [];

    public static function fromRawQuery(string $rawQuery): static
    {
        $normalization = static::newInstance();
        $normalization->rawQuery = trim($rawQuery);
        $query = $normalization->rawQuery;

        if (preg_match_all('/(?<![\w-])(-?)site:\s*([^\s"()]+)/iu', $query, $siteMatches, PREG_SET_ORDER) > 0) {
            foreach ($siteMatches as $siteMatch) {
                $domain = strtolower(rtrim((string)preg_replace('~^[a-z][a-z0-9+.-]*://~i', '', $siteMatch[2]), '/'));
                if ($domain !== '') {
                    $siteDomain = ($siteMatch[1] === '-' ? '-' : '') . $domain;
                    if (!in_array($siteDomain, $normalization->siteDomains, true)) {
                        $normalization->siteDomains[] = $siteDomain;
                    }
                }
            }
            $query = (string)preg_replace('/(?<![\w-])-?site:\s*[^\s"()]+/iu', ' ', $query);
            $normalization->removedOperators[] = 'site:';
        }

        $prefixOperatorPattern = '/(?<![\w-])(' . implode('|', self::PREFIX_OPERATORS_KEPT_AS_WORDS) . '):\s*/iu';
        if (preg_match_all($prefixOperatorPattern, $query, $prefixMatches) > 0) {
            foreach ($prefixMatches[1] as $prefixOperator) {
                $operatorName = strtolower($prefixOperator) . ':';
                if (!in_array($operatorName, $normalization->removedOperators, true)) {
                    $normalization->removedOperators[] = $operatorName;
                }
            }
            $query = (string)preg_replace($prefixOperatorPattern, '', $query);
        }

        if (preg_match('/(?<!\S)(OR|AND)(?!\S)|\|/u', $query) === 1) {
            $query = (string)preg_replace('/(?<!\S)(OR|AND)(?!\S)|\|/u', ' ', $query);
            $normalization->removedOperators[] = 'OR';
        }

        if (preg_match('/["“”„«»]/u', $query) === 1) {
            $query = (string)preg_replace('/["“”„«»]/u', ' ', $query);
            $normalization->removedOperators[] = 'quotes';
        }

        if ($normalization->removedOperators !== []) {
            $query = (string)preg_replace('/[()]/u', ' ', $query);
        }
        $normalization->query = trim((string)preg_replace('/\s+/u', ' ', $query));
        return $normalization;
    }

    public function hasRemovedOperators(): bool
    {
        return $this->removedOperators !== [];
    }

    public function countWords(): int
    {
        return $this->query === '' ? 0 : count(preg_split('/\s+/u', $this->query) ?: []);
    }

    public function isKeywordChain(): bool
    {
        return $this->countWords() > self::MAX_WORDS_OF_A_SHORT_QUERY;
    }
}
