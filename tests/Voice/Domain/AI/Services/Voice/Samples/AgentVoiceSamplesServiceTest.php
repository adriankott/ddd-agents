<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Samples;

use DDD\Domain\AI\Entities\Voice\AgentVoiceSettings;
use DDD\Domain\AI\Services\Voice\Samples\AgentVoiceSamplesService;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\ObjectStorage\BasicObjectStorage;
use DDD\Infrastructure\ObjectStorage\FilesystemObjectStorage;
use DDD\Tests\Agents\Voice\Doubles\DirectoryStoreAgentVoiceSamplesService;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;

/**
 * The voice samples of a voice picker: the shipped configuration (every selectable live voice, a sentence per
 * language), the sentence with the agent's name, the fingerprinted key that changes with the model, a WAV a browser
 * plays, the trim of the silence around the speech, and the generation that keeps a current sample, replaces an outdated
 * one and deletes it.
 */
class AgentVoiceSamplesServiceTest extends AgentVoiceUnitTestCase
{
    protected string $storageDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageDirectory = sys_get_temp_dir() . '/ddd-agents-voice-samples-test-' . bin2hex(random_bytes(4));
        mkdir($this->storageDirectory, 0777, true);
        DirectoryStoreAgentVoiceSamplesService::forgetSamplesReadInThisProcess();
        $this->declareAgentPersonaConfiguration(['agentName' => 'Mira']);
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['enabled' => true]));
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storageDirectory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $storedPath) {
            $storedPath->isDir() ? rmdir($storedPath->getPathname()) : unlink($storedPath->getPathname());
        }
        rmdir($this->storageDirectory);
        DirectoryStoreAgentVoiceSamplesService::forgetSamplesReadInThisProcess();
        parent::tearDown();
    }

    protected function buildSamplesService(string $liveModel = 'gpt-live-1.1'): DirectoryStoreAgentVoiceSamplesService
    {
        $samplesService = new DirectoryStoreAgentVoiceSamplesService();
        $samplesService->stubbedObjectStorage = new FilesystemObjectStorage($this->storageDirectory);
        $samplesService->liveModel = $liveModel;

        return $samplesService;
    }

    public function testTheShippedConfigurationOffersEverySelectableLiveVoiceInGermanAndEnglish(): void
    {
        $samplesService = $this->buildSamplesService();
        static::assertSame(AgentVoiceSettings::SELECTABLE_LIVE_VOICE_IDS, $samplesService->getSampleVoiceIds());
        static::assertSame(['de', 'en'], $samplesService->getSampleLanguageCodes());
        static::assertTrue($samplesService->isSampleOffered(AgentVoiceSettings::SELECTABLE_LIVE_VOICE_IDS[0], 'de'));
        static::assertFalse($samplesService->isSampleOffered('unknown', 'de'));
        static::assertFalse($samplesService->isSampleOffered(AgentVoiceSettings::SELECTABLE_LIVE_VOICE_IDS[0], 'xx'));
    }

    public function testConfiguredVoicesAndSentencesReplaceTheDefaults(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith([
            'enabled' => true,
            'samples' => ['voiceIds' => ['flitz'], 'sentences' => ['fr' => 'Salut, je suis %agentName%.', 'it' => ' ']],
        ]));
        $samplesService = $this->buildSamplesService();
        static::assertSame(['flitz'], $samplesService->getSampleVoiceIds());
        static::assertSame(['fr'], $samplesService->getSampleLanguageCodes(), 'a blank sentence offers no language');
        static::assertSame('Salut, je suis Mira.', $samplesService->findSampleSentence('fr'));
    }

    public function testTheSentenceCarriesTheAgentsName(): void
    {
        $samplesService = $this->buildSamplesService();
        static::assertSame('Hey, ich bin Mira. So klinge ich, wenn wir telefonieren.', $samplesService->findSampleSentence('de'));
        static::assertSame('Hey, I\'m Mira. This is how I sound when we talk on the phone.', $samplesService->findSampleSentence('en'));
        static::assertNull($samplesService->findSampleSentence('xx'));
    }

    public function testTheKeyChangesWithTheModelAndTheSentenceAndDiffersPerVoiceAndLanguage(): void
    {
        $currentKey = $this->buildSamplesService()->buildSampleStorageKey('flitz', 'de');
        static::assertMatchesRegularExpression('#^agent-voice-samples/flitz/de/[0-9a-f]{16}\.wav$#', $currentKey);
        static::assertSame($currentKey, $this->buildSamplesService()->buildSampleStorageKey('flitz', 'de'));
        static::assertNotSame($currentKey, $this->buildSamplesService('gpt-live-2')->buildSampleStorageKey('flitz', 'de'));
        static::assertNotSame($currentKey, $this->buildSamplesService()->buildSampleStorageKey('nuri', 'de'));
        static::assertNotSame($currentKey, $this->buildSamplesService()->buildSampleStorageKey('flitz', 'en'));
        $this->declareAgentPersonaConfiguration(['agentName' => 'Ella']);
        static::assertNotSame($currentKey, $this->buildSamplesService()->buildSampleStorageKey('flitz', 'de'), 'another name is another sentence');
    }

    public function testTheWaveHeaderDescribesMonoSixteenBitAtTheSessionRate(): void
    {
        $waveFile = AgentVoiceSamplesService::encodeWave(str_repeat(pack('v', 1000), 480), 24000);
        static::assertSame(44 + 960, strlen($waveFile));
        static::assertSame('RIFF', substr($waveFile, 0, 4));
        static::assertSame(36 + 960, unpack('V', substr($waveFile, 4, 4))[1]);
        static::assertSame('WAVE', substr($waveFile, 8, 4));
        static::assertSame(
            ['chunkSize' => 16, 'format' => 1, 'channels' => 1, 'sampleRate' => 24000, 'byteRate' => 48000, 'blockAlign' => 2, 'bitsPerSample' => 16],
            unpack('VchunkSize/vformat/vchannels/VsampleRate/VbyteRate/vblockAlign/vbitsPerSample', substr($waveFile, 16, 20))
        );
        static::assertSame('data', substr($waveFile, 36, 4));
        static::assertSame(960, unpack('V', substr($waveFile, 40, 4))[1]);
    }

    public function testTrimmingKeepsTheSpeechWithAShortMarginAndLeavesPureSilenceAlone(): void
    {
        $speech = str_repeat(pack('v', 5000), 100);
        $pcmAudio = str_repeat(pack('v', 0), 10000) . $speech . str_repeat(pack('v', 10), 10000);
        $trimmedAudio = AgentVoiceSamplesService::trimSilence($pcmAudio, 300, 50);
        static::assertSame((50 + 100 + 50) * 2, strlen($trimmedAudio));
        static::assertStringContainsString($speech, $trimmedAudio);
        $silence = str_repeat(pack('v', 0), 300);
        static::assertSame($silence, AgentVoiceSamplesService::trimSilence($silence));
        static::assertSame('', AgentVoiceSamplesService::trimSilence(''));
    }

    public function testGenerationStoresAWaveKeepsACurrentSampleAndReplacesAnOutdatedOne(): void
    {
        $samplesService = $this->buildSamplesService('gpt-live-1');
        static::assertNull($samplesService->findSampleAudio('flitz', 'de'));
        static::assertSame(AgentVoiceSamplesService::GENERATION_CREATED, $samplesService->generateSample('flitz', 'de'));
        static::assertSame([['flitz', 'Hey, ich bin Mira. So klinge ich, wenn wir telefonieren.']], $samplesService->recordingSynthesizer->synthesizedRequests);
        static::assertSame('Hey, ich bin Mira. So klinge ich, wenn wir telefonieren.', $samplesService->lastLiveVoiceSampleRecording?->transcript);
        $outdatedKey = $samplesService->buildSampleStorageKey('flitz', 'de');
        $sampleAudio = $samplesService->findSampleAudio('flitz', 'de');
        static::assertIsString($sampleAudio);
        static::assertSame('RIFF', substr($sampleAudio, 0, 4));
        static::assertLessThan(48000 * 2, strlen($sampleAudio), 'the second of silence on each side is trimmed');

        static::assertSame(AgentVoiceSamplesService::GENERATION_KEPT, $samplesService->generateSample('flitz', 'de'));
        static::assertCount(1, $samplesService->recordingSynthesizer->synthesizedRequests, 'a current sample is not spoken again');

        $switchedSamplesService = $this->buildSamplesService('gpt-live-1.1');
        static::assertNull($switchedSamplesService->findSampleAudio('flitz', 'de'), 'a new model hides the old sample');
        static::assertSame(AgentVoiceSamplesService::GENERATION_CREATED, $switchedSamplesService->generateSample('flitz', 'de'));
        static::assertFalse($switchedSamplesService->getBasicObjectStorage()->objectExists($outdatedKey), 'the outdated sample is deleted');
        static::assertTrue($switchedSamplesService->getBasicObjectStorage()->objectExists($switchedSamplesService->buildSampleStorageKey('flitz', 'de')));

        static::assertSame(AgentVoiceSamplesService::GENERATION_CREATED, $switchedSamplesService->generateSample('flitz', 'de', true));
        static::assertCount(2, $switchedSamplesService->recordingSynthesizer->synthesizedRequests, 'force speaks again');
    }

    public function testGenerationRefusesAVoiceOrLanguageOutsideTheConfiguration(): void
    {
        $this->expectException(InternalErrorException::class);
        $this->buildSamplesService()->generateSample('unknown', 'de');
    }

    public function testSamplesCanBeMadeWhereTheWebSocketLibraryIsInstalled(): void
    {
        static::assertTrue((new AgentVoiceSamplesService())->canGenerateSamplesHere());
    }

    public function testWithoutTheWebSocketLibraryGenerationAnswersThatTheLibraryIsMissingAndStoresNothing(): void
    {
        $storageDirectory = $this->storageDirectory;
        $samplesServiceWithoutLibrary = new class ($storageDirectory) extends AgentVoiceSamplesService {
            public function __construct(protected string $storageDirectory)
            {
            }

            public function canGenerateSamplesHere(): bool
            {
                return false;
            }

            public function getBasicObjectStorage(): BasicObjectStorage
            {
                return new FilesystemObjectStorage($this->storageDirectory);
            }

            protected function getLiveModel(): string
            {
                return 'gpt-live-1.1';
            }
        };

        try {
            $samplesServiceWithoutLibrary->generateSample('flitz', 'de');
            static::fail('generation without the library must be refused');
        } catch (InternalErrorException $internalErrorException) {
            static::assertSame(AgentVoiceSamplesService::LIBRARY_MISSING_MESSAGE, $internalErrorException->getMessage());
        }
        static::assertFalse($samplesServiceWithoutLibrary->getBasicObjectStorage()->objectExists($samplesServiceWithoutLibrary->buildSampleStorageKey('flitz', 'de')));
    }
}
