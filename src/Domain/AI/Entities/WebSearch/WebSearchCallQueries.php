<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\WebSearch;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The queries of one `searchTheWeb` call after their operators were removed ({@see WebSearchQueryNormalization}),
 * the call's `domains` filter, and the note that tells the model what changed. The `site:` domains become the call's
 * filter only when the call has no `domains` of its own and every query named a site (the filter applies to all
 * queries of a call); otherwise they are dropped, and the note says how to search a site. Pure.
 */
class WebSearchCallQueries extends ValueObject
{
    /** @var string[] The queries as the engine receives them, deduplicated, in the order given. */
    public array $queries = [];

    /** @var string[] The call's `domains` filter: the requested one, or the `site:` domains of the queries. */
    public array $domains = [];

    /** The note for the model; null when the queries were plain and short. */
    public ?string $noteForModel = null;

    /**
     * @var string|null The `recency` window the call searches with: the requested one, or the window a query named
     * unambiguously ({@see findRecencyWindowsOfQuery()}) when none was requested.
     */
    public ?string $recency = null;

    /**
     * Unambiguous time phrases of a query → the `recency` window they mean, in English, German, French, Spanish and
     * Italian. A phrase is matched as whole words ({@see buildWholeWordsPattern()}).
     */
    public const array RECENCY_WINDOWS_BY_PHRASE = [
        'day' => [
            'today', 'past 24 hours', 'last 24 hours',
            'heute', 'letzte 24 stunden', 'letzten 24 stunden',
            "aujourd'hui", 'dernières 24 heures',
            'hoy', 'últimas 24 horas',
            'oggi', 'ultime 24 ore',
        ],
        'week' => [
            'this week', 'past week',
            'diese woche', 'dieser woche', 'diesen woche',
            'cette semaine',
            'esta semana',
            'questa settimana',
        ],
        'month' => [
            'this month', 'past month',
            'diesen monat', 'dieser monat', 'diesem monat',
            'ce mois', 'ce mois-ci',
            'este mes',
            'questo mese',
        ],
    ];

    /** "past N days" in the five languages: the number is the first captured group that matched. */
    public const array PAST_DAYS_PATTERNS = [
        '/(?<![\p{L}\d])(?:past|last)\s+(\d{1,3})\s+days?(?![\p{L}\d])/iu',
        '/(?<![\p{L}\d])(?:letzte[nr]?|vergangene[nr]?)\s+(\d{1,3})\s+tagen?(?![\p{L}\d])/iu',
        '/(?<![\p{L}\d])(?:(\d{1,3})\s+derniers\s+jours|derniers\s+(\d{1,3})\s+jours)(?![\p{L}\d])/iu',
        '/(?<![\p{L}\d])últimos\s+(\d{1,3})\s+días(?![\p{L}\d])/iu',
        '/(?<![\p{L}\d])ultimi\s+(\d{1,3})\s+giorni(?![\p{L}\d])/iu',
    ];

    /** Month names in the five languages → the month number. */
    public const array MONTH_NUMBERS_BY_NAME = [
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6, 'july' => 7,
        'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
        'januar' => 1, 'jänner' => 1, 'februar' => 2, 'märz' => 3, 'mai' => 5, 'juni' => 6, 'juli' => 7,
        'oktober' => 10, 'dezember' => 12,
        'janvier' => 1, 'février' => 2, 'mars' => 3, 'avril' => 4, 'juin' => 6, 'juillet' => 7, 'août' => 8,
        'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'décembre' => 12,
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7,
        'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12,
        'gennaio' => 1, 'febbraio' => 2, 'aprile' => 4, 'maggio' => 5, 'giugno' => 6, 'luglio' => 7,
        'settembre' => 9, 'ottobre' => 10, 'dicembre' => 12,
    ];

    /** Month names that are ordinary words too: they count as the current month only with the year after them. */
    public const array MONTH_NAMES_ALSO_ORDINARY_WORDS = ['may', 'mars', 'mai'];

    /**
     * Words that ask for current items without saying how current: the note suggests `recency`. A year alone does not
     * count: "prices 2026" or "trends 2026" are facts whose best sources may be months old.
     */
    public const string CURRENT_ITEMS_PATTERN = '/(?<![\p{L}\d])(news|latest|breaking|headlines?|yesterday|nachrichten|neuigkeiten|schlagzeilen|gestern|actualités|noticias|notizie|ultime notizie)(?![\p{L}\d])/iu';

