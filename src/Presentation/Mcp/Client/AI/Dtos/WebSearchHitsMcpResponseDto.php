<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\AI\Dtos;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Domain\AI\Entities\Conversations\Widgets\WebSearchResultsConversationWidget;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolResponsePlatformNoteProvider;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultUntrustedSegments;
use DDD\Domain\AI\Entities\WebSearch\WebPageExtracts;
use DDD\Domain\AI\Entities\WebSearch\WebSearchHitLists;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Domain\AI\Entities\WebSearch\WebSearchConfiguration;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Response of the `searchTheWeb` tool: the hit lists of the queries, merged into one numbered page list
 * with the engine's excerpts, and — with `extractInformationFromResultPagesWithFastLLM` — the fast model's readings of
 * the best pages. Rendered as LLM markdown (the objects render themselves: {@see WebSearchHitLists::buildLlmMarkdown()},
 * {@see WebPageExtracts::buildLlmMarkdown()}). How to answer and show the answer is the PLATFORM NOTE
 * ({@see buildPlatformNoteForModel()}), kept out of the screened result.
 */
class WebSearchHitsMcpResponseDto extends RestResponseDto implements LlmMarkdownRenderable, MCPToolResponsePlatformNoteProvider
{
    /** How to answer: the result is research material, the answer is the agent's own, its sources are only these pages. */
    public const string ANSWER_INSTRUCTION = 'Answer from these pages in your own words: every figure with its source next to it (the site name and the URL; in a table one source per row), a value with its qualifier ("about", the variant), every entry found listed or its omission said, what was not found said plainly. A derived figure only from values of ONE source, named as derived — never an estimate from figures of different sources. Cite ONLY pages listed here — never a URL from memory; copy each URL character for character.';

    /**
     * How to show it on a screen: the key statement in prose, the figures in the
     * ComposedView skeleton their shape calls for ({@see SHAPED_FIGURES_SKELETON_FIELDS_BY_SHAPE}, dictated so the model
     * fills values instead of composing), the references widget under it — never in place of the figures.
     */
    public const string WIDGET_INSTRUCTION = 'On screen: the key statement in one or two sentences — never a Markdown table. First the test, for ONE quantity estimated by different sources with different methods (how long something lasts, an average cost) — polls and other dated measurements are never such a range, see below: figures on different bases (a lifetime range for one thing, a 5-year rate for the other; studies with different methods; regional price spans; \'mostly 10–15 years\') stay prose with the range and why, under them only the references widget. Named things compared on named properties (two cars, two phones, plan options) are always the Table: its cells may be text with their qualifier (\'up to 631 km\', \'from €40,580\', \'3× 48 MP\'), the variant in the column label or the caption — never a Markdown table. When the figures have a shape, copy the ComposedView skeleton for it and fill the `<…>` parts: one row, data point or tile per figure, numbers as JSON numbers, the page of the values as each node\'s `source`; percentages set `"valueFormat":"percent"` on the Chart, money `"valueFormat":"currency"`. The prose then names at most the one or two figures the statement rests on (the leader, the biggest change) — never the rows the widget shows. Key facts of one thing, even in different units (inhabitants, area, height), are a few headline numbers → the KpiTile Grid; "a single figure" is ONE number and stays in the prose. Dated measurements of the same thing (polls by different institutes, monthly figures) are ONE basis, never a range: show the newest one complete as bars (the newest = the latest publication date on the found pages; the prose names that one poll, never a span across institutes) — every value, its publisher and date in the caption — or one row per measurement in the Table; a measurement against an earlier one (a poll against the last election, this month against last month) is the bar Chart with the change per bar. Polls, election results and other political figures: the caption names the institute or publisher, the date and, where the page gives it, the sample size ("Polling institute / broadcaster, 20 Oct 2026 · 1,207 respondents"), and the `source` links the page. A set the found pages cover only partly (a top 3 where the question is about all parties) is not the answer: search once more for a page that lists the whole set. A series over time with gaps (no value for the start year) is the same: one more search for a page with the yearly values; still gaps → the line Chart of the years found, the caption naming the missing ones — never prose instead.';

    /**
     * The line before the references block: it closes every answer written from the pages — one in plain prose as
     * much as one with figures — lists the pages and never stands in for the figures.
     */
    public const string REFERENCES_INSTRUCTION = 'ALWAYS last, on its own line — under an answer in plain prose exactly as under figures; links inside the prose do not replace it: the references widget with the pages you used. It lists sources and never replaces the ComposedView:';

    /** @var string[] The placeholder source of every skeleton node: the page a value comes from. */
    public const array SKELETON_SOURCE = ['name' => '<site>', 'url' => '<page url>'];

