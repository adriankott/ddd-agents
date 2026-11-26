<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Voice\Samples;

use DDD\Domain\AI\Entities\Voice\AgentVoiceConfiguration;
use DDD\Domain\AI\Entities\Voice\AgentVoiceSettings;
use DDD\Domain\AI\Services\Voice\Live\OpenAILiveVoiceProviderAdapter;
use DDD\Domain\AI\Services\Voice\Providers\RealtimeVoiceProviderAdapter;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\ObjectStorage\BasicObjectStorage;
use DDD\Infrastructure\ObjectStorage\ObjectStorage;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\ObjectStorageService;
use DDD\Infrastructure\Services\Service;
use DDD\Modules\Agents\AgentsModule;

/**
 * The voice samples of the voice picker: one short spoken sentence per voice and language (`AI.agentVoice.samples`),
 * spoken by the live model the calls run on (the session worker's `LiveVoiceSampleSynthesizer`), stored as WAV in the application's
 * object store and served only through the backend ({@see \DDD\Presentation\Api\Admin\Voice\AdminAgentVoiceSamplesTrait};
 * no client ever reaches the store, no presigned URL).
 *
 * A sample's key carries the fingerprint of everything that shapes it (model, voice, language, sentence with the agent's
 * name, audio format): when one of them changes, the key changes, the old sample is no longer found and the generator
 * makes a new one (`app:agent:voice-samples:generate`). Read samples are kept in the process for the next request.
 */
class AgentVoiceSamplesService extends Service
{
    /** @var string The configuration of the samples below `AI.agentVoice` */
    public const string CONFIG_KEY = 'AI.agentVoice.samples';

    /** @var string The object store of the samples when the configuration names none */
    public const string DEFAULT_OBJECT_STORAGE_NAME = 'agentVoiceSamples';

    /** @var string The content type of a stored sample */
    public const string CONTENT_TYPE = 'audio/wav';

    /** @var string Bumped when the audio a sample is made of changes (format, trimming, encoding) */
    public const string SAMPLE_FORMAT_VERSION = 'wav-pcm16-24k-v1';

    /** @var string The placeholder of the agent's name in a sentence */
    public const string AGENT_NAME_PLACEHOLDER = '%agentName%';

    /** @var int The most samples a process keeps after reading them */
    public const int PROCESS_CACHE_MAXIMUM_SAMPLES = 64;

    /**
     * @var string The synthesizer, a class of the session worker's folder
     * ({@see AgentsModule::VOICE_SESSION_WORKER_NAMESPACES}): it needs the WebSocket library the package only suggests,
     * so this service names it and resolves it from the container only when it generates, never by import
     */
    public const string LIVE_VOICE_SAMPLE_SYNTHESIZER_CLASS = 'DDD\\Domain\\AI\\Services\\Voice\\SessionWorker\\Samples\\LiveVoiceSampleSynthesizer';

    /** @var string The answer when samples cannot be made here: the WebSocket library is not installed */
    public const string LIBRARY_MISSING_MESSAGE = 'Voice samples need the WebSocket library of the voice session worker, which is not installed: composer require ratchet/pawl react/event-loop';

    /** @var string A sample exists for the current fingerprint and was left as it is */
    public const string GENERATION_KEPT = 'kept';

    /** @var string A sample was spoken and stored */
    public const string GENERATION_CREATED = 'created';

    /** @var LiveVoiceSampleRecording|null The recording of the last sample this service made (what was said, how long) */
    public ?LiveVoiceSampleRecording $lastLiveVoiceSampleRecording = null;

    /** @var string[] Fingerprint => WAV bytes of the samples read in this process */
    protected static array $samplesReadInThisProcess = [];

    /**
     * The voices a sample is made for: `voiceIds` of the configuration, else the package's selectable live voices.
     *
     * @return string[]
     */
    public function getSampleVoiceIds(): array
    {
        $configuredVoiceIds = $this->getSamplesConfiguration()['voiceIds'] ?? null;
        return is_array($configuredVoiceIds) && $configuredVoiceIds !== []
            ? array_values(array_map('strval', $configuredVoiceIds))
            : AgentVoiceSettings::SELECTABLE_LIVE_VOICE_IDS;
    }

