<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker\Samples;

use DDD\Domain\AI\Entities\ModelCalls\AIModelCall;
use DDD\Domain\AI\Entities\ModelCalls\AIModelCallUsage;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Domain\AI\Services\AISessionUsageService;
use DDD\Domain\AI\Services\Voice\Samples\LiveVoiceSampleRecording;
use DDD\Domain\AI\Services\Voice\SessionWorker\Samples\LiveVoiceSampleSynthesizer;
use DDD\Domain\AI\Services\Voice\SessionWorker\Samples\OriginlessWebSocketConnector;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Tests\Agents\Voice\Doubles\FixedModelOpenAILiveVoiceProviderAdapter;
use DDD\Tests\Agents\Voice\Support\AgentVoiceIntegrationTestCase;
use Psr\Http\Message\RequestInterface;
use React\EventLoop\Loop;

/**
 * The sample session on the wire: the start event (model, voice, format, the sentence, nothing stored), the sessions
 * endpoint, the events a recording reads (audio, transcript, errors, close; unknown ones ignored), the end after quiet
 * frames, a handshake without `Origin`, a loop of its own per sample, and the booking of the session's seconds through
 * ddd-ai's session usage under the sample scope, priced by the live model found by its provider id.
 */
class LiveVoiceSampleSynthesizerTest extends AgentVoiceIntegrationTestCase
{
    public function testTheSessionStartsWithTheModelTheVoiceTheFormatAndTheSentenceAndStoresNothing(): void
    {
        $sessionStartEvent = (new LiveVoiceSampleSynthesizer())->buildSessionStartEvent('gpt-live-1.1', 'flitz', 'Hey, ich bin Mira.');
        static::assertSame('session.start', $sessionStartEvent['type']);
        static::assertSame('gpt-live-1.1', $sessionStartEvent['session']['model']);
        static::assertSame(['type' => 'audio/pcm', 'rate' => 24000], $sessionStartEvent['session']['audio']['format']);
        static::assertSame(['voice' => 'flitz'], $sessionStartEvent['session']['audio']['output']);
        static::assertFalse($sessionStartEvent['session']['store']);
        static::assertArrayNotHasKey('delegation', $sessionStartEvent['session']);
        static::assertSame('developer', $sessionStartEvent['session']['input'][0]['role']);
        static::assertStringContainsString('"Hey, ich bin Mira."', $sessionStartEvent['session']['input'][0]['content'][0]['text']);
    }

    public function testTheSessionUrlIsTheSessionsEndpointOfTheAdaptersHost(): void
    {
        $liveVoiceSampleSynthesizer = new LiveVoiceSampleSynthesizer();
        static::assertSame(
            'wss://api.openai.com/v1/live/sessions',
            $liveVoiceSampleSynthesizer->buildSessionWebSocketUrl(new FixedModelOpenAILiveVoiceProviderAdapter())
        );
        static::assertSame(
            'wss://eu.api.openai.com/v1/live/sessions',
            $liveVoiceSampleSynthesizer->buildSessionWebSocketUrl(new FixedModelOpenAILiveVoiceProviderAdapter('gpt-live-1.1', 'eu.api.openai.com'))
        );
    }

    public function testTheRecordingCollectsAudioAndTranscriptAndEndsAfterQuiet(): void
    {
        $liveVoiceSampleRecording = new LiveVoiceSampleRecording();
        $liveVoiceSampleRecording->applySessionEvent('{"type":"session.started","session":{"model":"gpt-live-1.1"}}', 1.0);
        static::assertTrue($liveVoiceSampleRecording->sessionStarted);
        static::assertFalse($liveVoiceSampleRecording->hasFinishedSpeaking(10.0, 1.5), 'no audio yet');
        $speechChunk = str_repeat(pack('v', 6000), 240);
        $liveVoiceSampleRecording->applySessionEvent((string)json_encode(['type' => 'session.output_audio.delta', 'delta' => base64_encode($speechChunk)]), 2.0);
        $liveVoiceSampleRecording->applySessionEvent((string)json_encode(['type' => 'session.output_audio.delta', 'delta' => base64_encode($speechChunk)]), 2.5);
        $liveVoiceSampleRecording->applySessionEvent('{"type":"session.output_transcript.delta","delta":"Hey, "}', 2.5);
        $liveVoiceSampleRecording->applySessionEvent('{"type":"session.output_transcript.delta","delta":"ich bin Mira."}', 2.6);
        $liveVoiceSampleRecording->applySessionEvent('{"type":"some.future.event"}', 2.7);
        $liveVoiceSampleRecording->applySessionEvent('not json', 2.7);
        static::assertSame($speechChunk . $speechChunk, $liveVoiceSampleRecording->pcmAudio);
        static::assertSame('Hey, ich bin Mira.', $liveVoiceSampleRecording->transcript);
        static::assertFalse($liveVoiceSampleRecording->hasFinishedSpeaking(3.0, 1.5));
        static::assertTrue($liveVoiceSampleRecording->hasFinishedSpeaking(4.0, 1.5));
        $liveVoiceSampleRecording->applySessionEvent('{"type":"error","error":{"message":"bad voice"}}', 4.1);
        $liveVoiceSampleRecording->applySessionEvent('{"type":"session.closed","reason":"client_closed","usage":{"seconds":4}}', 4.2);
        static::assertSame(['bad voice'], $liveVoiceSampleRecording->errorMessages);
        static::assertTrue($liveVoiceSampleRecording->sessionClosed);
        static::assertSame('client_closed', $liveVoiceSampleRecording->closeReason);
        static::assertSame(1, $liveVoiceSampleRecording->eventTypeCounts['some.future.event']);
        static::assertSame(2, $liveVoiceSampleRecording->eventTypeCounts['session.output_audio.delta']);
    }

