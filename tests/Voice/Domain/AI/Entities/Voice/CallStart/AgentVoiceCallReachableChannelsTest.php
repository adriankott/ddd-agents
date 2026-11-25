<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Entities\Voice\CallStart;

use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariant;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariants;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallReachableChannels;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What reaches the owner right now: the value of the `voiceAppRing` dimension, its closed value set, when a skill asks
 * for it, and the one offer after an unanswered call.
 */
class AgentVoiceCallReachableChannelsTest extends AgentVoiceUnitTestCase
{
    #[DataProvider('provideReadings')]
    public function testTheValueOfTheDimension(bool $appIsIncluded, bool $appIsRingableNow, string $expectedValue): void
    {
        $agentVoiceCallReachableChannels = static::buildReading($appIsIncluded, $appIsRingableNow);
        $aIConversationVariants = new AIConversationVariants();

        $agentVoiceCallReachableChannels->putIntoVariants($aIConversationVariants);

        static::assertSame($expectedValue, $agentVoiceCallReachableChannels->getAppRingValue());
        static::assertSame($expectedValue, $aIConversationVariants->getValue('voiceAppRing'));
        static::assertTrue($aIConversationVariants->isDecided('voiceAppRing'));
        static::assertSame(AIConversationVariant::SOURCE_RECORD, $aIConversationVariants->get('voiceAppRing')->source);
    }

    /** @return array<string, array{bool, bool, string}> */
    public static function provideReadings(): array
    {
        return [
            'included, a client listens' => [true, true, 'ringsNow'],
            'included, nobody listens' => [true, false, 'notRingableNow'],
            'not included, a client listens' => [false, true, 'notIncluded'],
            'not included, nobody listens' => [false, false, 'notIncluded'],
        ];
    }

    public function testTheDimensionHasItsClosedValueSet(): void
    {
        $variantDimensions = AgentVoiceCallReachableChannels::buildVariantDimensions()->getElements();

        static::assertCount(1, $variantDimensions);
        static::assertSame('voiceAppRing', $variantDimensions[0]->name);
        static::assertSame(['ringsNow', 'notRingableNow', 'notIncluded', 'unknown'], $variantDimensions[0]->values, 'the package adds its unknown value');
        static::assertSame('unknown', $variantDimensions[0]->default);
    }

    public function testOnlyASkillKeyingOnTheDimensionAsksForTheReading(): void
    {
        static::assertTrue(AgentVoiceCallReachableChannels::isAskedForByDimensionNames(['ownerMood', 'voiceAppRing']));
        static::assertFalse(AgentVoiceCallReachableChannels::isAskedForByDimensionNames(['ownerMood', 'VoiceAppRing']));
        static::assertFalse(AgentVoiceCallReachableChannels::isAskedForByDimensionNames([]));
    }

    public function testTheOfferAfterAnUnansweredCall(): void
    {
        $agentVoiceCall = new AgentVoiceCall();

        static::assertSame('ask whether you should try the app again a bit later.', static::buildReading(true, true)->buildUnansweredFollowUpOffer($agentVoiceCall));
        static::assertSame(
            'say they can call you in the app or write here whenever it suits them — no other call channel reaches them right now.',
            static::buildReading(true, false)->buildUnansweredFollowUpOffer($agentVoiceCall)
        );
        static::assertSame(
            'ask whether you should try the app again a bit later.',
            static::buildReading(false, true)->buildUnansweredFollowUpOffer($agentVoiceCall),
            'the offer reads only whether a ring works now (the package\'s reading never sets it without the channel)'
        );
    }

    protected static function buildReading(bool $appIsIncluded, bool $appIsRingableNow): AgentVoiceCallReachableChannels
    {
        $agentVoiceCallReachableChannels = AgentVoiceCallReachableChannels::newInstance();
        $agentVoiceCallReachableChannels->appIsIncluded = $appIsIncluded;
        $agentVoiceCallReachableChannels->appIsRingableNow = $appIsRingableNow;
        return $agentVoiceCallReachableChannels;
    }
}
