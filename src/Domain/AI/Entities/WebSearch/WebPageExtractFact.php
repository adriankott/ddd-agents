<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\WebSearch;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * ONE fact a fast model read off a web page (or the search excerpt of it) for an extraction task: the
 * value, what exactly it applies to, how it was obtained, and the verbatim quote it stands in. The platform checks the
 * quote against the text it came from ({@see verifyAgainstPageAndSnippets()}); a fact whose quote is not found stays, marked
 * — information for the agent, not a refusal.
 */
class WebPageExtractFact extends ValueObject
{
    public const string BASIS_MEASURED = 'measured';
    public const string BASIS_MANUFACTURER = 'manufacturer';
    public const string BASIS_CALCULATED = 'calculated';
    public const string BASIS_UNCLEAR = 'unclear';

    public const array BASES = [self::BASIS_MEASURED, self::BASIS_MANUFACTURER, self::BASIS_CALCULATED, self::BASIS_UNCLEAR];

    /** The fact was read from the crawled page. */
    public const string EVIDENCE_PAGE = 'page';

    /** The page could not be read; the fact was read from the search engine's excerpt of it. */
    public const string EVIDENCE_SNIPPET = 'snippet';

    /** What the fact is, in the words of the task ("opening hours on Sundays", "monthly price of the Basic plan"). */
    public ?string $what = null;

    /** The value as the source states it, with its unit and any qualifier ("rund 10 s", "ab 39 €"). */
    public ?string $value = null;

    /** Exactly what the value applies to as the source says it (model, variant, year, place). */
    public ?string $appliesTo = null;

    /** One of {@see BASES}. */
    public string $basis = self::BASIS_UNCLEAR;

    /** A verbatim passage of the source containing the value, in the source's language. */
    public ?string $quote = null;

    /** {@see EVIDENCE_PAGE} or {@see EVIDENCE_SNIPPET}. */
    public string $evidenceKind = self::EVIDENCE_PAGE;

    /** Whether the quote occurs in the evidence and carries every number of the value ({@see isQuoteVerifiedAgainst()}). */
    public bool $quoteVerified = false;

    /** A fact from one entry of the extractor's JSON answer; null for an entry without a value. */
    public static function fromExtractorAnswerEntry(object $answerEntry): ?static
    {
        $value = trim((string)($answerEntry->value ?? ''));
        if ($value === '') {
            return null;
        }
        $webPageExtractFact = static::newInstance();
        $webPageExtractFact->what = trim((string)($answerEntry->what ?? '')) ?: null;
        $webPageExtractFact->value = $value;
        $webPageExtractFact->appliesTo = trim((string)($answerEntry->appliesTo ?? '')) ?: null;
        $basis = strtolower(trim((string)($answerEntry->basis ?? '')));
        $webPageExtractFact->basis = in_array($basis, self::BASES, true) ? $basis : self::BASIS_UNCLEAR;
        $webPageExtractFact->quote = trim((string)($answerEntry->quote ?? '')) ?: null;
        return $webPageExtractFact;
    }

    /**
     * Sets {@see $quoteVerified} and {@see $evidenceKind}: the quote is looked up in the page text first, then in the
     * search excerpts; the first text that holds it is the fact's evidence. A quote found in neither stays unverified,
     * its evidence kind the text the extractor mainly read (the page when there was one).
     */
    public function verifyAgainstPageAndSnippets(?string $pageText, string $snippetText): void
    {
        $hasPageText = trim((string)$pageText) !== '';
        if ($hasPageText && $this->isQuoteVerifiedAgainst((string)$pageText)) {
            $this->evidenceKind = self::EVIDENCE_PAGE;
            $this->quoteVerified = true;
            return;
        }
        if (trim($snippetText) !== '' && $this->isQuoteVerifiedAgainst($snippetText)) {
            $this->evidenceKind = self::EVIDENCE_SNIPPET;
            $this->quoteVerified = true;
            return;
        }
        $this->evidenceKind = $hasPageText ? self::EVIDENCE_PAGE : self::EVIDENCE_SNIPPET;
        $this->quoteVerified = false;
    }

    /**
     * Whether the quote stands in `$evidenceText`: its parts occur IN ORDER (an ellipsis the model inserted splits the
     * quote; parts under six characters count only when they carry a digit), and every number of the value occurs in
     * the quote (a value "999 €" against a quote "100 €" fails).
     */
    public function isQuoteVerifiedAgainst(string $evidenceText): bool
    {
        $quote = (string)($this->quote ?? '');
        return $quote !== ''
            && self::quoteOccursInOrderIn($quote, $evidenceText)
            && self::valueNumbersOccurInQuote((string)($this->value ?? ''), $quote);
    }