    /**
     * The languages a sample is made for: the languages that have a sentence.
     *
     * @return string[]
     */
    public function getSampleLanguageCodes(): array
    {
        return array_keys($this->getSampleSentences());
    }

    /**
     * Whether a sample may exist for this voice and language (both configured).
     *
     * @param string $voiceId
     * @param string $languageCode
     * @return bool
     */
    public function isSampleOffered(string $voiceId, string $languageCode): bool
    {
        return in_array($voiceId, $this->getSampleVoiceIds(), true) && in_array($languageCode, $this->getSampleLanguageCodes(), true);
    }

    /**
     * The sentence of a language with the agent's name filled in.
     *
     * @param string $languageCode
     * @return string|null null when the language has no sentence
     */
    public function findSampleSentence(string $languageCode): ?string
    {
        $sentence = $this->getSampleSentences()[$languageCode] ?? null;
        if (!is_string($sentence) || trim($sentence) === '') {
            return null;
        }
        $agentName = AgentVoiceConfiguration::current()->agentName;
        return str_replace(static::AGENT_NAME_PLACEHOLDER, $agentName !== '' ? $agentName : 'Assistant', trim($sentence));
    }

    /**
     * The fingerprint of a sample: what shapes it, hashed.
     *
     * @param string $voiceId
     * @param string $languageCode
     * @return string 16 hex characters
     */
    public function buildSampleFingerprint(string $voiceId, string $languageCode): string
    {
        return substr(hash('sha256', (string)json_encode([
            $this->getLiveModel(),
            $voiceId,
            $languageCode,
            $this->findSampleSentence($languageCode),
            static::SAMPLE_FORMAT_VERSION,
        ], JSON_UNESCAPED_UNICODE)), 0, 16);
    }

    /**
     * The key of a sample in the store: `<keyPrefix><voice>/<language>/<fingerprint>.wav`.
     *
     * @param string $voiceId
     * @param string $languageCode
     * @return string
     */
    public function buildSampleStorageKey(string $voiceId, string $languageCode): string
    {
        return $this->getKeyPrefix() . rawurlencode($voiceId) . '/' . rawurlencode($languageCode) . '/'
            . $this->buildSampleFingerprint($voiceId, $languageCode) . '.wav';
    }

    /**
     * The WAV bytes of the current sample; null when none was generated (or voice or language is not offered).
     *
     * @param string $voiceId
     * @param string $languageCode
     * @return string|null
     * @throws InternalErrorException The object store is not configured
     */
    public function findSampleAudio(string $voiceId, string $languageCode): ?string
    {
        if (!$this->isSampleOffered($voiceId, $languageCode)) {
            return null;
        }
        $fingerprint = $this->buildSampleFingerprint($voiceId, $languageCode);
        if (isset(static::$samplesReadInThisProcess[$fingerprint])) {
            return static::$samplesReadInThisProcess[$fingerprint];
        }
        $sampleAudio = $this->getBasicObjectStorage()->getObjectContent($this->buildSampleStorageKey($voiceId, $languageCode));
        if ($sampleAudio === null || $sampleAudio === '') {
            return null;
        }
        if (count(static::$samplesReadInThisProcess) >= static::PROCESS_CACHE_MAXIMUM_SAMPLES) {
            array_shift(static::$samplesReadInThisProcess);
        }
        static::$samplesReadInThisProcess[$fingerprint] = $sampleAudio;
        return $sampleAudio;
    }

    /**
     * Whether samples can be made here: the WebSocket library of the session worker is installed. Reading and serving
     * the stored samples works without it.
     *
     * @return bool
     */
    public function canGenerateSamplesHere(): bool
    {
        return !AgentsModule::isClassOfAnInactivePart(static::LIVE_VOICE_SAMPLE_SYNTHESIZER_CLASS);
    }

