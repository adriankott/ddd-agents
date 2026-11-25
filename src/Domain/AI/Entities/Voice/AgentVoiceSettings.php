<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice;

use DDD\Domain\AI\Entities\Conversations\Languages\AgentLanguageNames;
use DDD\Domain\AI\Entities\Voice\Prompts\AgentVoiceAIPromptNames;
use DDD\Domain\AI\Entities\Voice\Prompts\AgentVoicePromptText;
use DDD\Domain\Base\Entities\Translatable\Translatable;
use DDD\Domain\Base\Entities\ValueObject;
use Symfony\Component\Validator\Constraints\Length;

/**
 * How the agent SOUNDS on a call and which language it speaks: the voice, the language, whether it follows the
 * caller's language, and the owner's speaking style.
 *
 * EVERY field is nullable on purpose: null means "inherit from the layer below". The layers are merged FIELD-WISE by
 * {@see buildWithOverrideApplied()}: a layer that sets only {@see $voiceId} keeps the language and the speaking style
 * of the layer beneath it. Replacing the whole object would silently reset them. The base knows one layer, the
 * configured default ({@see buildConfiguredDefault()}).
 *
 * Consumed when a call is created: {@see $voiceId} goes into the provider session's voice,
 * {@see $languageCode}, {@see $mirrorCallerLanguage} and {@see $speakingStyle} into the instructions.
 *
 * Override points of an application's subclass at the same path:
 * - further fields (settings stored per line or per device, a provider or model choice): declare them, and copy them
 *   in {@see buildWithOverrideApplied()};
 * - an allow-list of {@see $voiceId}: redeclare the property with its constraint. The base accepts any id, because an
 *   application may register an adapter with voices of its own; an adapter never sends a voice it does not know;
 * - provider names of further families: {@see getVoiceProviderFamilyForName()};
 * - the prompts of the language sentences: {@see getFixedLanguagePromptName()},
 *   {@see getMirroredLanguagePromptName()}, {@see getMidCallLanguagePromptName()} and
 *   {@see getMidCallLanguageEnglishPromptName()}.
 */
class AgentVoiceSettings extends ValueObject
{
    /** @var string GPT-Live voice: Marin, warm, mid-pitched, female; the provider's documented default */
    public const string VOICE_MARIN = 'marin';

    /** @var string GPT-Live voice: Quartz, Australian, female */
    public const string VOICE_QUARTZ = 'quartz';

    /** @var string GPT-Live voice: Ripple, Australian, male */
    public const string VOICE_RIPPLE = 'ripple';

    /** @var string GPT-Live voice: Vesper, British, male */
    public const string VOICE_VESPER = 'vesper';

    /** @var string GPT-Live voice: Willow, Irish, female */
    public const string VOICE_WILLOW = 'willow';

    /** @var string GPT-Live voice: Stone, Irish, male */
    public const string VOICE_STONE = 'stone';

    /** @var string GPT-Live voice: Gleam, North American, female */
    public const string VOICE_GLEAM = 'gleam';

    /** @var string GPT-Live voice: Meridian, North American, male */
    public const string VOICE_MERIDIAN = 'meridian';

    /** @var string GPT-Live voice: Bossa, Brazilian Portuguese, female */
    public const string VOICE_BOSSA = 'bossa';

    /** @var string GPT-Live voice: Tempo, Brazilian Portuguese, male */
    public const string VOICE_TEMPO = 'tempo';

    /** @var string GPT-Live voice: Beacon, Filipino English, male */
    public const string VOICE_BEACON = 'beacon';

    /** @var string GPT-Live voice: Delta, Southern U.S., female */
    public const string VOICE_DELTA = 'delta';

    /** @var string GPT-Live voice: Cinder, Southern U.S., male */
    public const string VOICE_CINDER = 'cinder';

    /** @var string GPT-Live voice: Flitz, native German, male */
    public const string VOICE_FLITZ = 'flitz';

