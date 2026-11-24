<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages\LlmRendering;

use DDD\Domain\Base\Entities\Entity;
use DDD\Infrastructure\Base\DateTime\Date;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * How a value of a record reads in a result of the admin agent's tools: one place for the rules, so a card, a list
 * line and a "was …, now …" line show the same value the same way.
 *
 * - A text longer than {@see self::MAX_INLINE_TEXT_LENGTH} characters never appears whole: a card shows its
 *   descriptor line ({@see self::describeTranslatableText()}); the owner's bar (2026-11-19) is that no record update
 *   makes the agent read or emit a long text.
 * - Translations are stored per `<languageCode>::<writingStyle>` (e.g. `de::FORMAL`); a result names the language
 *   code only, with the writing style where a language carries more than one.
 */
class LlmTextRendering
{
    /** @var int A text up to this length is shown whole, a longer one as a descriptor line */
    public const int MAX_INLINE_TEXT_LENGTH = 200;

    /** @var int The characters of a long text a descriptor line shows */
    public const int DESCRIPTOR_PREVIEW_LENGTH = 80;

    /** @var string What a value reads as when the record stores none */
    public const string NOT_SET = 'not set';

    /** @var string The separator of a translation index: `<languageCode>::<writingStyle>` */
    public const string TRANSLATION_INDEX_SEPARATOR = ':';

    /**
     * A stored value as a result shows it: a date as Y-m-d, a switch as yes / no, a text in double quotes and cut to
     * the inline length, nothing stored as "not set".
     *
     * @param mixed $value
     * @return string
     */
    public static function displayValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return self::NOT_SET;
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if ($value instanceof Date || $value instanceof DateTime) {
            return (string)$value;
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        return '"' . self::shorten(self::toSingleLine((string)$value), self::MAX_INLINE_TEXT_LENGTH) . '"';
    }

