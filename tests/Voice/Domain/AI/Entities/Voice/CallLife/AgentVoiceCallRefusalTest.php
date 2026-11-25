<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Entities\Voice\CallLife;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallRefusal;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** The refusal of the admission port ({@see AgentVoiceCallRefusal}): its three codes, its end reason, the limit check. */
class AgentVoiceCallRefusalTest extends AgentVoiceUnitTestCase
{
    #[DataProvider('provideRefusals')]
    public function testEachFactoryCarriesItsCodeAndTheLimitEndReason(string $factoryName, string $expectedCode, bool $expectedLimitRefusal): void
    {
        /** @var AgentVoiceCallRefusal $agentVoiceCallRefusal */
        $agentVoiceCallRefusal = AgentVoiceCallRefusal::$factoryName();

        static::assertSame($expectedCode, $agentVoiceCallRefusal->code);
        static::assertSame(AgentVoiceCall::END_REASON_LIMIT_REACHED, $agentVoiceCallRefusal->endReason);
        static::assertSame($expectedLimitRefusal, $agentVoiceCallRefusal->isLimitRefusal());
    }

    /** @return array<string, array{string, string, bool}> */
    public static function provideRefusals(): array
    {
        return [
            'off or not allowed' => ['forFeatureNotAvailable', 'feature_not_available', false],
            'no budget' => ['forNoAiBudget', 'no_ai_budget', true],
            'daily limit' => ['forDailyLimitReached', 'daily_limit_reached', true],
            'longest call' => ['forMaximumCallSecondsReached', 'maximum_call_seconds_reached', false],
        ];
    }

    public function testAnApplicationsCodeKeepsItsEndReason(): void
    {
        $agentVoiceCallRefusal = AgentVoiceCallRefusal::forCode('account_locked', AgentVoiceCall::END_REASON_FAILED);

        static::assertSame('account_locked', $agentVoiceCallRefusal->code);
        static::assertSame(AgentVoiceCall::END_REASON_FAILED, $agentVoiceCallRefusal->endReason);
        static::assertFalse($agentVoiceCallRefusal->isLimitRefusal());
        static::assertSame(AgentVoiceCall::END_REASON_LIMIT_REACHED, AgentVoiceCallRefusal::forCode('account_locked')->endReason);
    }
}
