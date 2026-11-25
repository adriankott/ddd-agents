<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\Voices;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The curated voice catalog ({@see AgentSelectableVoice}), assembled from an application's catalog entries (pure
 * assembly: the caller supplies the array). Its filter methods answer the two questions a voice picker and a save of
 * voice settings ask: "which voices may THIS provider, model tier and language offer?" and "is this stored voice id
 * legitimate for the provider it will speak on?".
 *
 * @property AgentSelectableVoice[] $elements
 * @method AgentSelectableVoice[] getElements()
 * @method AgentSelectableVoice|null first()
 */
class AgentSelectableVoices extends ObjectSet
{
    /**
     * @param array<string, mixed> $voicesConfig Voice id => its entry (`displayName`, `voiceProvider`, `displayOrder`,
     *     `realtimeModelNames`, `languageCodes`, `providerDefault`, `defaultForLanguageCodes`)
     */
    public static function fromConfiguration(array $voicesConfig): static
    {
        $selectableVoices = new static();
        foreach ($voicesConfig as $voiceId => $voiceEntry) {
            if (!is_array($voiceEntry)) {
                continue;
            }
            $selectableVoice = new AgentSelectableVoice();
            $selectableVoice->voiceId = (string)$voiceId;
            $selectableVoice->displayName = (string)($voiceEntry['displayName'] ?? $voiceId);
            $selectableVoice->voiceProvider = (string)($voiceEntry['voiceProvider'] ?? '');
            $selectableVoice->displayOrder = (int)($voiceEntry['displayOrder'] ?? 0);
            $selectableVoice->realtimeModelNames = is_array($voiceEntry['realtimeModelNames'] ?? null)
                ? array_values($voiceEntry['realtimeModelNames'])
                : null;
            $selectableVoice->languageCodes = is_array($voiceEntry['languageCodes'] ?? null)
                ? array_values($voiceEntry['languageCodes'])
                : null;
            $selectableVoice->providerDefault = (bool)($voiceEntry['providerDefault'] ?? false);
            $selectableVoice->defaultForLanguageCodes = is_array($voiceEntry['defaultForLanguageCodes'] ?? null)
                ? array_values($voiceEntry['defaultForLanguageCodes'])
                : null;
            $selectableVoices->add($selectableVoice);
        }
        $selectableVoices->sort(
            static fn (AgentSelectableVoice $left, AgentSelectableVoice $right): int
                => $left->displayOrder <=> $right->displayOrder
        );

        return $selectableVoices;
    }

    /** The catalog narrowed to one provider (and optionally its model tier and a language). */
    public function filterForProviderModelAndLanguage(
        string $voiceProviderName,
        ?string $realtimeModelName = null,
        ?string $languageCode = null
    ): static {
        $filteredSelectableVoices = new static();
        foreach ($this->getElements() as $selectableVoice) {
            if ($selectableVoice->voiceProvider !== $voiceProviderName
                || !$selectableVoice->isAvailableForRealtimeModel($realtimeModelName)
                || !$selectableVoice->isAvailableForLanguage($languageCode)
            ) {
                continue;
            }
            $filteredSelectableVoices->add($selectableVoice);
        }

        return $filteredSelectableVoices;
    }

    /**
     * The voice this provider speaks with when none is stored (or one of another provider is): the provider's native
     * voice for the spoken language when there is one, else the provider default. Null for a provider without curated
     * voices: it brings its own.
     */
    public function findDefaultVoiceIdForProviderAndLanguage(string $voiceProviderName, ?string $languageCode): ?string
    {
        $providerDefaultVoiceId = null;
        foreach ($this->getElements() as $selectableVoice) {
            if ($selectableVoice->voiceProvider !== $voiceProviderName) {
                continue;
            }
            if ($selectableVoice->isDefaultForLanguage($languageCode)) {
                return $selectableVoice->voiceId;
            }
            if ($selectableVoice->providerDefault && $providerDefaultVoiceId === null) {
                $providerDefaultVoiceId = $selectableVoice->voiceId;
            }
        }

        return $providerDefaultVoiceId;
    }

    /** The catalog entry of one voice of one provider; null when the provider has no such curated voice. */
    public function findVoiceOfProvider(string $voiceId, string $voiceProviderName): ?AgentSelectableVoice
    {
        foreach ($this->getElements() as $selectableVoice) {
            if ($selectableVoice->voiceId === $voiceId && $selectableVoice->voiceProvider === $voiceProviderName) {
                return $selectableVoice;
            }
        }
        return null;
    }

    /** True when the provider has NO curated voices at all: it brings its own voice; no picker, no validation. */
    public function providerHasNoCuratedVoices(string $voiceProviderName): bool
    {
        foreach ($this->getElements() as $selectableVoice) {
            if ($selectableVoice->voiceProvider === $voiceProviderName) {
                return false;
            }
        }

        return true;
    }

    /**
     * Fail-closed save check: a stored voice id must be a curated voice of the provider it will speak on, EXCEPT when
     * that provider has no curated voices (it brings its own; the stored voice id is inert then).
     */
    public function isVoiceIdValidForProviderAndModel(
        string $voiceId,
        string $voiceProviderName,
        ?string $realtimeModelName = null
    ): bool {
        if ($this->providerHasNoCuratedVoices($voiceProviderName)) {
            return true;
        }
        foreach ($this->filterForProviderModelAndLanguage($voiceProviderName, $realtimeModelName)->getElements() as $selectableVoice) {
            if ($selectableVoice->voiceId === $voiceId) {
                return true;
            }
        }

        return false;
    }
}