    /**
     * The `recency` windows the time phrases of `$rawQuery` name unambiguously: a phrase of
     * {@see RECENCY_WINDOWS_BY_PHRASE}, "past N days" (up to 1 → day, 7 → week, 31 → month, 366 → year), and the name
     * of the CURRENT month of `$now` (with no year or the current year; a name that is also an ordinary word, "may",
     * "mars", only with the year) → month.
     *
     * @return string[]
     */
    public static function findRecencyWindowsOfQuery(string $rawQuery, \DateTimeInterface $now): array
    {
        $recencyWindows = [];
        $lowerQuery = mb_strtolower($rawQuery);
        foreach (static::RECENCY_WINDOWS_BY_PHRASE as $recencyWindow => $phrases) {
            foreach ($phrases as $phrase) {
                if (preg_match(static::buildWholeWordsPattern($phrase), $lowerQuery) === 1) {
                    $recencyWindows[] = $recencyWindow;
                    break;
                }
            }
        }
        foreach (static::PAST_DAYS_PATTERNS as $pastDaysPattern) {
            if (preg_match($pastDaysPattern, $lowerQuery, $pastDaysMatch) !== 1) {
                continue;
            }
            $dayCount = 0;
            foreach (array_slice($pastDaysMatch, 1) as $capturedNumber) {
                if ($capturedNumber !== '') {
                    $dayCount = (int)$capturedNumber;
                    break;
                }
            }
            $recencyWindow = match (true) {
                $dayCount < 1 => null,
                $dayCount <= 1 => 'day',
                $dayCount <= 7 => 'week',
                $dayCount <= 31 => 'month',
                $dayCount <= 366 => 'year',
                default => null,
            };
            if ($recencyWindow !== null) {
                $recencyWindows[] = $recencyWindow;
            }
        }
        $currentMonthNumber = (int)$now->format('n');
        $currentYear = (int)$now->format('Y');
        foreach (static::findNamedMonthKeys($lowerQuery) as $namedMonthKey) {
            [$monthNumber, $namedYear] = explode(':', $namedMonthKey);
            if ((int)$monthNumber === $currentMonthNumber && ($namedYear === '' || (int)$namedYear === $currentYear)) {
                $recencyWindows[] = 'month';
            }
        }
        return array_values(array_unique($recencyWindows));
    }

