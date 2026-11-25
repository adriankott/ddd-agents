<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Ring;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceRingCallerContext;
use DDD\Domain\Base\Entities\StaticRegistry;

/**
 * The `callerContext` object of the published ring: the package's own context renders `"callerContext":{}` in a
 * payload whose bytes are pinned whole; {@see AgentVoiceRingCallerContext::newInstance()} answers the application's
 * class when one is bound at the package class's path; an application's context with fields appears in the
 * published `payloadJson` exactly as its `toPayloadArray()` names them, with their types, at the end of the payload.
 *
 * The binding of an application's class is staged as the framework's same-path lookup records it
 * (`StaticRegistry::$frameworkToAppClassNames`) and put back after each test.
 */
class AgentVoiceRingCallerContextTest extends AgentVoiceRingStagingTestCase
{
    /** @var array<string, string> The bindings before the test */
    protected array $frameworkToAppClassNamesBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->frameworkToAppClassNamesBefore = StaticRegistry::$frameworkToAppClassNames;
    }

    protected function onTearDown(): void
    {
        StaticRegistry::$frameworkToAppClassNames = $this->frameworkToAppClassNamesBefore;
        parent::onTearDown();
    }

    public function testThePackagesOwnContextRendersAnEmptyObjectInAPayloadPinnedByteForByte(): void
    {
        $nowUnixTime = time();
        $agentVoiceCall = $this->storeRingingCall(501, $nowUnixTime, 90);

        $this->getRingService()->publishRing($agentVoiceCall);

        static::assertSame(
            '{"callId":501,"conversationId":300,"answerToken":' . json_encode($this->getRingService()->mintAnswerToken($agentVoiceCall), JSON_UNESCAPED_SLASHES)
            . ',"ringExpiresAt":"' . gmdate('Y-m-d\TH:i:s', $nowUnixTime + 90) . '.000Z","ringExpiresAtUnixMs":' . (($nowUnixTime + 90) * 1000)
            . ',"callerLabelKey":"%agentName% wants to talk","callerContext":{}}',
            $this->realtimeChannel->publishedMessages[0]->payloadJson
        );
    }

    public function testNewInstanceAnswersThePackagesClassUnlessAnApplicationsClassIsBound(): void
    {
        static::assertSame(AgentVoiceRingCallerContext::class, AgentVoiceRingCallerContext::newInstance()::class);

        $this->bindTheApplicationsCallerContext();

        static::assertSame(ApplicationAgentVoiceRingCallerContext::class, AgentVoiceRingCallerContext::newInstance()::class);
    }

    public function testABoundContextWithoutValuesIsPublishedWithItsNamesAndEmptyValues(): void
    {
        $this->bindTheApplicationsCallerContext();
        $agentVoiceCall = $this->storeRingingCall(501, time(), 90);

        $this->getRingService()->publishRing($agentVoiceCall);

        static::assertStringEndsWith(
            ',"callerContext":{"ringingSpaceId":null,"ringingSpaceName":""}}',
            $this->realtimeChannel->publishedMessages[0]->payloadJson,
            'the package\'s buildCallerContext() hands out the application\'s class'
        );
    }

    public function testAnApplicationsFilledContextIsPublishedExactlyAsItsPayloadArrayNamesIt(): void
    {
        $this->bindTheApplicationsCallerContext();
        $agentVoiceCall = $this->storeRingingCall(501, time(), 90);

        (new CallerContextFillingAgentVoiceRingService())->publishRing($agentVoiceCall);

        $payloadJson = $this->realtimeChannel->publishedMessages[0]->payloadJson;
        static::assertStringEndsWith(',"callerContext":{"ringingSpaceId":12,"ringingSpaceName":"Front desk \"north\""}}', $payloadJson);
        static::assertSame(
            ['ringingSpaceId' => CallerContextFillingAgentVoiceRingService::AGENT_SPACE_ID, 'ringingSpaceName' => CallerContextFillingAgentVoiceRingService::DISPLAY_NAME],
            json_decode($payloadJson, true)['callerContext']
        );
        static::assertSame(
            ['callId', 'conversationId', 'answerToken', 'ringExpiresAt', 'ringExpiresAtUnixMs', 'callerLabelKey', 'callerContext'],
            array_keys(json_decode($payloadJson, true)),
            'the context adds no field beside callerContext'
        );
    }

    protected function bindTheApplicationsCallerContext(): void
    {
        StaticRegistry::$frameworkToAppClassNames[AgentVoiceRingCallerContext::class] = ApplicationAgentVoiceRingCallerContext::class;
    }
}