    /**
     * Makes the sample of a voice and language unless the current one exists: speaks the sentence, stores the WAV and
     * removes the voice's earlier samples of that language (other fingerprints) when the store can list.
     *
     * @param string $voiceId
     * @param string $languageCode
     * @param bool $force Speak and store again although the current sample exists
     * @return string {@see GENERATION_KEPT} or {@see GENERATION_CREATED}
     * @throws InternalErrorException Not offered, no store, the WebSocket library is missing, or the session produced no
     *     speech
     */
    public function generateSample(string $voiceId, string $languageCode, bool $force = false): string
    {
        if (!$this->isSampleOffered($voiceId, $languageCode)) {
            throw new InternalErrorException("No voice sample is offered for voice \"{$voiceId}\" and language \"{$languageCode}\"");
        }
        $basicObjectStorage = $this->getBasicObjectStorage();
        $sampleStorageKey = $this->buildSampleStorageKey($voiceId, $languageCode);
        if (!$force && $basicObjectStorage->objectExists($sampleStorageKey)) {
            return static::GENERATION_KEPT;
        }
        $liveVoiceSampleRecording = $this->getLiveVoiceSampleSynthesizer()->synthesize(
            $voiceId,
            (string)$this->findSampleSentence($languageCode)
        );
        $this->lastLiveVoiceSampleRecording = $liveVoiceSampleRecording;
        $basicObjectStorage->putObject(
            $sampleStorageKey,
            static::encodeWave(static::trimSilence($liveVoiceSampleRecording->pcmAudio), LiveVoiceSampleRecording::SAMPLE_RATE),
            static::CONTENT_TYPE
        );
        unset(static::$samplesReadInThisProcess[$this->buildSampleFingerprint($voiceId, $languageCode)]);
        $this->deleteEarlierSamples($basicObjectStorage, $voiceId, $languageCode, $sampleStorageKey);
        return static::GENERATION_CREATED;
    }

