<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The ONE normalisation of a tool-call comparison: everything is compared raw — the clock and the minted tokens are
 * pinned — except the database auto-increment ids two runs of the same call necessarily get different:
 * - the harness's own rows (the run's agent space, the fixture conversations and their owner rows), declared by the
 *   harness: `<conversation:setup>`, `<conversation:setup/row#2>`;
 * - the ids a case MINTED (above its tables' high-water marks, read around the case): `<table#n>`, numbered by FIRST
 *   APPEARANCE in the compared output across the whole run, so a later case that references a row an earlier case
 *   created reads the same placeholder in both runs. A declared id of a row another process created at the same time
 *   takes a number only where its value appears in the output — and then as a placeholder of a row the run did not
 *   create, which the other run does not repeat. The session therefore declares the ids of a table it read without a
 *   scope only when the same call inserted into that table
 *   ({@see ToolCallComparisonSession::buildDeclaredMintedIdsByTable()}). An id two tables minted reads `<id#n>`.
 *
 * Where an id is replaced, listed per field:
 * - a value under a key named `id`, or ending in `Id`, `_id` or `ID` (also the `{column, value}` pairs of a statement's
 *   condition, read under the column's name), int or digit string;
 * - inside any string (result text, message text, a JSON column, a request body) — {@see normalizeText()}:
 *   - a whole number of at least {@see MIN_TEXT_ID_DIGITS} digits that is one of those ids (a sentence's full stop
 *     after it does not hide it: `conversation #711166.`; a decimal fraction does);
 *   - one of those ids of ANY length where the text itself says it is an id: the value of an id-named key of JSON
 *     written into the string (`"runId":1926`, also escaped `\"buildRunId\":1926`), a number right after `#`
 *     (`build run #1926`) and a number glued to a word and closed by `_` (`run1934_…`, a client key). A small table's
 *     ids never reach six digits, so the length floor alone would leave every reference to them raw.
 * The script's opaque token keys ({@see declareOpaqueTokenKeys()}) hold a value derived from a harness id: each
 * distinct value reads `<key#n>`, numbered by first appearance per key, so two runs compare WHICH values are equal (a
 * retry reusing a key, a fresh key per request), never the bytes.
 * Every other value — moments, tokens, plain numbers, pre-existing ids, a short number in free text — stays raw.
 * Stateful (the numbering), one object per run.
 *
 * @phpstan-consistent-constructor
 */
class ToolCallComparisonIdPlaceholders extends ValueObject
{
    /** A number in free text shorter than this is never read as an id. */
    public const int MIN_TEXT_ID_DIGITS = 6;

    /** @var string[] id → placeholder, for the rows the harness created itself */
    public array $harnessPlaceholdersById = [];

    /** @var string[] keys whose value is an opaque token derived from a harness id (the script's `opaqueTokenKeys`) */
    public array $opaqueTokenKeys = [];

    /** @var array minted id → the tables that minted it (a counter cache of the run, never returned) */
    protected array $mintingTablesById = [];

    /** @var string[] minted id → its placeholder, assigned at first appearance */
    protected array $mintedPlaceholdersById = [];

    /** @var int[] per family: how many placeholders it handed out */
    protected array $placeholderCountsByFamily = [];

    /** @var array opaque token key → token → its placeholder, assigned at first appearance (a counter cache, never returned) */
    protected array $opaqueTokenPlaceholdersByKey = [];

    public function declareHarnessId(int|string $id, string $placeholder): void
    {
        $this->harnessPlaceholdersById[(string)$id] = $placeholder;
    }

    /**
     * Declares the ids a case minted, per table (read above the marks taken before the case).
     *
     * @param array<string, int[]> $mintedIdsByTable table → the ids it minted
     */
    public function declareMintedIds(array $mintedIdsByTable): void
    {
        foreach ($mintedIdsByTable as $table => $mintedIds) {
            foreach ($mintedIds as $mintedId) {
                $mintedIdText = (string)$mintedId;
                if (isset($this->harnessPlaceholdersById[$mintedIdText])) {
                    continue;
                }
                if (!in_array((string)$table, $this->mintingTablesById[$mintedIdText] ?? [], true)) {
                    $this->mintingTablesById[$mintedIdText][] = (string)$table;
                }
            }
        }
    }

    /**
     * Declares the keys whose value is an opaque token derived from a harness id.
     *
     * @param string[] $opaqueTokenKeys
     */
    public function declareOpaqueTokenKeys(array $opaqueTokenKeys): void
    {
        $this->opaqueTokenKeys = array_values(array_unique(array_map('strval', $opaqueTokenKeys)));
    }

    /** One opaque token as two runs compare it: `<key#n>`, the n-th distinct value of that key in the run. */
    public function normalizeOpaqueToken(string $opaqueTokenKey, string $opaqueToken): string
    {
        if (!isset($this->opaqueTokenPlaceholdersByKey[$opaqueTokenKey][$opaqueToken])) {
            $this->opaqueTokenPlaceholdersByKey[$opaqueTokenKey][$opaqueToken] = '<' . $opaqueTokenKey . '#' . (count($this->opaqueTokenPlaceholdersByKey[$opaqueTokenKey] ?? []) + 1) . '>';
        }
        return $this->opaqueTokenPlaceholdersByKey[$opaqueTokenKey][$opaqueToken];
    }

    /** One id as two runs compare it: its placeholder when the harness or a case created it, else the raw id. */
    public function normalizeId(int|string $id): int|string
    {
        $idText = (string)$id;
        if (isset($this->harnessPlaceholdersById[$idText])) {
            return $this->harnessPlaceholdersById[$idText];
        }
        if (!isset($this->mintingTablesById[$idText])) {
            return $id;
        }
        if (!isset($this->mintedPlaceholdersById[$idText])) {
            $mintingTables = $this->mintingTablesById[$idText];
            $family = count($mintingTables) === 1 ? $mintingTables[0] : 'id';
            $this->placeholderCountsByFamily[$family] = ($this->placeholderCountsByFamily[$family] ?? 0) + 1;
            $this->mintedPlaceholdersById[$idText] = '<' . $family . '#' . $this->placeholderCountsByFamily[$family] . '>';
        }
        return $this->mintedPlaceholdersById[$idText];
    }

    /**
     * A free text with the declared ids replaced where the rules of the class docblock find them and the opaque tokens
     * of JSON written into it read as their placeholders. One pass: a placeholder already written (`<sites#1>`) is
     * never read again.
     */
    public function normalizeText(string $text): string
    {
        return (string)preg_replace_callback($this->buildTextPattern(), [$this, 'normalizeTextMatch'], $text, -1, $replacementCount, PREG_UNMATCHED_AS_NULL);
    }

    /**
     * The one pattern of {@see normalizeText()}, its branches in priority order: an opaque token under its key, a number
     * under a JSON key (id-named or not — the key decides in {@see normalizeTextMatch()}), a number after `#`, a
     * number glued to a word and closed by `_`, a long free number. A quote may be escaped any number of times (JSON
     * in a JSON string).
     */
    protected function buildTextPattern(): string
    {
        $quote = '\\\\*"';
        $opaqueTokenBranch = $this->opaqueTokenKeys === []
            ? ''
            : '(?<opaquePrefix>' . $quote . '(?<opaqueKey>' . implode('|', array_map('preg_quote', $this->opaqueTokenKeys, array_fill(0, count($this->opaqueTokenKeys), '/'))) . ')'
                . $quote . '\\s*:\\s*' . $quote . ')(?<opaqueToken>[^"\\\\]+)|';
        return '/' . $opaqueTokenBranch
            . '(?<keyPrefix>' . $quote . '(?<key>[A-Za-z_][A-Za-z0-9_]*)' . $quote . '\\s*:\\s*(?:' . $quote . ')?)(?<keyedNumber>\\d+)(?!\\d|\\.\\d)'
            . '|(?<=#)(?<referencedNumber>\\d+)(?!\\d|\\.\\d)'
            . '|(?<=[a-z])(?<gluedNumber>\\d+)(?=_)'
            . '|(?<=[a-z]_)(?<underscoredNumber>\\d+)(?!\\d|\\.\\d)'
            . '|(?<![\\d.])(?<longNumber>\\d{' . static::MIN_TEXT_ID_DIGITS . ',})(?!\\d|\\.\\d)/';
    }

    /**
     * A compared structure: id-named keys normalised as ids, strings as text, recursively.
     *
     * @param array $structure a stored document (decoded JSON) of the comparison
     * @return array the same document normalised
     */
    public function normalizeStructure(array $structure): array
    {
        if (isset($structure['column'], $structure['value']) && is_string($structure['column']) && count($structure) === 2) {
            $structure['value'] = $this->normalizeValue($structure['column'], $structure['value']);
            return $structure;
        }
        foreach ($structure as $key => $value) {
            $structure[$key] = $this->normalizeValue(is_string($key) ? $key : null, $value);
        }
        return $structure;
    }

    /** Whether a key names an id (`id`, `…Id`, `…_id`, `…ID`). */
    public static function isIdKey(?string $key): bool
    {
        return $key !== null && ($key === 'id' || (bool)preg_match('/(?:[a-z0-9]Id|_id|ID)$/', $key));
    }

    protected function normalizeValue(?string $key, mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->normalizeStructure($value);
        }
        if ($key !== null && in_array($key, $this->opaqueTokenKeys, true) && (is_string($value) || is_int($value)) && (string)$value !== '') {
            return $this->normalizeOpaqueToken($key, (string)$value);
        }
        if (static::isIdKey($key) && (is_int($value) || (is_string($value) && ctype_digit($value)))) {
            return $this->normalizeId($value);
        }
        if (is_string($value)) {
            return $this->normalizeText($value);
        }
        return $value;
    }

    /** @param array<int|string, string|null> $textMatch one match of {@see buildTextPattern()} */
    protected function normalizeTextMatch(array $textMatch): string
    {
        if (($textMatch['opaqueToken'] ?? null) !== null) {
            return $textMatch['opaquePrefix'] . $this->normalizeOpaqueToken((string)$textMatch['opaqueKey'], $textMatch['opaqueToken']);
        }
        if (($textMatch['keyedNumber'] ?? null) !== null) {
            $keyedNumber = $textMatch['keyedNumber'];
            $readsAsId = static::isIdKey($textMatch['key']) || strlen($keyedNumber) >= static::MIN_TEXT_ID_DIGITS;
            return $textMatch['keyPrefix'] . ($readsAsId ? (string)$this->normalizeId($keyedNumber) : $keyedNumber);
        }
        $referenceNumber = $textMatch['referencedNumber'] ?? $textMatch['gluedNumber'] ?? $textMatch['underscoredNumber'] ?? $textMatch['longNumber'] ?? $textMatch[0];
        return (string)$this->normalizeId($referenceNumber);
    }
}
