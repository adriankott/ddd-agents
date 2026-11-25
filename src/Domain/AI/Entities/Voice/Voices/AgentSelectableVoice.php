<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\Voices;

use DDD\Domain\AI\Entities\Voice\AgentVoiceSettings;
use DDD\Domain\Base\Entities\ValueObject;
use Symfony\Component\Validator\Constraints\Length;

/**
 * ONE voice an owner may pick, from the application's curated catalog ({@see AgentSelectableVoices}): which provider
 * speaks it, which model tiers of that provider it exists on, and which languages it is curated for. A voice picker
 * renders from this; a save of voice settings validates fail-closed against it.
 */
class AgentSelectableVoice extends ValueObject
{
    /** @var string The provider's voice id: what {@see AgentVoiceSettings::$voiceId} stores */
    #[Length(max: 64)]
    public string $voiceId = '';

    /** @var string The title an owner sees ("Marin — warm, female"); English, doubles as the translation key of a client */
    #[Length(max: 120)]
    public string $displayName = '';

    /** @var string Which provider speaks this voice: the name of its adapter as a call pins it */
    #[Length(max: 20)]
    public string $voiceProvider = '';

    /** @var int Picker order, ascending */
    public int $displayOrder = 0;

    /** @var string[]|null Model tiers of the provider the voice exists on; null = every tier of its provider */
    public ?array $realtimeModelNames = null;

    /** @var string[]|null ISO-639-1 languages the voice is curated for; null = multilingual */
    public ?array $languageCodes = null;

    /** @var bool THE voice of its provider when no voice is stored, or a voice of another provider is */
    public bool $providerDefault = false;

    /** @var string[]|null Spoken languages for which this voice is the default instead (a native speaker of the call language) */
    public ?array $defaultForLanguageCodes = null;

    public function isAvailableForRealtimeModel(?string $realtimeModelName): bool
    {
        return $this->realtimeModelNames === null
            || $realtimeModelName === null
            || in_array($realtimeModelName, $this->realtimeModelNames, true);
    }

    public function isAvailableForLanguage(?string $languageCode): bool
    {
        return $this->languageCodes === null
            || $languageCode === null
            || in_array($languageCode, $this->languageCodes, true);
    }

    /** True when this voice is the provider's default for a call in the given language. */
    public function isDefaultForLanguage(?string $languageCode): bool
    {
        return $languageCode !== null
            && $this->defaultForLanguageCodes !== null
            && in_array($languageCode, $this->defaultForLanguageCodes, true);
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->voiceId . '.' . $this->voiceProvider);
    }
}
