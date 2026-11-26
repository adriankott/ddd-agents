<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Voice\Samples;

/**
 * What one sample session of the live model produced, as the events of its WebSocket arrive: the speech as raw PCM
 * (mono, signed 16-bit little-endian, at the session's rate), the transcript of what the model said, the provider's
 * error messages and when the last audio came. Fed by {@see LiveVoiceSampleSynthesizer}; holds no connection, so a test
 * feeds it recorded wire events.
 *
 * Wire shapes (OpenAI GPT-Live over WebSocket, developers.openai.com/api/docs/guides/voice-websockets?api=live):
 * `session.started`, `session.output_audio.delta` (`delta`: base64 audio in the session's format),
 * `session.output_transcript.delta` (`delta`: text), `error` (`error.message`), `session.closed` (`reason`).
 */
class LiveVoiceSampleRecording
{
    /** @var int The sample rate of a sample session's audio (mono, signed 16-bit little-endian PCM) */
    public const int SAMPLE_RATE = 24000;

    /** @var bool Whether the provider confirmed the session (`session.started`) */
    public bool $sessionStarted = false;

    /** @var bool Whether the provider closed the session (`session.closed`) */
    public bool $sessionClosed = false;

    /** @var string|null Why the provider closed the session (`session.closed` → `reason`) */
    public ?string $closeReason = null;

    /** @var string The speech received so far: raw PCM, mono, signed 16-bit little-endian */
    public string $pcmAudio = '';

    /** @var string What the model said, as its output transcript */
    public string $transcript = '';

    /** @var string[] The messages of the provider's `error` events */
    public array $errorMessages = [];

    /** @var float|null When the last audio arrived (microtime); null before the first */
    public ?float $lastAudioReceivedAt = null;

    /** @var array<string, int> Event type => how often it came (diagnosis of a session without speech) */
    public array $eventTypeCounts = [];

    /** @var float|null When the last audio with speech in it arrived (microtime); null before the first */
    public ?float $lastSpeechReceivedAt = null;

    /** @var int The amplitude from which a 16-bit sample counts as speech (below: the provider's quiet frames) */
    public const int SPEECH_AMPLITUDE_THRESHOLD = 500;

    /**
     * Applies one event of the session's WebSocket. Unknown events are ignored (the provider adds event types).
     *
     * @param string $rawSessionEvent The JSON text of the event
     * @param float $receivedAt When it arrived (microtime)
     * @return void
     */
    public function applySessionEvent(string $rawSessionEvent, float $receivedAt): void
    {
        $sessionEvent = json_decode($rawSessionEvent, true);
        if (!is_array($sessionEvent)) {
            return;
        }
        $eventType = (string)($sessionEvent['type'] ?? '');
        $this->eventTypeCounts[$eventType] = ($this->eventTypeCounts[$eventType] ?? 0) + 1;
        if ($eventType === 'session.started') {
            $this->sessionStarted = true;
            return;
        }
        if ($eventType === 'session.output_audio.delta') {
            $decodedAudio = base64_decode((string)($sessionEvent['delta'] ?? ''), true);
            if (is_string($decodedAudio) && $decodedAudio !== '') {
                $this->pcmAudio .= $decodedAudio;
                $this->lastAudioReceivedAt = $receivedAt;
                if (static::containsSpeech($decodedAudio)) {
                    $this->lastSpeechReceivedAt = $receivedAt;
                }
            }
            return;
        }
        if ($eventType === 'session.output_transcript.delta') {
            $this->transcript .= (string)($sessionEvent['delta'] ?? '');
            return;
        }
        if ($eventType === 'error') {
            $errorMessage = $sessionEvent['error']['message'] ?? $sessionEvent['error']['code'] ?? 'unknown provider error';
            $this->errorMessages[] = (string)$errorMessage;
            return;
        }
        if ($eventType === 'session.closed') {
            $this->sessionClosed = true;
            $this->closeReason = isset($sessionEvent['reason']) ? (string)$sessionEvent['reason'] : null;
        }
    }

    /**
     * Whether the model has finished speaking: speech came, and none for `$quietSeconds` since. GPT-Live sends no
     * "audio done" event and keeps streaming quiet audio after the speech, so the end is the last loud audio.
     *
     * @param float $now microtime
     * @param float $quietSeconds
     * @return bool
     */
    public function hasFinishedSpeaking(float $now, float $quietSeconds): bool
    {
        return $this->lastSpeechReceivedAt !== null && ($now - $this->lastSpeechReceivedAt) >= $quietSeconds;
    }

    /** @return float The seconds of audio received (mono 16-bit at `$sampleRate`) */
    public function getAudioSeconds(int $sampleRate): float
    {
        return strlen($this->pcmAudio) / 2 / $sampleRate;
    }

    /**
     * Whether a PCM chunk (mono, signed 16-bit little-endian) holds a sample at or above {@see SPEECH_AMPLITUDE_THRESHOLD}.
     *
     * @param string $pcmChunk
     * @return bool
     */
    public static function containsSpeech(string $pcmChunk): bool
    {
        $sampleCount = intdiv(strlen($pcmChunk), 2);
        if ($sampleCount === 0) {
            return false;
        }
        foreach (unpack('v*', substr($pcmChunk, 0, $sampleCount * 2)) ?: [] as $unsignedSample) {
            $signedSample = $unsignedSample >= 32768 ? $unsignedSample - 65536 : $unsignedSample;
            if (abs($signedSample) >= static::SPEECH_AMPLITUDE_THRESHOLD) {
                return true;
            }
        }
        return false;
    }
}
