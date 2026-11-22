<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\WebSearch;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentClock;
use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Infrastructure\Base\DateTime\Date;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The per-query answers of ONE `searchTheWeb` call, in the order of the queries ({@see WebSearchHitList}), and the
 * agent's view of them: one merged, numbered page list within a render budget.
 *
 * @property WebSearchHitList[] $elements
 * @method WebSearchHitList[] getElements()
 */
class WebSearchHitLists extends ObjectSet
{
    /**
     * The line above the page list of a search read with a recency window: the window dates the pages, not what they
     * report. It stands before the pages, so it is read before the first excerpt. It names the window by its first
     * day and today (`YYYY-MM-DD`), so "the last week" is never read as the previous calendar week; then the window
     * (`week`, `month`, …) — {@see buildRecencyWindowReadingNote()}.
     */
    public const string RECENCY_WINDOW_READING_NOTE =
        'Recency filter: these pages were PUBLISHED between %s and %s (the last %s, counted back from today) — that dates the page, not what it reports. '
        . 'Each item gets the day its page says it HAPPENED; an item whose page names no such day is undated and never news of the period the owner asked about. '
        . 'When no item is dated inside that period, the answer opens with exactly that and then names the newest dated item with its date.';

    /**
     * How far each recency window ({@see WebSearchHitListFilters::RECENCY_WINDOWS}) reaches back from today, as a date
     * modification: an hour stays inside today.
     */
    public const array RECENCY_WINDOW_DAY_MODIFICATIONS = [
        'hour' => '-0 days',
        'day' => '-1 day',
        'week' => '-7 days',
        'month' => '-1 month',
        'year' => '-1 year',
    ];

    /** Whether any query brought at least one hit. */
    public function hasAnyHit(): bool
    {
        foreach ($this->getElements() as $webSearchHitList) {
            if (($webSearchHitList->hits?->count() ?? 0) > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * The agent's view of the whole call: the numbered queries (with the mode and the number of hits, or why the
     * engine could not be asked), then — for a search with a recency window — how to date what the pages report,
     * then the merged pages within the budget, then — when pages were left out — one line saying so. Composes
     * {@see WebSearchMergedHits::buildPageListRenderingWithinBudget()}. `$today` is the day the recency window is counted
     * back from; null = today on the model's clock.
     */
    public function buildLlmMarkdown(int $renderBudgetChars, int $snippetCharCap, ?Date $today = null): string
    {
        $queryLines = [];
        foreach (array_values($this->getElements()) as $queryIndex => $webSearchHitList) {
            $status = $webSearchHitList->isAnswered()
                ? ($webSearchHitList->hits?->count() ?? 0) . ' hits'
                : 'not answered: ' . $webSearchHitList->failureReason;
            $queryLines[] = 'Query ' . ($queryIndex + 1) . ': "' . $webSearchHitList->query . '" — ' . $status;
        }
        $firstHitList = $this->first();
        $firstFilters = $firstHitList instanceof WebSearchHitList ? $firstHitList->filters : null;
        $header = 'Web search (' . ($firstFilters !== null ? $firstFilters->mode : WebSearchHitListFilters::MODE_FAST) . ')'
            . ($firstFilters?->countryCode !== null ? ', country ' . $firstFilters->countryCode : '')
            . ($firstFilters?->recencyWindow !== null ? ', last ' . $firstFilters->recencyWindow : '')
            . (($firstFilters !== null ? $firstFilters->domains : []) !== [] ? ', sites ' . implode(' ', $firstFilters->domains) : '')
            . (($firstFilters !== null ? $firstFilters->languageCodes : []) !== [] ? ', languages ' . implode(' ', $firstFilters->languageCodes) : '')
            . "\n" . implode("\n", $queryLines);
        $webSearchMergedHits = WebSearchMergedHits::fromHitLists($this);
        if ($webSearchMergedHits->count() === 0) {
            return $header . "\n\nNo pages found.";
        }
        $webSearchPageListRendering = $webSearchMergedHits->buildPageListRenderingWithinBudget($renderBudgetChars, $snippetCharCap);
        $markdown = $header
            . ($firstFilters?->recencyWindow !== null ? "\n\n" . self::buildRecencyWindowReadingNote($firstFilters->recencyWindow, $today ?? self::buildModelFacingToday()) : '')
            . "\n\nPages (" . $webSearchMergedHits->count() . '):' . "\n\n" . $webSearchPageListRendering->markdown;
        if ($webSearchPageListRendering->omittedPageCount > 0) {
            $markdown .= "\n\n" . $webSearchPageListRendering->omittedPageCount . ' more pages not shown (the result size limit) — narrow the queries to see them.';
        }
        return $markdown;
    }

    /**
     * The recency note for `$recencyWindow` counted back from `$today`: the window's first day (today minus the
     * window, {@see RECENCY_WINDOW_DAY_MODIFICATIONS}) and today, both `YYYY-MM-DD`. A month back from a day the
     * previous month does not have (31 March) is that month's last day.
     */
    public static function buildRecencyWindowReadingNote(string $recencyWindow, Date $today): string
    {
        $firstDay = clone $today;
        $firstDay->modify(self::RECENCY_WINDOW_DAY_MODIFICATIONS[$recencyWindow] ?? '-0 days');
        if ($recencyWindow === 'month' && $firstDay->format('d') !== $today->format('d')) {
            $firstDay->modify('last day of previous month');
        }
        return sprintf(self::RECENCY_WINDOW_READING_NOTE, $firstDay->format(Date::DATE), $today->format(Date::DATE), $recencyWindow);
    }

    /** Today as a day on the model's clock ({@see AgentClock::formatDay()}), the day the clock line of the prompt names. */
    public static function buildModelFacingToday(): Date
    {
        return new Date(AgentClock::formatDay(new DateTime()));
    }
}
