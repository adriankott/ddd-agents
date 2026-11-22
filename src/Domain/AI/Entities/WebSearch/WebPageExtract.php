<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\WebSearch;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultUntrustedSegments;
use DDD\Infrastructure\Base\DateTime\Date;

/**
 * What a fast model read off ONE web page for an extraction task: the page's status for the task, what
 * kind of source it is, the facts with their verified quotes, and the caveats the page states. The one result shape of
 * the `extractInformationFromWebPagesWithFastLLM` tool and of `searchTheWeb` with
 * `extractInformationFromResultPagesWithFastLLM`; {@see WebPageExtracts} composes the agent's view.
 */
class WebPageExtract extends ValueObject
{
    /** The page states every part of the task. */
    public const string STATUS_FOUND = 'FOUND';

    /** The page states some parts of the task. */
    public const string STATUS_PARTIAL = 'PARTIAL';

    /** The page was read and states nothing the task asks for. */
    public const string STATUS_NOT_ON_PAGE = 'NOT_ON_PAGE';

    /** The page could not be read; the facts come from the search engine's excerpts of it. */
    public const string STATUS_SNIPPET_ONLY = 'SNIPPET_ONLY';

    /** The page could not be read and no excerpt stated anything; no model call was made for it, or it found nothing. */
    public const string STATUS_UNREADABLE = 'UNREADABLE';

    /** The page was read but the model's answer was unusable (no JSON, a timeout, a refusal). */
    public const string STATUS_EXTRACTION_FAILED = 'EXTRACTION_FAILED';

    public const array STATUSES_FROM_THE_EXTRACTOR = [self::STATUS_FOUND, self::STATUS_PARTIAL, self::STATUS_NOT_ON_PAGE];

    /** Joins the excerpts for the quote check; the normalization keeps the NUL, so no quote spans two excerpts. */
    public const string SNIPPET_SEPARATOR = "\n\u{0}\n";

    /** The only form of the extractor's `asOf` that is kept: an ISO day, so the printed date reads one way only. */
    public const string AS_OF_ISO_DAY_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    public const array SOURCE_KINDS = ['manufacturer', 'test_report', 'spec_database', 'news', 'dealer', 'forum', 'official', 'other'];

    public ?string $url = null;

    public ?string $title = null;

    /** One of the STATUS_* constants. */
    public string $status = self::STATUS_UNREADABLE;

    /** One of {@see SOURCE_KINDS}; null when the extractor did not say. */
    public ?string $sourceKind = null;

    /**
     * The date the page states for its content ("as of", "updated") as an ISO day `YYYY-MM-DD`, as the extractor read
     * it; null when none or when the extractor's answer is no real ISO day ({@see buildIsoDayOrNull()}).
     */
    public ?string $asOf = null;

    public ?WebPageExtractFacts $facts = null;

    /** @var string[] Limits the page itself states for its values (test conditions, a market, a model year). */
    public array $caveats = [];

    /** @var string[] The parts of the task the page does not answer, as the extractor names them. */
    public array $unansweredParts = [];

