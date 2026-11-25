<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Live\Provider;

use DDD\Domain\AI\Entities\Voice\AgentVoiceSettings;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceSeedInputMessage;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceSeedInputMessages;
use DDD\Domain\AI\Services\Voice\Live\OpenAILiveVoiceProviderAdapter;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * The request that creates a GPT-Live session (exactly `session` and `transport` at the top; delegation, storage and
 * the seed inside `session`), the seed caps (128 rows, 6,000 characters per row, 20,000 in all, filled in order),
 * the content type per role, the model and voice from the adapter's settings with the fallback to the default voice,
 * the storage switch, the size limits the seam reads, the close-reason mapping and which attach failure means the
 * session is gone.
 */
class OpenAILiveVoiceProviderAdapterPayloadTest extends AgentVoiceUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith([]));
    }

    public function testTheCreationRequestHasExactlyTheTwoDocumentedTopLevelKeys(): void
    {
        $sessionCreationPayload = (new OpenAILiveVoiceProviderAdapter())->buildSessionCreationPayload('v=0 offer', 'Be brief.', null);

        static::assertSame([
            'session' => [
                'model' => 'gpt-live-1.1',
                'instructions' => 'Be brief.',
                'audio' => ['output' => ['voice' => 'marin']],
                'delegation' => ['type' => 'client'],
            ],
            'transport' => ['type' => 'webrtc', 'sdp' => 'v=0 offer'],
        ], $sessionCreationPayload);
    }

    public function testTheModelAndTheDefaultVoiceComeFromTheAdaptersSettings(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith([
            'provider' => ['openAiLive' => ['model' => ' gpt-live-2 ', 'defaultVoice' => 'willow']],
        ]));
        $openAILiveVoiceProviderAdapter = new OpenAILiveVoiceProviderAdapter();

        $sessionCreationPayload = $openAILiveVoiceProviderAdapter->buildSessionCreationPayload('v=0', 'x', null);

        static::assertSame('gpt-live-2', $sessionCreationPayload['session']['model']);
        static::assertSame('gpt-live-2', $openAILiveVoiceProviderAdapter->getModelName());
        static::assertSame('willow', $sessionCreationPayload['session']['audio']['output']['voice']);
    }

    public function testTheConstantsAnswerWhenTheSettingsNameNoModelAndNoVoice(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith([
            'provider' => ['openAiLive' => ['model' => '', 'defaultVoice' => 7]],
        ]));
        $openAILiveVoiceProviderAdapter = new OpenAILiveVoiceProviderAdapter();

        static::assertSame(OpenAILiveVoiceProviderAdapter::LIVE_MODEL, $openAILiveVoiceProviderAdapter->getLiveModel());
        static::assertSame(OpenAILiveVoiceProviderAdapter::DEFAULT_LIVE_VOICE_ID, $openAILiveVoiceProviderAdapter->resolveLiveVoiceId(null));
    }

    /** @return array<string, array{?string, string}> */
    public static function provideStoredVoices(): array
    {
        return [
            'no settings' => [null, 'marin'],
            'a voice of the catalog' => ['nuri', 'nuri'],
            'the documented default' => ['marin', 'marin'],
            'a voice of another provider' => ['alloy', 'marin'],
            'a voice id in another spelling' => ['Nuri', 'marin'],
        ];
    }

    #[DataProvider('provideStoredVoices')]
    public function testTheStoredVoiceIsSentOnlyWhenGptLiveKnowsIt(?string $storedVoiceId, string $expectedVoiceId): void
    {
        $effectiveVoiceSettings = null;
        if ($storedVoiceId !== null) {
            $effectiveVoiceSettings = new AgentVoiceSettings();
            $effectiveVoiceSettings->voiceId = $storedVoiceId;
        }

        $sessionCreationPayload = (new OpenAILiveVoiceProviderAdapter())->buildSessionCreationPayload('v=0', 'x', $effectiveVoiceSettings);

        static::assertSame($expectedVoiceId, $sessionCreationPayload['session']['audio']['output']['voice']);
    }

    public function testAConfiguredDefaultVoiceOutsideTheCatalogIsSentAndAcceptedAsStored(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['provider' => ['openAiLive' => ['defaultVoice' => 'house-voice']]]));
        $effectiveVoiceSettings = new AgentVoiceSettings();
        $effectiveVoiceSettings->voiceId = 'house-voice';
        $openAILiveVoiceProviderAdapter = new OpenAILiveVoiceProviderAdapter();

        static::assertSame('house-voice', $openAILiveVoiceProviderAdapter->resolveLiveVoiceId($effectiveVoiceSettings));
        static::assertSame('house-voice', $openAILiveVoiceProviderAdapter->resolveLiveVoiceId(null));
    }

    public function testTheStorageSwitchOffSendsNoStorageField(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['provider' => ['openAiLive' => ['storeSessionAtProvider' => false]]]));

        $sessionCreationPayload = (new OpenAILiveVoiceProviderAdapter())->buildSessionCreationPayload('v=0', 'x', null);

        static::assertArrayNotHasKey('store', $sessionCreationPayload['session']);
        static::assertSame(['model', 'instructions', 'audio', 'delegation'], array_keys($sessionCreationPayload['session']));
    }

    /** @return array<string, array{mixed, bool}> */
    public static function provideStorageSwitches(): array
    {
        return [
            'true' => [true, true],
            'the string "true" is no switch' => ['true', false],
            '1 is no switch' => [1, false],
            'not set' => [null, false],
        ];
    }

    #[DataProvider('provideStorageSwitches')]
    public function testOnlyTheBooleanTrueSendsTheDocumentedStoreField(mixed $storeSessionAtProvider, bool $expectsStoreField): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['provider' => ['openAiLive' => ['storeSessionAtProvider' => $storeSessionAtProvider]]]));

        $sessionCreationPayload = (new OpenAILiveVoiceProviderAdapter())->buildSessionCreationPayload('v=0', 'x', null);

        if ($expectsStoreField) {
            static::assertTrue($sessionCreationPayload['session']['store']);
            static::assertSame(['session', 'transport'], array_keys($sessionCreationPayload));
            static::assertSame(['model', 'instructions', 'audio', 'delegation', 'store'], array_keys($sessionCreationPayload['session']));
            return;
        }
        static::assertArrayNotHasKey('store', $sessionCreationPayload['session']);
    }

    public function testSeedRowsCarryOutputTextOnlyForTheAssistantAndBlankRowsAreDropped(): void
    {
        $sessionCreationPayload = (new OpenAILiveVoiceProviderAdapter())->buildSessionCreationPayload('v=0', 'x', null, AgentVoiceSeedInputMessages::fromMessages(
            AgentVoiceSeedInputMessage::create('developer', '  Opening  '),
            AgentVoiceSeedInputMessage::create('user', '   '),
            AgentVoiceSeedInputMessage::create('user', 'Hello'),
            AgentVoiceSeedInputMessage::create('assistant', 'Hi there'),
        ));

        static::assertSame([
            ['type' => 'message', 'role' => 'developer', 'content' => [['type' => 'input_text', 'text' => 'Opening']]],
            ['type' => 'message', 'role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Hello']]],
            ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Hi there']]],
        ], $sessionCreationPayload['session']['input']);
        static::assertSame(['session', 'transport'], array_keys($sessionCreationPayload));
    }

    public function testNoSeedRowsSendNoInputField(): void
    {
        $sessionCreationPayload = (new OpenAILiveVoiceProviderAdapter())->buildSessionCreationPayload('v=0', 'x', null, AgentVoiceSeedInputMessages::fromMessages(AgentVoiceSeedInputMessage::create('developer', ' ')));

        static::assertArrayNotHasKey('input', $sessionCreationPayload['session']);
    }

    public function testNoSetAndAnEmptySetBothSendNoInputField(): void
    {
        $withoutSet = (new OpenAILiveVoiceProviderAdapter())->buildSessionCreationPayload('v=0', 'x', null, null);
        $withEmptySet = (new OpenAILiveVoiceProviderAdapter())->buildSessionCreationPayload('v=0', 'x', null, new AgentVoiceSeedInputMessages());
        $withoutArgument = (new OpenAILiveVoiceProviderAdapter())->buildSessionCreationPayload('v=0', 'x', null);

        static::assertArrayNotHasKey('input', $withoutSet['session']);
        static::assertArrayNotHasKey('input', $withEmptySet['session']);
        static::assertSame($withoutArgument, $withoutSet);
        static::assertSame($withoutArgument, $withEmptySet);
    }

    /**
     * The request without seed rows, byte for byte as the overlay sent it while the seed travelled as a list: the
     * set changed the signature, not the wire.
     */
    public function testTheRequestWithoutSeedRowsIsByteIdenticalToTheListShapedOne(): void
    {
        $expectedRequest = '{"session":{"model":"gpt-live-1.1","instructions":"Be brief.","audio":{"output":{"voice":"marin"}},"delegation":{"type":"client"}},"transport":{"type":"webrtc","sdp":"v=0 offer"}}';

        static::assertSame($expectedRequest, static::encodeRequest((new OpenAILiveVoiceProviderAdapter())->buildSessionCreationPayload('v=0 offer', 'Be brief.', null, null)));
        static::assertSame($expectedRequest, static::encodeRequest((new OpenAILiveVoiceProviderAdapter())->buildSessionCreationPayload('v=0 offer', 'Be brief.', null, new AgentVoiceSeedInputMessages())));
    }

    /**
     * The request with mixed roles, a blank row and untrimmed text, byte for byte as the overlay sent it while the
     * seed travelled as a list: the rows keep the order of the set and the blank row is dropped.
     */
    public function testTheRequestWithSeedRowsIsByteIdenticalToTheListShapedOne(): void
    {
        $sessionCreationPayload = (new OpenAILiveVoiceProviderAdapter())->buildSessionCreationPayload('v=0 offer', 'Be brief.', null, AgentVoiceSeedInputMessages::fromMessages(
            AgentVoiceSeedInputMessage::create('developer', '  Opening  '),
            AgentVoiceSeedInputMessage::create('user', '   '),
            AgentVoiceSeedInputMessage::create('user', 'Hello'),
            AgentVoiceSeedInputMessage::create('assistant', 'Hi there — "quoted" / ünïcödé'),
        ));

        static::assertSame(
            '{"session":{"model":"gpt-live-1.1","instructions":"Be brief.","audio":{"output":{"voice":"marin"}},"delegation":{"type":"client"},"input":['
            . '{"type":"message","role":"developer","content":[{"type":"input_text","text":"Opening"}]},'
            . '{"type":"message","role":"user","content":[{"type":"input_text","text":"Hello"}]},'
            . '{"type":"message","role":"assistant","content":[{"type":"output_text","text":"Hi there — \"quoted\" / ünïcödé"}]}'
            . ']},"transport":{"type":"webrtc","sdp":"v=0 offer"}}',
            static::encodeRequest($sessionCreationPayload)
        );
    }

    public function testTwoSeedRowsWithTheSameRoleAndTextBothTravel(): void
    {
        $seedInputRows = (new OpenAILiveVoiceProviderAdapter())->buildSessionCreationPayload('v=0', 'x', null, AgentVoiceSeedInputMessages::fromMessages(
            AgentVoiceSeedInputMessage::create('user', 'Yes.'),
            AgentVoiceSeedInputMessage::create('assistant', 'Shall I book it?'),
            AgentVoiceSeedInputMessage::create('user', 'Yes.'),
        ))['session']['input'];

        static::assertSame(['Yes.', 'Shall I book it?', 'Yes.'], array_column(array_column(array_column($seedInputRows, 'content'), 0), 'text'));
    }

    /** The request as the probes of the wire render it (JSON without escaped Unicode and slashes). */
    protected static function encodeRequest(array $sessionCreationPayload): string
    {
        return json_encode($sessionCreationPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function testOneSeedRowIsCutTo6000Characters(): void
    {
        $sessionCreationPayload = (new OpenAILiveVoiceProviderAdapter())->buildSessionCreationPayload('v=0', 'x', null, AgentVoiceSeedInputMessages::fromMessages(
            AgentVoiceSeedInputMessage::create('developer', str_repeat('ä', 6001)),
        ));

        static::assertSame(str_repeat('ä', 6000), $sessionCreationPayload['session']['input'][0]['content'][0]['text']);
    }

    public function testTheWholeSeedIsCutAt20000CharactersInOrderAndTheLastRowsGoFirst(): void
    {
        $seedInputMessages = [];
        for ($seedRowNumber = 1; $seedRowNumber <= 5; $seedRowNumber++) {
            $seedInputMessages[] = AgentVoiceSeedInputMessage::create('developer', str_repeat((string)$seedRowNumber, 6000));
        }

        $seedInputRows = (new OpenAILiveVoiceProviderAdapter())->buildSessionCreationPayload('v=0', 'x', null, AgentVoiceSeedInputMessages::fromMessages(...$seedInputMessages))['session']['input'];

        static::assertCount(4, $seedInputRows);
        static::assertSame(str_repeat('3', 6000), $seedInputRows[2]['content'][0]['text']);
        static::assertSame(str_repeat('4', 2000), $seedInputRows[3]['content'][0]['text']);
    }

    public function testAtMost128SeedRowsAreHandedOver(): void
    {
        $seedInputMessages = [];
        for ($seedRowNumber = 1; $seedRowNumber <= 130; $seedRowNumber++) {
            $seedInputMessages[] = AgentVoiceSeedInputMessage::create('user', 'row ' . $seedRowNumber);
        }

        $seedInputRows = (new OpenAILiveVoiceProviderAdapter())->buildSessionCreationPayload('v=0', 'x', null, AgentVoiceSeedInputMessages::fromMessages(...$seedInputMessages))['session']['input'];

        static::assertCount(128, $seedInputRows);
        static::assertSame('row 128', $seedInputRows[127]['content'][0]['text']);
    }

    public function testTheSizeLimitsTheSeamReads(): void
    {
        $openAILiveVoiceProviderAdapter = new OpenAILiveVoiceProviderAdapter();

        static::assertSame(128, $openAILiveVoiceProviderAdapter->getSeedInputMaximumMessages());
        static::assertSame(6000, $openAILiveVoiceProviderAdapter->getSeedInputMessageMaximumCharacters());
        static::assertSame(20000, $openAILiveVoiceProviderAdapter->getSeedInputTotalMaximumCharacters());
        static::assertSame(1200, $openAILiveVoiceProviderAdapter->getAppendMaximumCharacters());
    }

    /** @return array<string, array{string, string}> */
    public static function provideCloseReasons(): array
    {
        return [
            'the owner hung up' => ['remote_hangup', AgentVoiceCall::END_REASON_COMPLETED],
            'a hang-up was requested' => ['close_requested', AgentVoiceCall::END_REASON_COMPLETED],
            'the client dropped its connection' => ['connection_lost', AgentVoiceCall::END_REASON_COMPLETED],
            'the session ran out of time' => ['expired', AgentVoiceCall::END_REASON_LIMIT_REACHED],
            'an undocumented reason' => ['content_filter', AgentVoiceCall::END_REASON_FAILED],
            'an empty reason' => ['', AgentVoiceCall::END_REASON_FAILED],
            'a documented reason in another spelling' => ['EXPIRED', AgentVoiceCall::END_REASON_FAILED],
        ];
    }

    #[DataProvider('provideCloseReasons')]
    public function testTheCloseReasonBecomesTheEndReasonOfTheCall(string $sessionCloseReason, string $expectedEndReason): void
    {
        static::assertSame($expectedEndReason, (new OpenAILiveVoiceProviderAdapter())->mapSessionCloseReasonToEndReason($sessionCloseReason));
    }

    /** @return array<string, array{string, bool}> */
    public static function provideAttachFailures(): array
    {
        return [
            'the provider names the session unknown' => ['{"error":{"code":"session_id_not_found"}}', true],
            'a 404 of the attach over HTTP/1.1' => ['HTTP/1.1 404 Not Found', true],
            'a 404 over HTTP/2' => ['HTTP/2 404 Not Found', true],
            'a 503' => ['HTTP/1.1 503 Service Unavailable', false],
            'a 4040 is no 404' => ['HTTP/1.1 4040', false],
            'a 404 not at the start' => ['upgrade failed: HTTP/1.1 404', false],
            'a refused connection' => ['Connection refused', false],
        ];
    }

    #[DataProvider('provideAttachFailures')]
    public function testOnlyAnUnknownSessionCountsAsGone(string $failureMessage, bool $expectedGone): void
    {
        static::assertSame($expectedGone, (new OpenAILiveVoiceProviderAdapter())->isSessionGoneConnectFailure(new RuntimeException($failureMessage)));
    }
}
