<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Doubles;

use DDD\Domain\AI\Services\Voice\Samples\LiveVoiceSampleRecording;
use DDD\Domain\AI\Services\Voice\SessionWorker\Samples\LiveVoiceSampleSynthesizer;

/**
 * A sample synthesizer without a provider: it answers a fixed speech (a second of silence, 0.2 s of loud samples, a
 * second of silence) and records what it was asked to say.
 */
class RecordingLiveVoiceSampleSynthesizer extends LiveVoiceSampleSynthesizer
{
    /** @var array<int, array{0: string, 1: string}> voice, sentence */
    public array $synthesizedRequests = [];

    public function synthesize(string $voiceId, string $sentence): LiveVoiceSampleRecording
    {
        $this->synthesizedRequests[] = [$voiceId, $sentence];
        $liveVoiceSampleRecording = new LiveVoiceSampleRecording();
        $liveVoiceSampleRecording->pcmAudio = str_repeat(pack('v', 0), 24000)
            . str_repeat(pack('v', 8000) . pack('v', 65536 - 8000), 2400)
            . str_repeat(pack('v', 0), 24000);
        $liveVoiceSampleRecording->transcript = $sentence;

        return $liveVoiceSampleRecording;
    }
}