    /** Why the page could not be read or the extraction failed, in plain words; null otherwise. */
    public ?string $failureReason = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic(WebPageMarkdown::canonicalUrlKey((string)($this->url ?? '')));
    }

    /** An extract for a page nobody could read: no model call, the crawler's reason kept. */
    public static function fromUnreadablePage(string $url, ?string $title, string $failureReason): static
    {
        $webPageExtract = static::newInstance();
        $webPageExtract->url = $url;
        $webPageExtract->title = $title;
        $webPageExtract->status = self::STATUS_UNREADABLE;
        $webPageExtract->failureReason = $failureReason;
        return $webPageExtract;
    }

    /**
     * Reads the extractor's JSON answer onto this extract and verifies every fact's quote against the page text and
     * the search excerpts ({@see WebPageExtractFact::verifyAgainstPageAndSnippets()}). An answer that is no JSON
     * object marks the extract {@see STATUS_EXTRACTION_FAILED}. Without page text a read with facts becomes
     * {@see STATUS_SNIPPET_ONLY} and one without facts {@see STATUS_UNREADABLE}; with page text, no facts means
     * {@see STATUS_NOT_ON_PAGE} whatever the model claimed.
     *
     * @param string[] $snippets
     */
    public function applyExtractorAnswer(string $answerText, ?string $pageText, array $snippets): void
    {
        $answerText = trim($answerText);
        $answerText = (string)preg_replace('/^```(?:json)?\s*|\s*```$/', '', $answerText);
        $answer = json_decode($answerText);
        if (!is_object($answer)) {
            $this->status = self::STATUS_EXTRACTION_FAILED;
            $this->failureReason = 'the fast model sent no readable answer';
            $this->facts = new WebPageExtractFacts();
            return;
        }
        // a NUL line between the excerpts: a quote never counts as found across two unrelated excerpts
        $snippetText = implode(self::SNIPPET_SEPARATOR, $snippets);
        $webPageExtractFacts = new WebPageExtractFacts();
        foreach ((array)($answer->facts ?? []) as $answerEntry) {
            if (!is_object($answerEntry)) {
                continue;
            }
            $webPageExtractFact = WebPageExtractFact::fromExtractorAnswerEntry($answerEntry);
            if ($webPageExtractFact === null) {
                continue;
            }
            $webPageExtractFact->verifyAgainstPageAndSnippets($pageText, $snippetText);
            $webPageExtractFacts->add($webPageExtractFact);
        }
        $this->facts = $webPageExtractFacts;
        $sourceKind = strtolower(trim((string)($answer->sourceKind ?? '')));
        $this->sourceKind = in_array($sourceKind, self::SOURCE_KINDS, true) ? $sourceKind : null;
        $this->asOf = self::buildIsoDayOrNull((string)($answer->asOf ?? ''));
        $this->caveats = self::buildCleanTextList((array)($answer->caveats ?? []));
        $this->unansweredParts = self::buildCleanTextList((array)($answer->notAnswered ?? []));
        $hasFacts = $webPageExtractFacts->count() > 0;
        if (trim((string)$pageText) === '') {
            // read from the excerpts: the crawl's reason set by the service stays
            $this->status = $hasFacts ? self::STATUS_SNIPPET_ONLY : self::STATUS_UNREADABLE;
            return;
        }
        $this->failureReason = null;
        if (!$hasFacts) {
            $this->status = self::STATUS_NOT_ON_PAGE;
            return;
        }
        // FOUND only when the model says the page answers the whole task; facts under any other claim are partial
        $claimedStatus = strtoupper(trim((string)($answer->status ?? '')));
        $this->status = $claimedStatus === self::STATUS_FOUND ? self::STATUS_FOUND : self::STATUS_PARTIAL;
    }

    /**
     * `$asOfText` when it is a real ISO day (`YYYY-MM-DD`, a day that exists in the calendar), else null: a date the
     * extractor copied in another form ("09/11/26") reads two ways and is never printed.
     */
    public static function buildIsoDayOrNull(string $asOfText): ?string
    {
        $asOfText = trim($asOfText);
        if (preg_match(self::AS_OF_ISO_DAY_PATTERN, $asOfText) !== 1) {
            return null;
        }
        $asOfDate = Date::fromString($asOfText);
        if (!$asOfDate instanceof Date || $asOfDate->format(Date::DATE) !== $asOfText) {
            return null;
        }
        return $asOfText;
    }

    /** @return string[] the non-empty trimmed texts of an answer list, in order */
    protected static function buildCleanTextList(array $answerTexts): array
    {
        $cleanTexts = [];
        foreach ($answerTexts as $answerText) {
            $answerText = trim((string)$answerText);
            if ($answerText !== '') {
                $cleanTexts[] = $answerText;
            }
        }
        return $cleanTexts;
    }

    /** Whether this extract may be kept for the hour: only a real reading of a real page. */
    public function isCacheable(): bool
    {
        return in_array($this->status, self::STATUSES_FROM_THE_EXTRACTOR, true);
    }

    /** Whether the extract carries facts the agent can use. */
    public function hasFacts(): bool
    {
        return ($this->facts?->count() ?? 0) > 0;
    }

    /** The source tag of this page's facts: its host without `www.` and the reading's number (`example.com, Reading 2`). */
    public function buildSourceLabel(int $sourceNumber): string
    {
        $host = strtolower((string)(parse_url((string)$this->url, PHP_URL_HOST) ?: ''));
        $host = (string)preg_replace('/^www\./', '', $host);
        return ($host !== '' ? $host . ', ' : '') . 'Reading ' . $sourceNumber;
    }

    /**
     * The agent's view of this page: `Reading n: title — url` (numbered apart from a search's `[n]` page list), a line with the status, the kind of source and its date, the
     * facts one per line, the caveats; cut at `$charCap` characters with a visible marker.
     */
    public function buildLlmMarkdownBlock(int $sourceNumber, int $charCap): string
    {
        $title = trim((string)($this->title ?? ''));
        $lines = ['Reading ' . $sourceNumber . ': ' . ($title !== '' ? $title . ' — ' : '') . $this->url];
        $statusFacts = [$this->status === self::STATUS_SNIPPET_ONLY
            ? ($this->failureReason !== null ? $this->failureReason . ', ' : 'the page could not be read, ') . 'read from the search excerpts'
            : strtolower(str_replace('_', ' ', $this->status))];
        if ($this->sourceKind !== null) {
            $statusFacts[] = str_replace('_', ' ', $this->sourceKind);
        }
        if ($this->asOf !== null) {
            $statusFacts[] = 'as of ' . $this->asOf;
        }
        $lines[] = implode(' · ', $statusFacts);
        $sourceLabel = $this->buildSourceLabel($sourceNumber);
        foreach ($this->facts?->getElements() ?? [] as $webPageExtractFact) {
            $lines[] = $webPageExtractFact->buildLlmMarkdownLine($sourceLabel);
        }
        if ($this->caveats !== []) {
            $lines[] = 'Caveats: ' . implode('; ', $this->caveats);
        }
        if ($this->unansweredParts !== []) {
            $lines[] = 'Not on this page: ' . implode('; ', $this->unansweredParts);
        }
        $block = implode("\n", $lines);
        if (mb_strlen($block) > $charCap) {
            $block = rtrim(mb_substr($block, 0, $charCap)) . ' [cut]';
        }
        // read off a third-party page — marked after the cut, so the closing mark is never cut away
        return ToolResultUntrustedSegments::wrap((string)$this->url, $block);
    }
}