    /** Whether `$rawQuery` names a month WITH a year other than the current month of `$now`: a date, but no window. */
    public static function namesAnotherMonthWithItsYear(string $rawQuery, \DateTimeInterface $now): bool
    {
        $currentMonthNumber = (int)$now->format('n');
        $currentYear = (int)$now->format('Y');
        foreach (static::findNamedMonthKeys(mb_strtolower($rawQuery)) as $namedMonthKey) {
            [$monthNumber, $namedYear] = explode(':', $namedMonthKey);
            if ($namedYear !== '' && ((int)$monthNumber !== $currentMonthNumber || (int)$namedYear !== $currentYear)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The months named in `$lowerQuery` as flat strings `<month number>:<year>` (`11:2026`), the year empty when none
     * follows the name (`11:`); a name that is also an ordinary word ({@see MONTH_NAMES_ALSO_ORDINARY_WORDS}) only with
     * its year.
     *
     * @return string[]
     */
    protected static function findNamedMonthKeys(string $lowerQuery): array
    {
        $namedMonthKeys = [];
        foreach (static::MONTH_NUMBERS_BY_NAME as $monthName => $monthNumber) {
            $monthPattern = '/(?<![\\p{L}\\d])' . preg_quote($monthName, '/') . '(?:\\s+(\\d{4}))?(?![\\p{L}\\d])/iu';
            if (preg_match($monthPattern, $lowerQuery, $monthMatch) !== 1) {
                continue;
            }
            $namedYear = $monthMatch[1] ?? '';
            if ($namedYear === '' && in_array($monthName, static::MONTH_NAMES_ALSO_ORDINARY_WORDS, true)) {
                continue;
            }
            $namedMonthKeys[] = $monthNumber . ':' . $namedYear;
        }
        return $namedMonthKeys;
    }

    /** A pattern matching `$phrase` as whole words, any run of spaces between them. */
    protected static function buildWholeWordsPattern(string $phrase): string
    {
        $words = preg_split('/\s+/u', trim($phrase)) ?: [];
        $quotedWords = [];
        foreach ($words as $word) {
            $quotedWords[] = preg_quote($word, '/');
        }
        return '/(?<![\p{L}\d])' . implode('\s+', $quotedWords) . '(?![\p{L}\d])/iu';
    }

    /**
     * @param string[] $rawQueries the queries as the model wrote them (deduplicated, non-empty)
     * @param string[] $requestedDomains the `domains` argument of the call
     * @param string|null $requestedRecency the `recency` argument of the call
     * @param \DateTimeInterface|null $now the moment in the conversation's zone (a month name is read against it); null = now in UTC
     */
    public static function fromRawQueries(array $rawQueries, array $requestedDomains, ?string $requestedRecency = null, ?\DateTimeInterface $now = null): static
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $namesAnotherDate = false;
        $callQueries = static::newInstance();
        $callQueries->domains = array_values($requestedDomains);
        $requestedRecency = $requestedRecency !== null && trim($requestedRecency) !== '' ? trim($requestedRecency) : null;
        $callQueries->recency = $requestedRecency;
        $impliedRecencyWindows = [];
        $asksForCurrentItems = false;
        $removedOperators = [];
        $siteDomains = [];
        $everyQueryNamedASite = true;
        $keywordChainQueries = [];
        foreach ($rawQueries as $rawQuery) {
            $normalization = WebSearchQueryNormalization::fromRawQuery((string)$rawQuery);
            $removedOperators = array_values(array_unique([...$removedOperators, ...$normalization->removedOperators]));
            if ($normalization->siteDomains === []) {
                $everyQueryNamedASite = false;
            }
            $siteDomains = array_values(array_unique([...$siteDomains, ...$normalization->siteDomains]));
            $query = $normalization->query !== '' ? $normalization->query : static::buildQueryFromSiteDomains($normalization->siteDomains);
            if ($query !== '' && !in_array($query, $callQueries->queries, true)) {
                $callQueries->queries[] = $query;
            }
            if ($normalization->isKeywordChain()) {
                $keywordChainQueries[] = $query;
            }
            $impliedRecencyWindows = [...$impliedRecencyWindows, ...static::findRecencyWindowsOfQuery((string)$rawQuery, $now)];
            if (static::namesAnotherMonthWithItsYear((string)$rawQuery, $now)) {
                $namesAnotherDate = true;
            }
            if (preg_match(static::CURRENT_ITEMS_PATTERN, (string)$rawQuery) === 1) {
                $asksForCurrentItems = true;
            }
        }
        $impliedRecencyWindows = array_values(array_unique($impliedRecencyWindows));
        $noteParts = [];
        if ($removedOperators !== []) {
            $siteDomainsApplied = $siteDomains !== [] && $requestedDomains === [] && $everyQueryNamedASite;
            if ($siteDomainsApplied) {
                $callQueries->domains = $siteDomains;
            }
            $notePart = 'CORRECTION: search operators removed from the queries (' . implode(', ', $removedOperators) . ') — the engine takes plain words';
            if ($siteDomains !== []) {
                $includedSiteDomains = [];
                foreach ($siteDomains as $siteDomain) {
                    $includedSiteDomains[] = '"' . $siteDomain . '"';
                }
                $notePart .= $siteDomainsApplied
                    ? '; `site:` was applied as `domains` this time'
                    : '; `site:` was dropped this time (`domains` applies to every query of the call: search that site in its own call)';
                $notePart .= '. Next time pass "domains": [' . implode(', ', $includedSiteDomains) . '] instead of `site:`';
            }
            $noteParts[] = $notePart;
        }
        if ($requestedRecency === null && count($impliedRecencyWindows) === 1) {
            $callQueries->recency = $impliedRecencyWindows[0];
            $noteParts[] = 'a query named a time window, so the search ran with "recency": "' . $impliedRecencyWindows[0]
                . '" — pass `recency` yourself next time';
        } elseif ($requestedRecency === null && ($impliedRecencyWindows !== [] || $asksForCurrentItems || $namesAnotherDate)) {
            $noteParts[] = 'for news and current events pass `recency` (day, week, month, year) instead of a date or year in '
                . 'the query, and give every news item its date from the page list (or "undated")';
        }
        if ($keywordChainQueries !== []) {
            $noteParts[] = 'long keyword chains find less than one short query per aspect (more than '
                . WebSearchQueryNormalization::MAX_WORDS_OF_A_SHORT_QUERY . ' words: "' . implode('", "', $keywordChainQueries) . '")';
        }
        if ($noteParts !== []) {
            $callQueries->noteForModel = 'NOTE: ' . implode('; ', $noteParts) . '.';
        }
        return $callQueries;
    }

    /**
     * The words of a query that consisted of `site:` operators only: the included domains.
     *
     * @param string[] $siteDomains
     */
    protected static function buildQueryFromSiteDomains(array $siteDomains): string
    {
        $includedDomains = [];
        foreach ($siteDomains as $siteDomain) {
            if (!str_starts_with($siteDomain, '-')) {
                $includedDomains[] = $siteDomain;
            }
        }
        return implode(' ', $includedDomains);
    }
}
