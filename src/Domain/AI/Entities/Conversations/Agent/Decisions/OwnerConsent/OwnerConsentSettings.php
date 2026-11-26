<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Libs\Config;

/**
 * The settings `AI.ownerConsent` (`config/app/AI/ownerConsent.php`): the thresholds the owner-consent check applies.
 * A step is consented only when the decision model's probability that the agent EXPLAINED the step reaches
 * {@see $minimumStepExplainedProbability} AND its probability that the owner AGREED reaches
 * {@see $minimumOwnerAgreedProbability}. Both default to {@see OwnerConsentRequest::MINIMUM_PROBABILITY} (0.90).
 */
class OwnerConsentSettings extends ValueObject
{
    public const string CONFIG_KEY = 'AI.ownerConsent';

    /** @var float The probability that the agent explained the step (target and consequence) must reach this */
    public float $minimumStepExplainedProbability = OwnerConsentRequest::MINIMUM_PROBABILITY;

    /** @var float The probability that the owner agreed to that step must reach this */
    public float $minimumOwnerAgreedProbability = OwnerConsentRequest::MINIMUM_PROBABILITY;

    /** @var OwnerConsentSettings|null A test seam: the settings {@see current()} answers with in this process */
    protected static ?OwnerConsentSettings $forCurrentProcess = null;

    /** The settings of the application (`Config::get('AI.ownerConsent')`), or those a test set for the process. */
    public static function current(): OwnerConsentSettings
    {
        return static::$forCurrentProcess ?? static::fromConfigNode(Config::get(static::CONFIG_KEY));
    }

    /** Makes {@see current()} answer with these settings for the rest of the process (null = the config again). Tests only. */
    public static function useForCurrentProcess(?OwnerConsentSettings $ownerConsentSettings): void
    {
        static::$forCurrentProcess = $ownerConsentSettings;
    }

    /**
     * The config array as settings. A missing or invalid value (not a number between 0 and 1) keeps the default.
     */
    public static function fromConfigNode(mixed $configNode): OwnerConsentSettings
    {
        $ownerConsentSettings = new static();
        if (!is_array($configNode)) {
            return $ownerConsentSettings;
        }
        $minimumProbabilityNode = is_array($configNode['minimumProbability'] ?? null) ? $configNode['minimumProbability'] : [];
        $ownerConsentSettings->minimumStepExplainedProbability = static::readProbability(
            $minimumProbabilityNode['stepExplained'] ?? null,
            $ownerConsentSettings->minimumStepExplainedProbability
        );
        $ownerConsentSettings->minimumOwnerAgreedProbability = static::readProbability(
            $minimumProbabilityNode['ownerAgreed'] ?? null,
            $ownerConsentSettings->minimumOwnerAgreedProbability
        );
        return $ownerConsentSettings;
    }

    /** A configured probability (int or float in [0, 1]), else the default. */
    protected static function readProbability(mixed $configuredValue, float $defaultProbability): float
    {
        if ((!is_int($configuredValue) && !is_float($configuredValue)) || $configuredValue < 0 || $configuredValue > 1) {
            return $defaultProbability;
        }
        return (float)$configuredValue;
    }
}