    public function testQuietFramesAfterTheSpeechDoNotKeepTheSampleOpen(): void
    {
        $liveVoiceSampleRecording = new LiveVoiceSampleRecording();
        $speechChunk = str_repeat(pack('v', 6000), 240);
        $quietChunk = str_repeat(pack('v', 3), 240);
        $liveVoiceSampleRecording->applySessionEvent((string)json_encode(['type' => 'session.output_audio.delta', 'delta' => base64_encode($speechChunk)]), 1.0);
        foreach ([1.5, 2.0, 2.5, 3.0] as $receivedAt) {
            $liveVoiceSampleRecording->applySessionEvent((string)json_encode(['type' => 'session.output_audio.delta', 'delta' => base64_encode($quietChunk)]), $receivedAt);
        }
        static::assertSame(3.0, $liveVoiceSampleRecording->lastAudioReceivedAt);
        static::assertSame(1.0, $liveVoiceSampleRecording->lastSpeechReceivedAt);
        static::assertTrue($liveVoiceSampleRecording->hasFinishedSpeaking(3.0, 1.5), 'quiet frames are no speech');
        static::assertEqualsWithDelta(0.05, $liveVoiceSampleRecording->getAudioSeconds(24000), 0.0001);
        static::assertFalse(LiveVoiceSampleRecording::containsSpeech($quietChunk));
        static::assertTrue(LiveVoiceSampleRecording::containsSpeech(str_repeat(pack('v', 65536 - 6000), 2)), 'a negative amplitude counts');
    }

    public function testTheSessionHandshakeCarriesNoOrigin(): void
    {
        $originlessWebSocketConnector = new class (Loop::get()) extends OriginlessWebSocketConnector {
            /** @param array<string, string> $headers */
            public function buildRequest(string $url, array $headers): RequestInterface
            {
                return $this->generateRequest($url, [], $headers);
            }
        };
        $handshakeRequest = $originlessWebSocketConnector->buildRequest('wss://api.openai.com/v1/live/sessions', ['Authorization' => 'Bearer test']);
        static::assertFalse($handshakeRequest->hasHeader('Origin'), 'OpenAI answers 403 to a server session with an Origin');
        static::assertSame('Bearer test', $handshakeRequest->getHeaderLine('Authorization'));
    }

    public function testEverySampleRunsOnALoopOfItsOwn(): void
    {
        $liveVoiceSampleSynthesizer = new class extends LiveVoiceSampleSynthesizer {
            public function buildEventLoopForTest(): \React\EventLoop\LoopInterface
            {
                return $this->buildEventLoop();
            }
        };
        $firstEventLoop = $liveVoiceSampleSynthesizer->buildEventLoopForTest();
        static::assertNotSame(Loop::get(), $firstEventLoop);
        static::assertNotSame($firstEventLoop, $liveVoiceSampleSynthesizer->buildEventLoopForTest());
    }

    public function testTheSecondsAreBookedUnderTheSampleScopePricedByTheModelFoundByItsProviderId(): void
    {
        $bookedModelCalls = [];
        $aISessionUsageService = $this->createMock(AISessionUsageService::class);
        $aISessionUsageService->expects(static::once())->method('recordSessionSeconds')->willReturnCallback(
            function (AIModelCall $aiModelCall, float $sessionSeconds) use (&$bookedModelCalls): AIModelCallUsage {
                $bookedModelCalls[] = [$aiModelCall, $sessionSeconds];

                return $this->createStub(AIModelCallUsage::class);
            }
        );
        $this->replaceService(AISessionUsageService::class, $aISessionUsageService);

        $this->buildBookingSynthesizer()->recordSessionSecondsForTest('gpt-live-1.1', 8.4);

        static::assertCount(1, $bookedModelCalls);
        [$aiModelCall, $sessionSeconds] = $bookedModelCalls[0];
        static::assertSame(8.4, $sessionSeconds);
        static::assertSame(LiveVoiceSampleSynthesizer::USAGE_SCOPE_SAMPLE, $aiModelCall->aiUsageScope);
        static::assertSame('AI.VOICE.SAMPLE', $aiModelCall->aiUsageScope);
        static::assertFalse($aiModelCall->enforceBudget, 'an operator command is never refused by a budget');
        static::assertSame(AIModel::MODEL_OPENAI_GPT_LIVE_1_1, $aiModelCall->aiModel->name);
        static::assertNull($aiModelCall->accountBilledForAICosts, "no Account is billed");
    }

    public function testAModelOutsideTheCatalogIsRefusedInsteadOfBookedWithoutAPrice(): void
    {
        $aIModelsService = $this->createMock(AIModelsService::class);
        $aIModelsService->method('getAIModelByName')->willReturn(null);
        $aIModelsService->method('getAIModelByExternalId')->willReturn(null);
        $this->replaceService(AIModelsService::class, $aIModelsService);
        $aISessionUsageService = $this->createMock(AISessionUsageService::class);
        $aISessionUsageService->expects(static::never())->method('recordSessionSeconds');
        $this->replaceService(AISessionUsageService::class, $aISessionUsageService);

        $this->expectException(InternalErrorException::class);
        $this->buildBookingSynthesizer()->recordSessionSecondsForTest('gpt-live-unknown', 3.0);
    }

    protected function buildBookingSynthesizer(): LiveVoiceSampleSynthesizer
    {
        return new class extends LiveVoiceSampleSynthesizer {
            public function recordSessionSecondsForTest(string $liveModel, float $sessionSeconds): void
            {
                $this->recordSessionSeconds($liveModel, $sessionSeconds);
            }
        };
    }
}
