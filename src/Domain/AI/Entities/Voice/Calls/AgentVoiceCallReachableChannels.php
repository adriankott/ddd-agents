<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\Calls;

use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariant;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariantDimension;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariantDimensions;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariants;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * Which call medium of the agent would reach the owner RIGHT NOW: the reads `startVoiceCall` routes on, made without
 * starting anything ({@see \DDD\Domain\AI\Services\Voice\AgentVoiceCallStartsService::findReachableChannelsForOwner()}):
 * whether the voice channel is available to the owner and whether a client of the owner can be rung.
 *
 * Read twice: by the follow-up of an unanswered call ({@see AgentVoiceCall::buildUnansweredFollowUpSignalText()}),
 * which offers only what can reach the owner ({@see buildUnansweredFollowUpOffer()}), and as the transient situation
 * dimension {@see self::APP_RING_DIMENSION} ({@see putIntoVariants()}): a skill body keys sections on it
 * (`### [voiceAppRing=notRingableNow]`), so the model sees per model call whether a ring works now. It is a state,
 * never an offer gate. The conversation merges it into the rendered variants only while a loaded skill keys on it.
 *
 * The base knows one medium, the ring in the application's own window. An application with a further medium adds its
 * fields, its dimension and its follow-up offers in a subclass at the same path, by overriding
 * {@see putIntoVariants()}, {@see buildVariantDimensions()}, {@see isAskedForByDimensionNames()} and
 * {@see buildUnansweredFollowUpOffer()}.
 */
class AgentVoiceCallReachableChannels extends ValueObject
{
    /** @var string The situation dimension of the ring in the application's window */
    public const string APP_RING_DIMENSION = 'voiceAppRing';

    /** @var string A ring reaches the owner right now: the voice channel is available and a client of the owner listens */
    public const string APP_RING_RINGS_NOW = 'ringsNow';

    /** @var string The voice channel is available, but nothing can ring now: no client of the owner listens */
    public const string APP_RING_NOT_RINGABLE_NOW = 'notRingableNow';

    /** @var string The voice channel is not available to this owner */
    public const string APP_RING_NOT_INCLUDED = 'notIncluded';

    /** @var bool The voice channel is available to this owner */
    public bool $appIsIncluded = false;

    /** @var bool A ring in the application's window would reach the owner now */
    public bool $appIsRingableNow = false;

    /** The value of {@see self::APP_RING_DIMENSION} for this reading. */
    public function getAppRingValue(): string
    {
        if (!$this->appIsIncluded) {
            return static::APP_RING_NOT_INCLUDED;
        }
        return $this->appIsRingableNow ? static::APP_RING_RINGS_NOW : static::APP_RING_NOT_RINGABLE_NOW;
    }

    /** Puts the dimension, decided from this reading, into `$aIConversationVariants`. */
    public function putIntoVariants(AIConversationVariants $aIConversationVariants): void
    {
        $aIConversationVariants->put(AIConversationVariant::decided(static::APP_RING_DIMENSION, $this->getAppRingValue(), AIConversationVariant::SOURCE_RECORD));
    }

    /** The dimension with its closed value set: what a skill section may key on. */
    public static function buildVariantDimensions(): AIConversationVariantDimensions
    {
        $appRingDimension = AIConversationVariantDimension::of(
            static::APP_RING_DIMENSION,
            [static::APP_RING_RINGS_NOW, static::APP_RING_NOT_RINGABLE_NOW, static::APP_RING_NOT_INCLUDED]
        );
        $aIConversationVariantDimensions = new AIConversationVariantDimensions();
        $aIConversationVariantDimensions->add($appRingDimension);
        return $aIConversationVariantDimensions;
    }

    /**
     * Whether a skill body that keys sections on `$dimensionNames` asks for this reading; the conversation probes what
     * reaches the owner only then.
     *
     * @param string[] $dimensionNames
     */
    public static function isAskedForByDimensionNames(array $dimensionNames): bool
    {
        return in_array(static::APP_RING_DIMENSION, $dimensionNames, true);
    }

    /**
     * The ONE thing the agent offers the owner after `$agentVoiceCall` went unanswered, as the end of the sentence
     * "say that they did not pick up, then …": the same ring again later when a ring reaches the owner now, otherwise
     * only that the owner can get back to the agent. A subclass with a further medium offers that medium first and
     * reads the medium of the unanswered call from `$agentVoiceCall`.
     */
    public function buildUnansweredFollowUpOffer(AgentVoiceCall $agentVoiceCall): string
    {
        if ($this->appIsRingableNow) {
            return 'ask whether you should try the app again a bit later.';
        }
        return 'say they can call you in the app or write here whenever it suits them — no other call channel reaches them right now.';
    }
}