    /** @var string GPT-Live voice: Nuri, native German, female */
    public const string VOICE_NURI = 'nuri';

    /** @var string GPT-Live voice: Juni, native German, female */
    public const string VOICE_JUNI = 'juni';

    /** @var string GPT-Live voice: Sillage, native French, male */
    public const string VOICE_SILLAGE = 'sillage';

    /** @var string GPT-Live voice: Brise, native French, female */
    public const string VOICE_BRISE = 'brise';

    /** @var string GPT-Live voice: Shida, native Japanese, male */
    public const string VOICE_SHIDA = 'shida';

    /** @var string GPT-Live voice: Harema, native Japanese, female */
    public const string VOICE_HAREMA = 'harema';

    /** @var string GPT-Live voice: Noeul, native Korean, female */
    public const string VOICE_NOEUL = 'noeul';

    /** @var string GPT-Live voice: Nira, native Hindi, female */
    public const string VOICE_NIRA = 'nira';

    /** @var string[] The GPT-Live voices beside {@see self::VOICE_MARIN}: the catalog of the LIVE family; a voice of another provider is not one of them */
    public const array SELECTABLE_LIVE_VOICE_IDS = [
        self::VOICE_QUARTZ,
        self::VOICE_RIPPLE,
        self::VOICE_VESPER,
        self::VOICE_WILLOW,
        self::VOICE_STONE,
        self::VOICE_GLEAM,
        self::VOICE_MERIDIAN,
        self::VOICE_BOSSA,
        self::VOICE_TEMPO,
        self::VOICE_BEACON,
        self::VOICE_DELTA,
        self::VOICE_CINDER,
        self::VOICE_FLITZ,
        self::VOICE_NURI,
        self::VOICE_JUNI,
        self::VOICE_SILLAGE,
        self::VOICE_BRISE,
        self::VOICE_SHIDA,
        self::VOICE_HAREMA,
        self::VOICE_NOEUL,
        self::VOICE_NIRA,
    ];

    /**
     * @var string LIVE family: the MODEL drives the session. It starts and ends its own turns, holds no tools and takes
     * instructions append-only; the session worker attaches to the session and answers the model's delegations through
     * the agent. The one family the package knows.
     */
    public const string VOICE_PROVIDER_FAMILY_LIVE = 'LIVE';

    /**
     * @var string OpenAI GPT-Live (`gpt-live-1.1`), the first provider of the LIVE family, in the spelling a stored call
     * may carry. The adapter is registered as `openAiLive` in `AI.agentVoice.provider.adapters`; both spellings name
     * the same provider ({@see normalizeVoiceProviderName()}).
     */
    public const string VOICE_PROVIDER_OPENAI_LIVE = 'OPENAI_LIVE';

    /** @var string[] The provider names of the LIVE family */
    protected const array LIVE_VOICE_PROVIDER_NAMES = [self::VOICE_PROVIDER_OPENAI_LIVE];

    /** @var int The cap of {@see $speakingStyle}: one or two sentences (the Length constraint below, checked on every save) */
    public const int MAXIMUM_SPEAKING_STYLE_LENGTH = 255;

    /** @var string|null Which voice of the provider speaks. Null = inherit; the adapter then speaks with its configured default voice. */
    #[Length(max: 32)]
    public ?string $voiceId = null;

    /** @var string|null ISO-639-1 language the agent speaks in ('de', 'en', …). Null = inherit. */
    #[Length(max: 8)]
    public ?string $languageCode = null;

    /**
     * @var bool|null True: the agent answers in whatever language the caller speaks and falls back to
     * {@see $languageCode} only when it cannot tell. The voice model HEARS the language, so this serves an owner who
     * speaks more than one. Null = inherit.
     */
    public ?bool $mirrorCallerLanguage = null;

