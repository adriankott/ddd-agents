<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Voice\SessionWorker\Samples;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\ModelCalls\AIModelCall;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Domain\AI\Services\AISessionUsageService;
use DDD\Domain\AI\Services\Voice\Live\OpenAILiveVoiceProviderAdapter;
use DDD\Domain\AI\Services\Voice\Providers\RealtimeVoiceProviderAdapter;
use DDD\Domain\AI\Services\Voice\Samples\LiveVoiceSampleRecording;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Ratchet\Client\Connector;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;

/**
 * Speaks one sentence with one voice of the live model and returns the speech: a short GPT-Live session over the
 * provider's server WebSocket (`wss://<apiHost>/v1/live/sessions`, the key and host of the default provider's adapter,
 * the model the calls run on), started with a developer message that asks for exactly the sentence. Input audio is
 * digital silence, paced in real time: the session timeline only moves while audio comes in. The speech ends when no
 * audio WITH SPEECH came for {@see QUIET_SECONDS_AFTER_SPEECH}: the provider sends no "audio done" and keeps streaming
 * quiet frames after the speech ({@see LiveVoiceSampleRecording::containsSpeech()}); then the session is closed.
 *
 * Every sample runs on an event loop of its own ({@see buildEventLoop()}) in a {@see LiveVoiceSampleSession}: on the
 * process's global loop a socket of an earlier sample that closes late stops the loop of the next one. The handshake carries no `Origin`
 * ({@see OriginlessWebSocketConnector}). A recording without a transcript or shorter than
 * {@see MINIMUM_SPEECH_SECONDS} counts as cut short and is refused. The seconds of every opened session are booked
 * ({@see recordSessionSeconds()}), also those of a session that ended without speech: the provider bills them.
 *
 * Audio format: mono signed 16-bit little-endian PCM at {@see SAMPLE_RATE} Hz, the provider's default.
 *
 * Part of the session worker's folder ({@see \DDD\Modules\Agents\AgentsModule::VOICE_SESSION_WORKER_NAMESPACES}): it
 * needs the WebSocket library the package only suggests; without it the class is no service and the samples service
 * answers that the library is missing.
 */
class LiveVoiceSampleSynthesizer extends Service
{
    /** @var int The sample rate of the session's audio (input and output) */
    public const int SAMPLE_RATE = LiveVoiceSampleRecording::SAMPLE_RATE;

    /** @var float Seconds between two appends of silent input audio */
    public const float INPUT_CHUNK_SECONDS = 0.1;

    /** @var float Seconds without audio after speech that end the sample */
    public const float QUIET_SECONDS_AFTER_SPEECH = 1.5;

    /** @var float The longest a sample session may run before it is closed */
    public const float MAXIMUM_SESSION_SECONDS = 30.0;

    /** @var float Seconds the provider gets to confirm the close before the socket is dropped */
    public const float CLOSE_GRACE_SECONDS = 3.0;

    /** @var float The least audio a sample must have; less is a session cut short */
    public const float MINIMUM_SPEECH_SECONDS = 1.0;

    /** @var string The usage scope the seconds of a sample session are booked under. A stored value. */
    public const string USAGE_SCOPE_SAMPLE = 'AI.VOICE.SAMPLE';

    /** @var string The path of the provider's session WebSocket below its host */
    public const string SESSIONS_PATH = '/v1/live/sessions';