    /**
     * The dictated ComposedView skeletons by the shape of the figures: label → block fields.
     * Each one reads as a VALID ComposedView ({@see buildShapedFiguresSkeletonBlocks()}, `WebSearchMCPPackageResearchToolsTest`);
     * no row key is `value` — a row object with a `value` key reads as a researched value without a source.
     */
    public const array SHAPED_FIGURES_SKELETON_FIELDS_BY_SHAPE = [
        '2–5 things compared, the `source` one entry per page the values come from' => ['title' => '<title>', 'view' => ['type' => 'Table', 'columns' => [['key' => 'item', 'label' => '<thing>'], ['key' => 'figure', 'label' => '<figure>']], 'rows' => [['item' => '<name>', 'figure' => '<value>']], 'source' => [self::SKELETON_SOURCE]]],
        'a series over time' => ['title' => '<title>', 'view' => ['type' => 'Chart', 'kind' => 'line', 'x' => 'year', 'series' => [['field' => 'figure', 'label' => '<what>']], 'data' => [['year' => '<year>', 'figure' => '<number>']], 'source' => self::SKELETON_SOURCE]],
        'shares or a ranking' => ['title' => '<title>', 'view' => ['type' => 'Chart', 'kind' => 'bar', 'x' => 'category', 'series' => [['field' => 'figure', 'label' => '<what>']], 'data' => [['category' => '<name>', 'figure' => '<number>']], 'source' => self::SKELETON_SOURCE]],
        'a measurement against an earlier one' => ['title' => '<title>', 'view' => ['type' => 'Chart', 'kind' => 'bar', 'horizontal' => true, 'x' => 'category', 'series' => [['field' => 'figure', 'label' => '<what>']], 'data' => [['category' => '<name>', 'figure' => '<number>', 'change' => '<signed number>']], 'deltaField' => 'change', 'deltaUnit' => 'pp', 'deltaLabel' => '<Right: change in percentage points since …>', 'caption' => '<publisher>, <date> · <sample> respondents', 'source' => self::SKELETON_SOURCE]],
        'a few headline numbers' => ['title' => '<title>', 'view' => ['type' => 'Grid', 'columns' => 3, 'children' => [['type' => 'KpiTile', 'label' => '<what>', 'value' => '<number>', 'source' => self::SKELETON_SOURCE]]]],
    ];

    /** Whether the note asks for the widget (false on a phone call — no screen). */
    public bool $showAnswerAsWidget = true;

    /** @var WebSearchHitLists|null The per-query hit lists, in the order of the queries. */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?WebSearchHitLists $hitLists = null;

    /** @var WebPageExtracts|null The fast model's readings of the best result pages; null when not asked for. */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?WebPageExtracts $resultPageExtracts = null;

    /** The extraction task the result pages were read for; null without extraction. */
    public ?string $extractionTask = null;

    /** The leading corrective line of a repeated single-query search in this turn; null otherwise. */
    public ?string $batchAdoptionNote = null;

    /** What was changed in the queries (operators removed, `site:` applied as `domains`) or a keyword-chain note; null otherwise. */
    public ?string $queryNote = null;

    /** Characters the merged page list may take ({@see WebSearchHitLists::buildLlmMarkdown()}). */
    public int $renderBudgetChars = 48000;

    /** Characters of excerpts per page. */
    public int $snippetCharCapPerHit = 1800;

    /** Characters per page reading. */
    public int $extractCharCapPerPage = 2400;

    public function toLlmMarkdown(): string
    {
        $sections = [($this->hitLists ?? new WebSearchHitLists())->buildLlmMarkdown($this->renderBudgetChars, $this->snippetCharCapPerHit)];
        if ($this->resultPageExtracts !== null) {
            $sections[] = $this->resultPageExtracts->buildLlmMarkdown((string)$this->extractionTask, $this->extractCharCapPerPage);
        }
        return implode("\n\n", $sections);
    }

    public function buildPlatformNoteForModel(): ?string
    {
        $noteLines = [];
        if ($this->batchAdoptionNote !== null && $this->batchAdoptionNote !== '') {
            $noteLines[] = $this->batchAdoptionNote;
        }
        if ($this->queryNote !== null && $this->queryNote !== '') {
            $noteLines[] = $this->queryNote;
        }
        $noteLines[] = ToolResultUntrustedSegments::READING_RULE;
        $noteLines[] = self::buildAnswerInstruction($this->showAnswerAsWidget);
        return implode("\n", $noteLines);
    }

    /** How to answer a research result, and on a screen how to show it. */
    public static function buildAnswerInstruction(bool $showAnswerAsWidget): string
    {
        if (!$showAnswerAsWidget) {
            return self::ANSWER_INSTRUCTION;
        }
        return implode("\n", [
            self::ANSWER_INSTRUCTION,
            self::WIDGET_INSTRUCTION,
            ...self::buildShapedFiguresSkeletonBlocks(),
            self::REFERENCES_INSTRUCTION,
            self::buildWidgetSkeletonBlock(),
        ]);
    }

    /**
     * The ComposedView skeleton of each shape, labelled, each block rendered through the widget class from the fields
     * its tree reader kept.
     *
     * @return string[]
     */
    public static function buildShapedFiguresSkeletonBlocks(): array
    {
        $skeletonLines = [];
        foreach (self::SHAPED_FIGURES_SKELETON_FIELDS_BY_SHAPE as $shapeLabel => $skeletonFields) {
            $composedViewConversationWidget = new ComposedViewConversationWidget();
            $composedViewConversationWidget->applyTreeReading(ComposedViewTreeReading::fromWidgetFields($skeletonFields));
            $skeletonLines[] = $shapeLabel . ':';
            $skeletonLines[] = $composedViewConversationWidget->buildWidgetBlock();
        }
        return $skeletonLines;
    }

    /** The `WebSearchResults` references block the model fills, dictated through the widget class. */
    public static function buildWidgetSkeletonBlock(): string
    {
        $webSearchResultsConversationWidget = new WebSearchResultsConversationWidget();
        $webSearchResultsConversationWidget->query = '<what was researched>';
        $providerName = trim((string)WebSearchConfiguration::findValue('hits.providerName', ''));
        $webSearchResultsConversationWidget->provider = $providerName !== '' ? $providerName : null;
        $webSearchResultsConversationWidget->sources = [['url' => '<a page you used>', 'title' => '<its title>']];
        return $webSearchResultsConversationWidget->buildWidgetBlock();
    }
}
