<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Live\Provider;

use DDD\Domain\AI\Exceptions\Voice\LiveSessionGoneException;
use DDD\Domain\AI\Services\Voice\Live\LiveSessionCreationResponse;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What a session creation answers: the session id and the SDP answer, read from the nested or the flat body, usable
 * only with both; and the exception of a session that is gone at the provider.
 */
class LiveSessionCreationResponseTest extends AgentVoiceUnitTestCase
{
    /** @return array<string, array{array<string, mixed>, string, string, bool}> */
    public static function provideCreationBodies(): array
    {
        return [
            'nested, as the reference shows it' => [['session' => ['id' => 'live_1'], 'transport' => ['sdp' => 'v=0 a']], 'live_1', 'v=0 a', true],
            'flat, as the examples show it' => [['id' => 'live_2', 'sdp' => 'v=0 b'], 'live_2', 'v=0 b', true],
            'the nested form wins over the flat one' => [['session' => ['id' => 'live_n'], 'id' => 'live_f', 'transport' => ['sdp' => 'n'], 'sdp' => 'f'], 'live_n', 'n', true],
            'a session that is no object falls back to the flat id' => [['session' => 'live_x', 'id' => 'live_3', 'sdp' => 'v=0'], 'live_3', 'v=0', true],
            'the id is trimmed' => [['id' => '  live_4 ', 'sdp' => 'v=0'], 'live_4', 'v=0', true],
            'the SDP is kept as sent' => [['id' => 'live_5', 'sdp' => " v=0\r\n"], 'live_5', " v=0\r\n", true],
            'only an id' => [['id' => 'live_6'], 'live_6', '', false],
            'only an SDP' => [['sdp' => 'v=0'], '', 'v=0', false],
            'a blank SDP' => [['id' => 'live_7', 'sdp' => '   '], 'live_7', '   ', false],
            'nothing' => [[], '', '', false],
        ];
    }

    /** @param array<string, mixed> $creationResponseBody */
    #[DataProvider('provideCreationBodies')]
    public function testBothHalvesAreReadAndTheAnswerIsUsableOnlyWithBoth(array $creationResponseBody, string $expectedSessionId, string $expectedSdpAnswer, bool $expectedUsable): void
    {
        $liveSessionCreationResponse = LiveSessionCreationResponse::fromCreationResponseBody($creationResponseBody);

        static::assertSame($expectedSessionId, $liveSessionCreationResponse->providerSessionId);
        static::assertSame($expectedSdpAnswer, $liveSessionCreationResponse->sdpAnswer);
        static::assertSame($expectedUsable, $liveSessionCreationResponse->isUsable());
    }

    public function testAGoneSessionIsAnInternalErrorThatKeepsItsMessage(): void
    {
        $liveSessionGoneException = new LiveSessionGoneException('session live_1 is gone');

        static::assertInstanceOf(InternalErrorException::class, $liveSessionGoneException);
        static::assertSame('session live_1 is gone', $liveSessionGoneException->getMessage());
    }
}