    /**
     * @var string|null Free-text delivery direction appended to the call's instructions ("calm and matter-of-fact",
     * "warm, with some pace"). Capped: one or two sentences by contract, so that a pasted paragraph fails as clean
     * validation instead of bloating the instructions of every call. Null = inherit.
     */
    #[Length(max: self::MAXIMUM_SPEAKING_STYLE_LENGTH)]
    public ?string $speakingStyle = null;

    /**
     * The language spoken when nothing above states one: the LAST resort of the chain, the default language of the
     * application's translations. What normally answers the question is the configured language of the voice channel
     * or the language of the call's conversation ({@see buildConfiguredDefault()}).
     */
    public static function getPlatformDefaultLanguageCode(): string
    {
        return Translatable::getDefaultLanguageCode();
    }

    /**
     * WHICH family a provider name belongs to; null for a name of no family the package knows. Null as the name
     * resolves to the configured default provider (`AI.agentVoice.provider.default`). Checked before anything that is
     * specific to one family, so that an adapter of one family never serves a call of another.
     */
    public static function getVoiceProviderFamilyForName(?string $voiceProviderName): ?string
    {
        $resolvedProviderName = static::normalizeVoiceProviderName(
            $voiceProviderName ?? AgentVoiceConfiguration::current()->defaultProviderName
        );
        foreach (static::LIVE_VOICE_PROVIDER_NAMES as $liveVoiceProviderName) {
            if ($resolvedProviderName === static::normalizeVoiceProviderName($liveVoiceProviderName)) {
                return static::VOICE_PROVIDER_FAMILY_LIVE;
            }
        }

        return null;
    }

    /** True when the provider name resolves to the LIVE family ({@see getVoiceProviderFamilyForName()}). */
    public static function isLiveVoiceProviderName(?string $voiceProviderName): bool
    {
        return static::getVoiceProviderFamilyForName($voiceProviderName) === static::VOICE_PROVIDER_FAMILY_LIVE;
    }

    /**
     * A provider name without its spelling: lower case, letters and digits only (`OPENAI_LIVE` and `openAiLive` both
     * become `openailive`). The name a stored call carries and the name an adapter is registered under are compared
     * this way, so that a stored value keeps its spelling whatever the config file writes.
     */
    public static function normalizeVoiceProviderName(string $voiceProviderName): string
    {
        return (string)preg_replace('/[^a-z0-9]/', '', strtolower($voiceProviderName));
    }

    /** A language code as an owner or a model writes it ("DE", "de-DE", "de_AT") brought to the stored form ("de"). */
    public static function normalizeLanguageCode(string $languageCode): string
    {
        return explode('-', str_replace('_', '-', strtolower(trim($languageCode))))[0];
    }

    /** The English name of a language code for a reader ("de" → "German"); the code itself when unknown. */
    public static function getLanguageNameForCode(string $languageCode): string
    {
        return static::buildLanguageName($languageCode);
    }

    /**
     * The settings used when nothing is stored anywhere: the bottom layer of the merge chain. The language is the
     * configured one of the voice channel (`AI.agentVoice.language`), else the given one, else the default language of
     * the application; the agent follows the caller's language from there.
     *
     * No voice here: the default VOICE is the adapter's (`AI.agentVoice.provider.<name>.defaultVoice`), and which
     * adapter serves the call is decided after the merge.
     *
     * @param string|null $spokenLanguageCode The language of the call's conversation; null falls through to the default
     */
    public static function buildConfiguredDefault(?string $spokenLanguageCode = null): static
    {
        $configuredDefaultVoiceSettings = new static();
        $configuredDefaultVoiceSettings->languageCode = AgentVoiceConfiguration::current()->language
            ?? $spokenLanguageCode
            ?? static::getPlatformDefaultLanguageCode();
        $configuredDefaultVoiceSettings->mirrorCallerLanguage = true;

        return $configuredDefaultVoiceSettings;
    }