    /** Whether the parts of `$quote` occur in `$evidenceText` in the given order (after {@see normalizeForQuoteMatch()}). */
    public static function quoteOccursInOrderIn(string $quote, string $evidenceText): bool
    {
        $normalizedEvidence = self::normalizeForQuoteMatch($evidenceText);
        $quoteParts = [];
        foreach (preg_split('/\.\.\.|\x{2026}/u', $quote) ?: [] as $rawPart) {
            $part = self::normalizeForQuoteMatch($rawPart);
            if (mb_strlen($part) >= 6 || ($part !== '' && preg_match('/\d/', $part) === 1)) {
                $quoteParts[] = $part;
            }
        }
        if ($quoteParts === []) {
            return false;
        }
        $searchFrom = 0;
        foreach ($quoteParts as $part) {
            $foundAt = mb_strpos($normalizedEvidence, $part, $searchFrom);
            if ($foundAt === false) {
                return false;
            }
            $searchFrom = $foundAt + mb_strlen($part);
        }
        return true;
    }

    /** Whether every number of `$value` occurs in `$quote` (separators ignored); a value without digits passes. */
    public static function valueNumbersOccurInQuote(string $value, string $quote): bool
    {
        $valueNumbers = self::extractNumberTokens($value);
        if ($valueNumbers === []) {
            return true;
        }
        $quoteNumbers = self::extractNumberTokens($quote);
        foreach ($valueNumbers as $valueNumber) {
            // a whole number of the quote, never a digit run inside a longer one ("10" is not in "2010")
            if (!in_array($valueNumber, $quoteNumbers, true)) {
                return false;
            }
        }
        return true;
    }

    /** Case, dashes, ß, markdown links, list markers and table/emphasis markup folded away; whitespace collapsed. */
    public static function normalizeForQuoteMatch(string $text): string
    {
        $text = mb_strtolower($text);
        $text = str_replace(["\u{00a0}", "\u{202f}", 'ß'], [' ', ' ', 'ss'], $text);
        $text = (string)preg_replace('/[\x{2010}-\x{2015}\x{2212}]/u', '-', $text);
        $text = (string)preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $text);
        $text = (string)preg_replace('/(^|\n)\s*(?:[-*+\x{2022}\x{00b7}]|\d+\.)\s+/u', '$1', $text);
        $text = (string)preg_replace('/[|*#_`\x{2022}\x{00b7}]+/u', ' ', $text);
        $text = (string)preg_replace('/\\\\(.)/u', '$1', $text);
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * One line for the agent: `- what: value (applies to …; basis) — "quote" [source: site]`, unverified quotes
     * marked. `$sourceLabel` (the page's site) closes the line, so the source travels with the figure into a sentence or
     * a table row.
     */
    public function buildLlmMarkdownLine(?string $sourceLabel = null): string
    {
        $details = array_filter([
            $this->appliesTo !== null ? 'applies to ' . $this->appliesTo : null,
            $this->basis !== self::BASIS_UNCLEAR ? $this->basis : null,
        ]);
        $line = '- ' . ($this->what !== null ? $this->what . ': ' : '') . $this->value . ($details !== [] ? ' (' . implode('; ', $details) . ')' : '');
        if ($this->quote !== null) {
            $line .= ' — "' . $this->quote . '"' . ($this->evidenceKind === self::EVIDENCE_SNIPPET ? ' [from the search excerpt]' : '') . ($this->quoteVerified ? '' : ' [quote not found in the source text]');
        }
        if ($sourceLabel !== null && $sourceLabel !== '') {
            $line .= ' [source: ' . $sourceLabel . ']';
        }
        return $line;
    }

    /** @return string[] the numbers of a text, separators removed ("1.500" → "1500", "3,6" → "36") */
    protected static function extractNumberTokens(string $text): array
    {
        if (preg_match_all('/\d+(?:[.,\s]\d+)*/u', $text, $matches) < 1) {
            return [];
        }
        $numberTokens = [];
        foreach ($matches[0] as $token) {
            $numberTokens[] = (string)preg_replace('/[.,\s]/u', '', $token);
        }
        return $numberTokens;
    }
}
