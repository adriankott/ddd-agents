<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Entities\Voice\CallStart;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallRoute;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallStart;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** The medium of the agent's call start, decided from what the owner is entitled to and what can ring now. */
class AgentVoiceCallRouteTest extends AgentVoiceUnitTestCase
{
    #[DataProvider('provideRequests')]
    public function testTheRouteOfARequest(bool $appIsIncluded, bool $appIsRingable, ?string $expectedChannel, ?string $expectedRefusalReason): void
    {
        $agentVoiceCallRoute = AgentVoiceCallRoute::resolveForRequest($appIsIncluded, $appIsRingable);

        static::assertInstanceOf(AgentVoiceCallRoute::class, $agentVoiceCallRoute);
        static::assertSame($expectedChannel, $agentVoiceCallRoute->channel);
        static::assertSame($expectedRefusalReason, $agentVoiceCallRoute->refusalReason);
    }

    /** @return array<string, array{bool, bool, ?string, ?string}> */
    public static function provideRequests(): array
    {
        return [
            'included and ringable: the window' => [true, true, 'APP', null],
            'included, nothing rings' => [true, false, null, 'APP_NOT_RINGABLE'],
            'not included, a client listens' => [false, true, null, 'APP_NOT_INCLUDED'],
            'not included, nothing rings' => [false, false, null, 'APP_NOT_INCLUDED'],
        ];
    }

    public function testTheFactoriesSetOneSide(): void
    {
        $toChannel = AgentVoiceCallRoute::toChannel(AgentVoiceCallStart::CHANNEL_APP);
        $refused = AgentVoiceCallRoute::refused(AgentVoiceCallStart::REFUSED_APP_NOT_RINGABLE);

        static::assertSame(['APP', null], [$toChannel->channel, $toChannel->refusalReason]);
        static::assertSame([null, 'APP_NOT_RINGABLE'], [$refused->channel, $refused->refusalReason]);
    }
}