    /**
     * @param string $text
     * @param int $maxLength
     * @return string The text, cut to $maxLength characters with "…" when it was longer
     */
    public static function shorten(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $maxLength)) . '…';
    }

    /**
     * A size in bytes as a result shows it: B, KB, MB or GB with one decimal (1 KB = 1,024 bytes).
     * Examples: `840 B` · `12.4 KB` · `1.5 GB`
     *
     * @param int|null $bytes
     * @return string
     */
    public static function formatBytes(?int $bytes): string
    {
        if ($bytes === null) {
            return self::NOT_SET;
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float)max(0, $bytes);
        $unitIndex = 0;
        while ($value >= 1024 && $unitIndex < count($units) - 1) {
            $value /= 1024;
            $unitIndex++;
        }
        return $unitIndex === 0 ? $bytes . ' B' : number_format($value, 1, '.', '') . ' ' . $units[$unitIndex];
    }

    /**
     * @param string $text
     * @return string The text on one line (a line of a result never breaks inside a value)
     */
    public static function toSingleLine(string $text): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * A short identifier a client sent (device model, OS version, app version, a reason code), reduced so it can carry
     * no sentence: ASCII letters, digits and `. , _ - + / ( )` with single spaces, at most $maxLength characters. A tool
     * that renders only such identifiers of client data can declare its content trusted.
     * @param string $text
     * @param int $maxLength
     * @return string
     */
    public static function toClientIdentifier(string $text, int $maxLength = 40): string
    {
        $identifier = (string)preg_replace('/[^A-Za-z0-9.,_\-+\/() ]/', '', $text);
        return mb_substr(static::toSingleLine($identifier), 0, $maxLength);
    }

    /**
     * The stored translations of a translatable property, per language code. Where a language stores more than one
     * writing style, the key is `<languageCode> <writingStyle>`.
     *
     * @param Entity $entity An entity with the TranslatableTrait
     * @param string $propertyName
     * @return string[] Text per language (key: language code, or language code and writing style); empty texts left out
     */
    public static function getTextsByLanguage(Entity $entity, string $propertyName): array
    {
        if (!method_exists($entity, 'getTranslationsForProperty')) {
            return [];
        }
        $translations = $entity->getTranslationsForProperty($propertyName) ?? [];
        $writingStylesByLanguageCode = [];
        foreach ($translations as $translationIndex => $text) {
            if (!is_string($text) || trim($text) === '') {
                continue;
            }
            [$languageCode, $writingStyle] = self::splitTranslationIndex((string)$translationIndex);
            $writingStylesByLanguageCode[$languageCode][$writingStyle] = $text;
        }
        $textsByLanguage = [];
        foreach ($writingStylesByLanguageCode as $languageCode => $textsByWritingStyle) {
            foreach ($textsByWritingStyle as $writingStyle => $text) {
                $key = count($textsByWritingStyle) > 1 ? $languageCode . ' ' . $writingStyle : (string)$languageCode;
                $textsByLanguage[$key] = $text;
            }
        }
        return $textsByLanguage;
    }

    /**
     * The parts of a translation index (`<languageCode>:<countryCode>:<writingStyle>`, e.g. `de::FORMAL` or
     * `de:at:FORMAL`).
     *
     * @param string $translationIndex
     * @return string[] The language (`de`, with a country `de-at`) and the writing style (empty when the index carries none)
     */
    public static function splitTranslationIndex(string $translationIndex): array
    {
        $parts = explode(self::TRANSLATION_INDEX_SEPARATOR, $translationIndex, 3);
        $countryCode = $parts[1] ?? '';
        return [$countryCode !== '' ? $parts[0] . '-' . $countryCode : $parts[0], $parts[2] ?? ''];
    }

    /**
     * One line for a translatable property on a card. A short text (every language within the inline length) is shown
     * in the languages it is stored in; a long text as its descriptor: the languages present with their lengths and
     * the first characters of one of them, never the text.
     *
     * Examples:
     *   motto: de "Jeder Kilometer zählt" · en "Every kilometre counts"
     *   landingPageHeroContent: long text, de 2,310 chars · en 2,184 chars; de starts "Wir radeln für saubere …"
     *
     * @param Entity $entity An entity with the TranslatableTrait
     * @param string $propertyName
     * @param int $maxLanguagesShown A short text shows at most this many languages and counts the rest
     * @return string|null The line, or null when the property stores no text
     */
    public static function describeTranslatableText(Entity $entity, string $propertyName, int $maxLanguagesShown = 2): ?string
    {
        $textsByLanguage = self::getTextsByLanguage($entity, $propertyName);
        if ($textsByLanguage === []) {
            return null;
        }
        $longestLength = 0;
        foreach ($textsByLanguage as $text) {
            $longestLength = max($longestLength, mb_strlen($text));
        }
        if ($longestLength > self::MAX_INLINE_TEXT_LENGTH) {
            $lengthParts = [];
            foreach ($textsByLanguage as $language => $text) {
                $lengthParts[] = $language . ' ' . number_format(mb_strlen($text)) . ' chars';
            }
            $firstLanguage = (string)array_key_first($textsByLanguage);
            return $propertyName . ': long text, ' . implode(' · ', $lengthParts) . '; ' . $firstLanguage . ' starts "'
                . self::shorten(self::toSingleLine($textsByLanguage[$firstLanguage]), self::DESCRIPTOR_PREVIEW_LENGTH) . '"';
        }
        $distinctTexts = array_unique($textsByLanguage);
        if (count($distinctTexts) === 1 && count($textsByLanguage) > 1) {
            return $propertyName . ': ' . self::displayValue(reset($distinctTexts)) . ' (same in ' . implode(', ', array_keys($textsByLanguage)) . ')';
        }
        $shownParts = [];
        foreach (array_slice($textsByLanguage, 0, $maxLanguagesShown, true) as $language => $text) {
            $shownParts[] = $language . ' ' . self::displayValue($text);
        }
        $hiddenLanguages = array_slice(array_keys($textsByLanguage), $maxLanguagesShown);
        return $propertyName . ': ' . implode(' · ', $shownParts)
            . ($hiddenLanguages !== [] ? ' · also in ' . implode(', ', $hiddenLanguages) : '');
    }
}