    /**
     * Raw PCM (mono, 16-bit little-endian) as a WAV file.
     *
     * @param string $pcmAudio
     * @param int $sampleRate
     * @return string
     */
    public static function encodeWave(string $pcmAudio, int $sampleRate): string
    {
        $dataLength = strlen($pcmAudio);
        return 'RIFF' . pack('V', 36 + $dataLength) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, 1, 1, $sampleRate, $sampleRate * 2, 2, 16)
            . 'data' . pack('V', $dataLength) . $pcmAudio;
    }

    /**
     * Cuts the near-silence before the first and after the last loud sample, keeping a short margin.
     *
     * @param string $pcmAudio mono, 16-bit little-endian
     * @param int $threshold The amplitude below which a sample counts as silence
     * @param int $marginSamples Samples kept before and after the speech
     * @return string
     */
    public static function trimSilence(string $pcmAudio, int $threshold = 300, int $marginSamples = 2400): string
    {
        $sampleCount = intdiv(strlen($pcmAudio), 2);
        if ($sampleCount === 0) {
            return '';
        }
        /** @var int[] $samples */
        $samples = array_values(unpack('v*', substr($pcmAudio, 0, $sampleCount * 2)) ?: []);
        $firstLoudIndex = null;
        $lastLoudIndex = null;
        foreach ($samples as $sampleIndex => $unsignedSample) {
            $signedSample = $unsignedSample >= 32768 ? $unsignedSample - 65536 : $unsignedSample;
            if (abs($signedSample) >= $threshold) {
                $firstLoudIndex ??= $sampleIndex;
                $lastLoudIndex = $sampleIndex;
            }
        }
        if ($firstLoudIndex === null) {
            return substr($pcmAudio, 0, $sampleCount * 2);
        }
        $startIndex = max(0, $firstLoudIndex - $marginSamples);
        $endIndex = min($sampleCount - 1, $lastLoudIndex + $marginSamples);
        return substr($pcmAudio, $startIndex * 2, ($endIndex - $startIndex + 1) * 2);
    }

    /**
     * The store of the samples: `objectStorage` of the configuration.
     *
     * @return BasicObjectStorage
     * @throws InternalErrorException The store is not configured
     */
    public function getBasicObjectStorage(): BasicObjectStorage
    {
        $objectStorageName = (string)($this->getSamplesConfiguration()['objectStorage'] ?? static::DEFAULT_OBJECT_STORAGE_NAME);
        /** @var ObjectStorageService $objectStorageService */
        $objectStorageService = DDDService::instance()->getService(ObjectStorageService::class);
        $basicObjectStorage = $objectStorageService->getBasicObjectStorage($objectStorageName);
        if (!$basicObjectStorage instanceof BasicObjectStorage) {
            throw new InternalErrorException("The object store \"{$objectStorageName}\" of the voice samples is not configured");
        }
        return $basicObjectStorage;
    }

    /**
     * Removes the samples of a voice and language other than the current one; only a store that can list.
     *
     * @param BasicObjectStorage $basicObjectStorage
     * @param string $voiceId
     * @param string $languageCode
     * @param string $currentStorageKey
     * @return void
     */
    protected function deleteEarlierSamples(BasicObjectStorage $basicObjectStorage, string $voiceId, string $languageCode, string $currentStorageKey): void
    {
        if (!$basicObjectStorage instanceof ObjectStorage) {
            return;
        }
        $earlierStorageKeys = [];
        $keyPrefix = $this->getKeyPrefix() . rawurlencode($voiceId) . '/' . rawurlencode($languageCode) . '/';
        foreach ($basicObjectStorage->listObjects($keyPrefix)->getElements() as $objectStorageObject) {
            if ($objectStorageObject->key !== $currentStorageKey) {
                $earlierStorageKeys[] = $objectStorageObject->key;
            }
        }
        if ($earlierStorageKeys !== []) {
            $basicObjectStorage->deleteObjects($earlierStorageKeys);
        }
    }

    /**
     * @return string The model the calls run on (the samples are spoken by it): the default provider's live model
     * @throws InternalErrorException The default provider is no GPT-Live adapter
     */
    protected function getLiveModel(): string
    {
        $realtimeVoiceProviderAdapter = RealtimeVoiceProviderAdapter::forProviderName(null);
        if (!$realtimeVoiceProviderAdapter instanceof OpenAILiveVoiceProviderAdapter) {
            throw new InternalErrorException('Voice samples need a GPT-Live provider as the default voice provider');
        }
        return $realtimeVoiceProviderAdapter->getLiveModel();
    }

    /** @return string The key prefix of the samples in the store, ending in "/" */
    protected function getKeyPrefix(): string
    {
        $keyPrefix = trim((string)($this->getSamplesConfiguration()['keyPrefix'] ?? 'agent-voice-samples/'));
        return $keyPrefix === '' ? '' : rtrim($keyPrefix, '/') . '/';
    }

    /** @return array<string, string> Language code => sentence */
    protected function getSampleSentences(): array
    {
        $sentences = $this->getSamplesConfiguration()['sentences'] ?? [];
        return is_array($sentences) ? array_filter($sentences, static fn (mixed $sentence): bool => is_string($sentence) && trim($sentence) !== '') : [];
    }

    /** @return array<string, mixed> */
    protected function getSamplesConfiguration(): array
    {
        $samplesConfiguration = Config::get(static::CONFIG_KEY);
        return is_array($samplesConfiguration) ? $samplesConfiguration : [];
    }

    /**
     * The synthesizer from the container (an application's same-path subclass answers).
     *
     * @return \DDD\Domain\AI\Services\Voice\SessionWorker\Samples\LiveVoiceSampleSynthesizer
     * @throws InternalErrorException The WebSocket library is not installed ({@see LIBRARY_MISSING_MESSAGE})
     */
    protected function getLiveVoiceSampleSynthesizer(): Service
    {
        if (!$this->canGenerateSamplesHere()) {
            throw new InternalErrorException(static::LIBRARY_MISSING_MESSAGE);
        }
        /** @var \DDD\Domain\AI\Services\Voice\SessionWorker\Samples\LiveVoiceSampleSynthesizer $liveVoiceSampleSynthesizer */
        $liveVoiceSampleSynthesizer = DDDService::instance()->getService(static::LIVE_VOICE_SAMPLE_SYNTHESIZER_CLASS);
        return $liveVoiceSampleSynthesizer;
    }
}