    /**
     * A NEW settings object in which every field the override actually sets wins and every field it leaves null keeps
     * this object's value: the field-wise merge the layering depends on.
     *
     * @param AgentVoiceSettings|null $overridingVoiceSettings The higher-priority layer; null yields a copy of this one
     */
    public function buildWithOverrideApplied(?AgentVoiceSettings $overridingVoiceSettings): static
    {
        $mergedVoiceSettings = new static();
        $mergedVoiceSettings->voiceId = $overridingVoiceSettings->voiceId ?? $this->voiceId;
        $mergedVoiceSettings->languageCode = $overridingVoiceSettings->languageCode ?? $this->languageCode;
        $mergedVoiceSettings->mirrorCallerLanguage = $overridingVoiceSettings->mirrorCallerLanguage
            ?? $this->mirrorCallerLanguage;
        $mergedVoiceSettings->speakingStyle = $overridingVoiceSettings->speakingStyle ?? $this->speakingStyle;

        return $mergedVoiceSettings;
    }

    /** The language the agent speaks unless it follows the caller, falling back to the application's default. */
    public function getLanguageCodeOrDefault(): string
    {
        return $this->languageCode ?? static::getPlatformDefaultLanguageCode();
    }

    /**
     * The language direction of the instructions: either "follow the caller, fall back to X" or "always speak X".
     *
     * Spells the language OUT ("English", not "en"): the ISO code is an identifier the voice model has to decode, the
     * name is an instruction it can follow. The first sentence also covers who speaks first, because the model opens
     * the call before the caller said anything and has nothing to follow yet.
     */
    public function buildLanguageInstruction(): string
    {
        return AgentVoicePromptText::forName(
            $this->mirrorCallerLanguage === false ? static::getFixedLanguagePromptName() : static::getMirroredLanguagePromptName(),
            ['LANGUAGE_NAME' => static::buildLanguageName($this->getLanguageCodeOrDefault())]
        );
    }

    /**
     * The closing clause of a note the session worker sends in English while the call runs (a silence check, a bridge
     * line, a goodbye): it NAMES the call's language, so that the voice model never follows the note's own English or
     * guesses a language from the prompt's examples. A caller who has clearly switched since keeps theirs. Starts with
     * a space: it is appended to the note's last sentence.
     */
    public static function buildMidCallLanguageClause(string $languageCode): string
    {
        return ' ' . AgentVoicePromptText::forName(
            strtolower($languageCode) === 'en' ? static::getMidCallLanguageEnglishPromptName() : static::getMidCallLanguagePromptName(),
            ['LANGUAGE_NAME' => static::buildLanguageName($languageCode)]
        );
    }

    /**
     * The English NAME of an ISO-639-1 code ("de" → "German") for the instruction text. Falls back to the code itself
     * when no name is known, so that an exotic code degrades to the bare code instead of an empty word.
     */
    protected static function buildLanguageName(string $languageCode): string
    {
        $languageName = AgentLanguageNames::englishNameForLanguageCode($languageCode) ?? \Locale::getDisplayLanguage($languageCode, 'en');

        return is_string($languageName) ? $languageName : $languageCode;
    }

    /** The prompt of the language rule of a call held in one language whatever the caller speaks. */
    protected static function getFixedLanguagePromptName(): string
    {
        return AgentVoiceAIPromptNames::APP_AI_VOICE_LANGUAGE_FIXED;
    }

    /** The prompt of the language rule of a call that opens in one language and then follows the caller. */
    protected static function getMirroredLanguagePromptName(): string
    {
        return AgentVoiceAIPromptNames::APP_AI_VOICE_LANGUAGE_MIRRORED;
    }

    /** The prompt of the clause that names the call's language in a note sent while the call runs. */
    protected static function getMidCallLanguagePromptName(): string
    {
        return AgentVoiceAIPromptNames::APP_AI_VOICE_LANGUAGE_MID_CALL;
    }

    /** The prompt of the same clause for a call held in English. */
    protected static function getMidCallLanguageEnglishPromptName(): string
    {
        return AgentVoiceAIPromptNames::APP_AI_VOICE_LANGUAGE_MID_CALL_ENGLISH;
    }
}