    /**
     * Speaks `$sentence` with `$voiceId` and returns the raw PCM of the speech.
     *
     * @param string $voiceId A live voice of the provider
     * @param string $sentence The sentence, spoken exactly
     * @return LiveVoiceSampleRecording The recording; its `pcmAudio` holds the speech
     * @throws InternalErrorException The session could not be opened, or it ended without speech
     */
    public function synthesize(string $voiceId, string $sentence): LiveVoiceSampleRecording
    {
        $liveVoiceProviderAdapter = $this->getLiveVoiceProviderAdapter();
        $liveModel = $liveVoiceProviderAdapter->getLiveModel();
        // a loop of its own per sample: a socket of an earlier sample that closes late must never stop this one
        $eventLoop = $this->buildEventLoop();
        $liveVoiceSampleSession = new LiveVoiceSampleSession(
            $eventLoop,
            (string)json_encode($this->buildSessionStartEvent($liveModel, $voiceId, $sentence), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        ($this->buildConnector($eventLoop))(
            $this->buildSessionWebSocketUrl($liveVoiceProviderAdapter),
            [],
            $liveVoiceProviderAdapter->buildSidebandWebSocketHeaders()
        )->then([$liveVoiceSampleSession, 'onConnected'], [$liveVoiceSampleSession, 'onConnectFailed']);
        $liveVoiceSampleSession->run();

        $liveVoiceSampleRecording = $liveVoiceSampleSession->liveVoiceSampleRecording;
        $connectFailure = $liveVoiceSampleSession->connectFailure;
        if ($connectFailure !== null) {
            throw new InternalErrorException('The voice sample session could not be opened: ' . $connectFailure->getMessage());
        }
        // the provider bills every second of an opened session, also one that ended without speech
        $this->recordSessionSeconds($liveModel, microtime(true) - $liveVoiceSampleSession->startedAt);
        if ($liveVoiceSampleRecording->pcmAudio === '') {
            $reason = $liveVoiceSampleRecording->errorMessages !== []
                ? implode('; ', $liveVoiceSampleRecording->errorMessages)
                : 'no audio within ' . (int)static::MAXIMUM_SESSION_SECONDS . ' s';
            $reason .= ' (events: ' . implode(', ', array_map(
                static fn (string $eventType, int $eventCount): string => "{$eventType} x{$eventCount}",
                array_keys($liveVoiceSampleRecording->eventTypeCounts),
                $liveVoiceSampleRecording->eventTypeCounts
            )) . ')';
            throw new InternalErrorException("The voice sample of {$voiceId} has no speech: {$reason}");
        }
        if (trim($liveVoiceSampleRecording->transcript) === ''
            || $liveVoiceSampleRecording->getAudioSeconds(static::SAMPLE_RATE) < static::MINIMUM_SPEECH_SECONDS
        ) {
            throw new InternalErrorException(sprintf(
                'The voice sample of %s was cut short (%.1f s of audio, transcript "%s"); nothing is stored',
                $voiceId,
                $liveVoiceSampleRecording->getAudioSeconds(static::SAMPLE_RATE),
                trim($liveVoiceSampleRecording->transcript)
            ));
        }
        return $liveVoiceSampleRecording;
    }

    /**
     * Books the seconds of an opened sample session through ddd-ai's session usage: priced by the catalog's
     * `costsPerSessionSecondInUSD` of the live model (found by its catalog name or by the provider's id, the catalog's
     * `externalId`), under {@see USAGE_SCOPE_SAMPLE}, with no budget check (an operator's command, not a caller's
     * turn). No Account is billed. An application with a cost ledger of its own overrides this method.
     *
     * @param string $liveModel The live model the session ran on (the adapter's `model`)
     * @param float $sessionSeconds The seconds from opening to closing the session
     * @return void
     * @throws InternalErrorException The live model is not in the catalog: its seconds cannot be priced
     */
    protected function recordSessionSeconds(string $liveModel, float $sessionSeconds): void
    {
        /** @var AIModelsService $aIModelsService */
        $aIModelsService = DDDService::instance()->getService(AIModelsService::class);
        $aiModel = $aIModelsService->getAIModelByName($liveModel) ?? $aIModelsService->getAIModelByExternalId($liveModel, AIModel::TYPE_AUDIO);
        if (!$aiModel instanceof AIModel) {
            throw new InternalErrorException("The live model {$liveModel} of a voice sample is not in the AI model catalog: its seconds cannot be priced");
        }
        $aiModelCall = new AIModelCall(
            argusClass: static::class,
            aiUsageScope: static::USAGE_SCOPE_SAMPLE,
            aiModel: $aiModel,
            enforceBudget: false,
        );
        /** @var AISessionUsageService $aISessionUsageService */
        $aISessionUsageService = DDDService::instance()->getService(AISessionUsageService::class);
        $aISessionUsageService->recordSessionSeconds($aiModelCall, $sessionSeconds);
    }

    /**
     * The first event of the session: the model the calls run on, the voice, the audio format, and a developer
     * message that asks for exactly the sentence. No delegation: the model answers alone.
     *
     * @param string $liveModel
     * @param string $voiceId
     * @param string $sentence
     * @return array<string, mixed>
     */
    public function buildSessionStartEvent(string $liveModel, string $voiceId, string $sentence): array
    {
        return [
            'type' => 'session.start',
            'session' => [
                'model' => $liveModel,
                'instructions' => 'You record a short voice sample. Say exactly the sentence the developer gives you, once,'
                    . ' warmly and naturally, as if you were greeting someone on a phone call. Add nothing before or after'
                    . ' it, then stay silent.',
                'audio' => [
                    'format' => ['type' => 'audio/pcm', 'rate' => static::SAMPLE_RATE],
                    'output' => ['voice' => $voiceId],
                ],
                'input' => [[
                    'type' => 'message',
                    'role' => 'developer',
                    'content' => [['type' => 'input_text', 'text' => "Say exactly this, now: \"{$sentence}\""]],
                ]],
                'store' => false,
            ],
        ];
    }

    /**
     * The session WebSocket of the provider the adapter talks to.
     *
     * @param OpenAILiveVoiceProviderAdapter $liveVoiceProviderAdapter
     * @return string
     */
    public function buildSessionWebSocketUrl(OpenAILiveVoiceProviderAdapter $liveVoiceProviderAdapter): string
    {
        $sidebandWebSocketUrl = $liveVoiceProviderAdapter->buildSidebandWebSocketUrl('sample');
        $hostPart = (string)preg_replace('#' . preg_quote(static::SESSIONS_PATH, '#') . '/.*$#', '', $sidebandWebSocketUrl);
        return $hostPart . static::SESSIONS_PATH;
    }

    /**
     * The adapter of the default provider (its key, host and model).
     *
     * @return OpenAILiveVoiceProviderAdapter
     * @throws InternalErrorException The default provider is no GPT-Live adapter
     */
    public function getLiveVoiceProviderAdapter(): OpenAILiveVoiceProviderAdapter
    {
        $realtimeVoiceProviderAdapter = RealtimeVoiceProviderAdapter::forProviderName(null);
        if (!$realtimeVoiceProviderAdapter instanceof OpenAILiveVoiceProviderAdapter) {
            throw new InternalErrorException('Voice samples need a GPT-Live provider as the default voice provider');
        }
        return $realtimeVoiceProviderAdapter;
    }

    /** @return LoopInterface A new event loop (never the process's global one) */
    protected function buildEventLoop(): LoopInterface
    {
        return new StreamSelectLoop();
    }

    /**
     * The WebSocket client of the session. Without an `Origin` header: Pawl adds one (`https://api.openai.com`), and
     * the provider refuses a server session with a browser origin (HTTP 403 "Forbidden" at the handshake; the sideband
     * attach of a call accepts it).
     *
     * @param LoopInterface $eventLoop
     * @return Connector
     */
    protected function buildConnector(LoopInterface $eventLoop): Connector
    {
        return new OriginlessWebSocketConnector($eventLoop);
    }
}
