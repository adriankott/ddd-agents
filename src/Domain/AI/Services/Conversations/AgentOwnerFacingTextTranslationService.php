<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations;

use DDD\Domain\Base\Entities\Translatable\Translatable;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * Translates the texts the module writes for the owner outside the model's own answer: the run report, the stop reason
 * of a cut turn, the plain reply of a plan-less stall, schedule notices and schedule refusals. The key of a text is its
 * English source; a key without a translation answers the English text with its placeholders filled.
 *
 * The default looks the key up with the framework's {@see Translatable::translateKey()} (the application's translations
 * of translation keys). A count-dependent line in the plural ({@see Translatable::CONTEXT_MANY}) looks up its plural
 * English wording as a key of its own, so it never answers with the singular translation.
 *
 * An application with a translation layer of its own (another store, plural forms, product names) overrides
 * {@see translateKeyWithApplicationTranslations()} in a same-path subclass. An application that defines the global
 * function named {@see HOST_TRANSLATION_FUNCTION_NAME} keeps it: the default calls it when it exists, with the
 * arguments `context`, `languageCode`, `placeholders` and `defaultString`.
 *
 * Never throws: the texts are written on failure paths (a failed run, a budget pause, a refused resume), and an
 * unreachable translation store must not take the delivery down. A failed lookup is logged and answered in English.
 */
class AgentOwnerFacingTextTranslationService extends Service
{
    /** The global translation function an application may define; the module never defines it. */
    public const string HOST_TRANSLATION_FUNCTION_NAME = '___';

    /**
     * The text in `$languageCode`, placeholders filled.
     *
     * @param string $englishSourceKey The English source text, the translation key
     * @param string|null $languageCode The language of the owner; null = the current language
     * @param array<string, string|int|float> $placeholders `['name' => 'value']` replaces `%name%`
     * @param string|null $englishFallbackText The English text when the key has no translation; null = the key itself
     * @param string $countContext {@see Translatable::CONTEXT_ONE} or {@see Translatable::CONTEXT_MANY}
     * @return string
     */
    public function translateOwnerFacingText(
        string $englishSourceKey,
        ?string $languageCode = null,
        array $placeholders = [],
        ?string $englishFallbackText = null,
        string $countContext = Translatable::CONTEXT_ONE
    ): string {
        $englishText = Translatable::replacePlaceholders($englishFallbackText ?? $englishSourceKey, $placeholders);
        try {
            $translatedText = $this->translateKeyWithApplicationTranslations($englishSourceKey, $languageCode, $placeholders, $englishFallbackText, $countContext);
            return $translatedText !== null && $translatedText !== '' ? $translatedText : $englishText;
        } catch (Throwable $translationError) {
            try {
                DDDService::instance()->getLogger()->error(
                    'AgentOwnerFacingTextTranslationService: translation failed, answering in English: ' . $translationError->getMessage()
                );
            } catch (Throwable) {
            }
            return $englishText;
        }
    }

    /**
     * The translation of the key, or null when there is none (the caller answers the English text). Override point of
     * an application with a translation layer of its own.
     *
     * @param string $englishSourceKey
     * @param string|null $languageCode
     * @param array<string, string|int|float> $placeholders
     * @param string|null $englishFallbackText
     * @param string $countContext
     * @return string|null
     */
    protected function translateKeyWithApplicationTranslations(
        string $englishSourceKey,
        ?string $languageCode,
        array $placeholders,
        ?string $englishFallbackText,
        string $countContext
    ): ?string {
        if (function_exists(self::HOST_TRANSLATION_FUNCTION_NAME)) {
            return $this->translateKeyWithHostTranslationFunction($englishSourceKey, $languageCode, $placeholders, $englishFallbackText, $countContext);
        }
        // a plural line is looked up by its plural English wording; the singular translation never answers a plural count
        $lookupKey = $countContext === Translatable::CONTEXT_MANY && $englishFallbackText !== null ? $englishFallbackText : $englishSourceKey;
        $translatedText = Translatable::translateKey($lookupKey, $languageCode, null, null, $placeholders);
        if ($translatedText === $lookupKey || $translatedText === Translatable::replacePlaceholders($lookupKey, $placeholders)) {
            return null;
        }
        return $translatedText;
    }

    /**
     * The answer of the application's global translation function ({@see HOST_TRANSLATION_FUNCTION_NAME}).
     *
     * @param string $englishSourceKey
     * @param string|null $languageCode
     * @param array<string, string|int|float> $placeholders
     * @param string|null $englishFallbackText
     * @param string $countContext
     * @return string|null
     */
    protected function translateKeyWithHostTranslationFunction(
        string $englishSourceKey,
        ?string $languageCode,
        array $placeholders,
        ?string $englishFallbackText,
        string $countContext
    ): ?string {
        $hostTranslationFunctionName = self::HOST_TRANSLATION_FUNCTION_NAME;
        return (string)$hostTranslationFunctionName(
            $englishSourceKey,
            context: $countContext,
            languageCode: $languageCode,
            placeholders: $placeholders,
            defaultString: $englishFallbackText ?? $englishSourceKey
        );
    }
}
