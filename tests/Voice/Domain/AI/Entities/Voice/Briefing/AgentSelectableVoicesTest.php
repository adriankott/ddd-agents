<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Entities\Voice\Briefing;

use DDD\Domain\AI\Entities\Voice\Voices\AgentSelectableVoice;
use DDD\Domain\AI\Entities\Voice\Voices\AgentSelectableVoices;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;

/**
 * The curated voice catalog an application supplies: assembled in picker order, narrowed by provider, model tier and
 * language, the default voice of a provider for a language, and the fail-closed check of a stored voice id.
 */
class AgentSelectableVoicesTest extends AgentVoiceUnitTestCase
{
    protected static function buildCatalog(): AgentSelectableVoices
    {
        return AgentSelectableVoices::fromConfiguration([
            'nuri' => ['displayName' => 'Nuri — German, female', 'voiceProvider' => 'openAiLive', 'displayOrder' => 3, 'languageCodes' => ['de'], 'defaultForLanguageCodes' => ['de']],
            'marin' => ['displayName' => 'Marin — warm, female', 'voiceProvider' => 'openAiLive', 'displayOrder' => 1, 'providerDefault' => true],
            'stone' => ['voiceProvider' => 'openAiLive', 'displayOrder' => 2, 'realtimeModelNames' => ['gpt-live-2']],
            'clara' => ['displayName' => 'Clara', 'voiceProvider' => 'otherVoice', 'displayOrder' => 0, 'providerDefault' => true],
            'broken' => 'not an entry',
        ]);
    }

    /** @return string[] */
    protected static function readVoiceIds(AgentSelectableVoices $agentSelectableVoices): array
    {
        return array_map(static fn (AgentSelectableVoice $selectableVoice): string => $selectableVoice->voiceId, $agentSelectableVoices->getElements());
    }

    public function testTheCatalogIsAssembledInPickerOrderAndSkipsWhatIsNoEntry(): void
    {
        $agentSelectableVoices = static::buildCatalog();

        static::assertSame(['clara', 'marin', 'stone', 'nuri'], static::readVoiceIds($agentSelectableVoices));
        $stoneVoice = $agentSelectableVoices->findVoiceOfProvider('stone', 'openAiLive');
        static::assertInstanceOf(AgentSelectableVoice::class, $stoneVoice);
        static::assertSame('stone', $stoneVoice->displayName, 'a voice without a title is titled by its id');
        static::assertSame(['gpt-live-2'], $stoneVoice->realtimeModelNames);
        static::assertNull($stoneVoice->languageCodes);
        static::assertFalse($stoneVoice->providerDefault);
    }

    public function testTheCatalogNarrowedToAProviderItsModelTierAndALanguage(): void
    {
        $agentSelectableVoices = static::buildCatalog();

        static::assertSame(['marin', 'stone', 'nuri'], static::readVoiceIds($agentSelectableVoices->filterForProviderModelAndLanguage('openAiLive')));
        static::assertSame(['marin', 'nuri'], static::readVoiceIds($agentSelectableVoices->filterForProviderModelAndLanguage('openAiLive', 'gpt-live-1')));
        static::assertSame(['marin', 'stone'], static::readVoiceIds($agentSelectableVoices->filterForProviderModelAndLanguage('openAiLive', null, 'fr')));
        static::assertSame(['clara'], static::readVoiceIds($agentSelectableVoices->filterForProviderModelAndLanguage('otherVoice')));
        static::assertSame([], static::readVoiceIds($agentSelectableVoices->filterForProviderModelAndLanguage('OPENAI_LIVE')));
    }

    public function testTheDefaultVoiceIsTheNativeSpeakerOfTheLanguageElseTheProviderDefault(): void
    {
        $agentSelectableVoices = static::buildCatalog();

        static::assertSame('nuri', $agentSelectableVoices->findDefaultVoiceIdForProviderAndLanguage('openAiLive', 'de'));
        static::assertSame('marin', $agentSelectableVoices->findDefaultVoiceIdForProviderAndLanguage('openAiLive', 'fr'));
        static::assertSame('marin', $agentSelectableVoices->findDefaultVoiceIdForProviderAndLanguage('openAiLive', null));
        static::assertNull($agentSelectableVoices->findDefaultVoiceIdForProviderAndLanguage('houseVoice', 'de'));
    }

    public function testAStoredVoiceIsValidOnlyAsACuratedVoiceOfItsProviderAndTier(): void
    {
        $agentSelectableVoices = static::buildCatalog();

        static::assertTrue($agentSelectableVoices->isVoiceIdValidForProviderAndModel('nuri', 'openAiLive'));
        static::assertTrue($agentSelectableVoices->isVoiceIdValidForProviderAndModel('stone', 'openAiLive', 'gpt-live-2'));
        static::assertFalse($agentSelectableVoices->isVoiceIdValidForProviderAndModel('stone', 'openAiLive', 'gpt-live-1'));
        static::assertFalse($agentSelectableVoices->isVoiceIdValidForProviderAndModel('clara', 'openAiLive'));
        static::assertTrue($agentSelectableVoices->isVoiceIdValidForProviderAndModel('anything', 'houseVoice'), 'a provider without curated voices brings its own');
        static::assertTrue($agentSelectableVoices->providerHasNoCuratedVoices('houseVoice'));
        static::assertFalse($agentSelectableVoices->providerHasNoCuratedVoices('otherVoice'));
        static::assertNull($agentSelectableVoices->findVoiceOfProvider('nuri', 'otherVoice'));
    }

    public function testTheAvailabilityRulesOfOneVoice(): void
    {
        $selectableVoice = new AgentSelectableVoice();
        $selectableVoice->voiceId = 'nuri';
        $selectableVoice->voiceProvider = 'openAiLive';

        static::assertTrue($selectableVoice->isAvailableForRealtimeModel('any'));
        static::assertTrue($selectableVoice->isAvailableForLanguage('any'));
        static::assertFalse($selectableVoice->isDefaultForLanguage('de'));
        $selectableVoice->realtimeModelNames = ['gpt-live-1'];
        $selectableVoice->languageCodes = ['de'];
        $selectableVoice->defaultForLanguageCodes = ['de'];
        static::assertTrue($selectableVoice->isAvailableForRealtimeModel(null));
        static::assertFalse($selectableVoice->isAvailableForRealtimeModel('gpt-live-2'));
        static::assertTrue($selectableVoice->isAvailableForLanguage(null));
        static::assertFalse($selectableVoice->isAvailableForLanguage('fr'));
        static::assertTrue($selectableVoice->isDefaultForLanguage('de'));
        static::assertFalse($selectableVoice->isDefaultForLanguage(null));
        static::assertNotSame((new AgentSelectableVoice())->uniqueKey(), $selectableVoice->uniqueKey());
    }
}
