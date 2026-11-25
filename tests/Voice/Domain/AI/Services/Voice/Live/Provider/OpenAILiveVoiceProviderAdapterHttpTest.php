<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Live\Provider;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceSeedInputMessage;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceSeedInputMessages;
use DDD\Domain\AI\Services\Voice\Live\LiveSessionCreationResponse;
use DDD\Infrastructure\Exceptions\BadGatewayException;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * The two REST calls of the GPT-Live adapter (creating a session from the client's SDP offer, hanging it up) and the
 * attach of the session worker, staged with an HTTP client that answers canned responses
 * ({@see RecordingOpenAILiveVoiceProviderAdapter}): the URLs come from `apiHost`, the key from the environment variable
 * the settings name (a variable of this test only, never a real one), and a refusal, an unusable answer or a transport
 * failure is one `BadGatewayException('provider_unavailable')` with the provider's words in the previous exception.
 */
class OpenAILiveVoiceProviderAdapterHttpTest extends AgentVoiceUnitTestCase
{
    /** @var string A variable name no environment carries; set for the test only */
    protected const string TEST_API_KEY_VARIABLE = 'DDD_AGENT_VOICE_TEST_ONLY_LIVE_KEY_7F3A';

    /** @var string A fake key value */
    protected const string TEST_API_KEY = 'test-key-not-a-secret';

    protected const string EU_API_HOST = 'eu.live-provider.test';

    protected bool $hadTestVariableBefore = false;

    protected mixed $testVariableValueBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hadTestVariableBefore = array_key_exists(static::TEST_API_KEY_VARIABLE, $_ENV);
        $this->testVariableValueBefore = $_ENV[static::TEST_API_KEY_VARIABLE] ?? null;
        $_ENV[static::TEST_API_KEY_VARIABLE] = static::TEST_API_KEY;
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith([
            'provider' => ['openAiLive' => [
                'apiHost' => static::EU_API_HOST,
                'apiKeyEnvironmentVariable' => static::TEST_API_KEY_VARIABLE,
                'model' => 'gpt-live-1',
                'defaultVoice' => 'marin',
                'storeSessionAtProvider' => false,
            ]],
        ]));
    }

    protected function tearDown(): void
    {
        if ($this->hadTestVariableBefore) {
            $_ENV[static::TEST_API_KEY_VARIABLE] = $this->testVariableValueBefore;
        } else {
            unset($_ENV[static::TEST_API_KEY_VARIABLE]);
        }
        parent::tearDown();
    }

    public function testASessionIsCreatedWithOnePostToTheSessionsPathOfTheConfiguredHost(): void
    {
        $recordingAdapter = new RecordingOpenAILiveVoiceProviderAdapter();
        $recordingAdapter->queuedAnswers = [new Response(201, [], '{"session":{"id":"live_abc"},"transport":{"sdp":"v=0 answer"}}')];
        $sessionCreationPayload = $recordingAdapter->buildSessionCreationPayload('v=0 offer', 'Be brief.', null, AgentVoiceSeedInputMessages::fromMessages(AgentVoiceSeedInputMessage::create('developer', 'Seed')));

        $liveSessionCreationResponse = $recordingAdapter->createSessionFromSdpOffer($sessionCreationPayload);

        static::assertInstanceOf(LiveSessionCreationResponse::class, $liveSessionCreationResponse);
        static::assertSame('live_abc', $liveSessionCreationResponse->providerSessionId);
        static::assertSame('v=0 answer', $liveSessionCreationResponse->sdpAnswer);
        static::assertCount(1, $recordingAdapter->sentRequests);
        $sentRequest = $recordingAdapter->getSentRequest(0);
        static::assertSame('POST', $sentRequest->getMethod());
        static::assertSame('https://' . static::EU_API_HOST . '/v1/live/sessions', (string)$sentRequest->getUri());
        static::assertSame('Bearer ' . static::TEST_API_KEY, $sentRequest->getHeaderLine('Authorization'));
        static::assertSame('application/json', $sentRequest->getHeaderLine('Content-Type'));
        static::assertSame($sessionCreationPayload, $recordingAdapter->getSentRequestJsonBody(0));
        static::assertSame([10], $recordingAdapter->builtClientTimeoutSeconds);
    }

    public function testTheKeyComesFromTheVariableTheSettingsNameAndNeverFromTheConfig(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith([
            'provider' => ['openAiLive' => ['apiKeyEnvironmentVariable' => static::TEST_API_KEY_VARIABLE, 'apiKey' => 'a-key-in-the-config']],
        ]));
        $recordingAdapter = new RecordingOpenAILiveVoiceProviderAdapter();

        static::assertSame(static::TEST_API_KEY, $recordingAdapter->getApiKey());
        static::assertSame(['Authorization' => 'Bearer ' . static::TEST_API_KEY], $recordingAdapter->buildSidebandWebSocketHeaders());
    }

    /** @return array<string, array{?string}> */
    public static function provideMissingKeys(): array
    {
        return [
            'the variable is not set' => [null],
            'the variable is empty' => [''],
            'the variable holds only white space' => ["  \t "],
        ];
    }

    #[DataProvider('provideMissingKeys')]
    public function testWithoutAKeyNoSessionIsRequestedAndTheVariableIsNamed(?string $keyValue): void
    {
        if ($keyValue === null) {
            unset($_ENV[static::TEST_API_KEY_VARIABLE]);
        } else {
            $_ENV[static::TEST_API_KEY_VARIABLE] = $keyValue;
        }
        $recordingAdapter = new RecordingOpenAILiveVoiceProviderAdapter();
        $recordingAdapter->queuedAnswers = [new Response(201, [], '{"id":"live_1","sdp":"v=0"}')];

        $providerFailure = null;
        try {
            $recordingAdapter->createSessionFromSdpOffer($recordingAdapter->buildSessionCreationPayload('v=0 offer', 'Be brief.', null));
        } catch (BadGatewayException $badGatewayException) {
            $providerFailure = $badGatewayException;
        }

        static::assertInstanceOf(BadGatewayException::class, $providerFailure);
        static::assertSame('provider_unavailable', $providerFailure->getMessage());
        static::assertSame(502, $providerFailure->getCode());
        $expectedProviderFailureText = 'GPT-Live was not asked: the environment variable ' . static::TEST_API_KEY_VARIABLE
            . ' holds no key (read through Config::getEnv(), that is from $_ENV)';
        static::assertSame($expectedProviderFailureText, $providerFailure->getPrevious()?->getMessage());
        static::assertSame($expectedProviderFailureText, $recordingAdapter->describeProviderFailure($providerFailure));
        static::assertCount(0, $recordingAdapter->sentRequests, 'no request leaves without a key');
        static::assertSame([], $recordingAdapter->builtClientTimeoutSeconds, 'no HTTP client is built');
    }

    public function testTheRefusalNamesTheConfiguredVariableAndNeverAKeyOfAnotherVariable(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith([
            'provider' => ['openAiLive' => ['apiKeyEnvironmentVariable' => 'DDD_AGENT_VOICE_TEST_ONLY_UNSET_VARIABLE_9C1E']],
        ]));
        $recordingAdapter = new RecordingOpenAILiveVoiceProviderAdapter();

        try {
            $recordingAdapter->createSessionFromSdpOffer([]);
            static::fail('a missing key must be refused');
        } catch (BadGatewayException $badGatewayException) {
            static::assertSame(
                'GPT-Live was not asked: the environment variable DDD_AGENT_VOICE_TEST_ONLY_UNSET_VARIABLE_9C1E holds no key (read through Config::getEnv(), that is from $_ENV)',
                $badGatewayException->getPrevious()?->getMessage()
            );
            static::assertStringNotContainsString(static::TEST_API_KEY, (string)$badGatewayException->getPrevious()?->getMessage());
        }
        static::assertCount(0, $recordingAdapter->sentRequests);
    }

    public function testTheUrlsUseTheDefaultHostWhenTheSettingsNameNone(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith([
            'provider' => ['openAiLive' => ['apiHost' => '  ', 'apiKeyEnvironmentVariable' => static::TEST_API_KEY_VARIABLE]],
        ]));
        $recordingAdapter = new RecordingOpenAILiveVoiceProviderAdapter();
        $recordingAdapter->queuedAnswers = [new Response(200)];

        static::assertSame('wss://api.openai.com/v1/live/sessions/live_1/attach', $recordingAdapter->buildSidebandWebSocketUrl('live_1'));
        static::assertTrue($recordingAdapter->hangUpSession('live_1'));
        static::assertSame('https://api.openai.com/v1/live/sessions/live_1/hangup', (string)$recordingAdapter->getSentRequest(0)->getUri());
    }

    public function testTheAttachUrlIsBuiltFromTheConfiguredHostAndEncodesTheSessionId(): void
    {
        $recordingAdapter = new RecordingOpenAILiveVoiceProviderAdapter();

        static::assertSame('wss://' . static::EU_API_HOST . '/v1/live/sessions/live_1/attach', $recordingAdapter->buildSidebandWebSocketUrl('live_1'));
        static::assertSame('wss://' . static::EU_API_HOST . '/v1/live/sessions/live%2F..%3Fx/attach', $recordingAdapter->buildSidebandWebSocketUrl('live/..?x'));
    }

    public function testAHangUpPostsAnEmptyObjectAndAnswersTrueOnA2xx(): void
    {
        $recordingAdapter = new RecordingOpenAILiveVoiceProviderAdapter();
        $recordingAdapter->queuedAnswers = [new Response(204)];

        static::assertTrue($recordingAdapter->hangUpSession('live_9'));

        $sentRequest = $recordingAdapter->getSentRequest(0);
        static::assertSame('POST', $sentRequest->getMethod());
        static::assertSame('https://' . static::EU_API_HOST . '/v1/live/sessions/live_9/hangup', (string)$sentRequest->getUri());
        static::assertSame('{}', (string)$sentRequest->getBody());
        static::assertSame('Bearer ' . static::TEST_API_KEY, $sentRequest->getHeaderLine('Authorization'));
        static::assertSame([10], $recordingAdapter->builtClientTimeoutSeconds);
        static::assertSame([], $recordingAdapter->voiceLogLines);
    }

    public function testAHangUpOfAGoneSessionAnswersFalseAndLogsTheRefusalWithoutTheKey(): void
    {
        $recordingAdapter = new RecordingOpenAILiveVoiceProviderAdapter();
        $recordingAdapter->queuedAnswers = [new Response(404, [], '{"error":{"code":"session_id_not_found"}}')];

        static::assertFalse($recordingAdapter->hangUpSession('live_9'));

        static::assertSame(
            ['LIVE live_9 hangup REFUSED HTTP 404: {"error":{"code":"session_id_not_found"}} — sent []'],
            $recordingAdapter->voiceLogLines
        );
        static::assertStringNotContainsString(static::TEST_API_KEY, $recordingAdapter->voiceLogLines[0]);
    }

    /** @return array<string, array{Response, string}> */
    public static function provideUnusableCreationAnswers(): array
    {
        return [
            'a refusal' => [
                new Response(400, [], '{"error":"invalid voice"}'),
                'GPT-Live refused the SDP offer (HTTP 400): {"error":"invalid voice"}',
            ],
            'a redirect counts as a refusal' => [
                new Response(302, [], 'moved'),
                'GPT-Live refused the SDP offer (HTTP 302): moved',
            ],
            'a server error' => [
                new Response(503, [], 'unavailable'),
                'GPT-Live refused the SDP offer (HTTP 503): unavailable',
            ],
            'a body that is no JSON' => [
                new Response(201, [], '<html>ok</html>'),
                'GPT-Live accepted the SDP offer but answered with a non-JSON body.',
            ],
            'a session id without an SDP' => [
                new Response(201, [], '{"session":{"id":"live_1"},"status":"ok"}'),
                'GPT-Live accepted the SDP offer but answered without a usable session id or SDP — keys: session,status',
            ],
            'an SDP without a session id' => [
                new Response(201, [], '{"transport":{"sdp":"v=0"}}'),
                'GPT-Live accepted the SDP offer but answered without a usable session id or SDP — keys: transport',
            ],
        ];
    }

    #[DataProvider('provideUnusableCreationAnswers')]
    public function testAnUnusableCreationAnswerIsProviderUnavailableWithTheProvidersWords(Response $providerAnswer, string $expectedProviderFailureText): void
    {
        $recordingAdapter = new RecordingOpenAILiveVoiceProviderAdapter();
        $recordingAdapter->queuedAnswers = [$providerAnswer];

        $providerFailure = null;
        try {
            $recordingAdapter->createSessionFromSdpOffer(['session' => [], 'transport' => []]);
        } catch (BadGatewayException $badGatewayException) {
            $providerFailure = $badGatewayException;
        }

        static::assertInstanceOf(BadGatewayException::class, $providerFailure);
        static::assertSame('provider_unavailable', $providerFailure->getMessage());
        static::assertSame(502, $providerFailure->getCode());
        static::assertInstanceOf(RuntimeException::class, $providerFailure->getPrevious());
        static::assertSame($expectedProviderFailureText, $providerFailure->getPrevious()->getMessage());
        static::assertNull($providerFailure->getPrevious()->getPrevious());
        static::assertSame($expectedProviderFailureText, $recordingAdapter->describeProviderFailure($providerFailure));
    }

    public function testARefusalBodyIsCutTo500CharactersInTheProvidersWords(): void
    {
        $recordingAdapter = new RecordingOpenAILiveVoiceProviderAdapter();
        $recordingAdapter->queuedAnswers = [new Response(400, [], str_repeat('x', 600))];

        try {
            $recordingAdapter->createSessionFromSdpOffer([]);
            static::fail('a refusal must throw');
        } catch (BadGatewayException $badGatewayException) {
            static::assertSame('GPT-Live refused the SDP offer (HTTP 400): ' . str_repeat('x', 500), $badGatewayException->getPrevious()?->getMessage());
        }
    }

    public function testATimeoutIsProviderUnavailableWithTheTransportFailureKept(): void
    {
        $recordingAdapter = new RecordingOpenAILiveVoiceProviderAdapter();
        $transportFailure = new ConnectException('cURL error 28: Operation timed out', new Request('POST', 'https://' . static::EU_API_HOST . '/v1/live/sessions'));
        $recordingAdapter->queuedAnswers = [$transportFailure];

        try {
            $recordingAdapter->createSessionFromSdpOffer([]);
            static::fail('a timeout must throw');
        } catch (BadGatewayException $badGatewayException) {
            static::assertSame('provider_unavailable', $badGatewayException->getMessage());
            static::assertSame(
                'GPT-Live could not be reached with the SDP offer: cURL error 28: Operation timed out',
                $badGatewayException->getPrevious()?->getMessage()
            );
            static::assertSame($transportFailure, $badGatewayException->getPrevious()?->getPrevious());
            static::assertSame(
                'GPT-Live could not be reached with the SDP offer: cURL error 28: Operation timed out',
                $recordingAdapter->describeProviderFailure($badGatewayException)
            );
        }
    }
}
