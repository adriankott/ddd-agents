<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Libs\Config;

/**
 * The settings `AI.decisionContext` (`config/app/AI/decisionContext.php`): how many characters of the conversation the
 * decision models read, and the second pass of the owner-consent decision. Every cut is marked
 * ({@see AgentDecisionTextCuts}).
 */
class AgentDecisionContextSettings extends ValueObject
{
    public const string CONFIG_KEY = 'AI.decisionContext';

    /** @var int Owner-consent: an owner or widget line keeps this; an assistant line this plus the step and consequence */
    public int $ownerConsentLineCharacters = 700;

    /** @var int Owner-consent: the most an assistant line keeps in the first pass */
    public int $ownerConsentAssistantLineMaxCharacters = 4000;

    /** @var int Owner-consent: every line keeps this in the second pass */
    public int $ownerConsentExpandedLineCharacters = 12000;

    /** @var bool Owner-consent: whether a first pass short of context may be followed by ONE second pass */
    public bool $ownerConsentSecondPass = true;

    /** @var float Owner-consent: a context-sufficient probability below this asks for the second pass */
    public float $ownerConsentContextSufficientMinimum = 0.5;

    /** @var float Owner-consent: a clear yes with a step-explained probability from this up to its threshold asks for the second pass */
    public float $ownerConsentNearMissFloor = 0.5;

    /** @var int Turn start: the owner message keeps its first characters up to this */
    public int $turnStartOwnerMessageCharacters = 600;

    /** @var int Turn start: the assistant text before the owner message keeps its last characters up to this */
    public int $turnStartAssistantTextCharacters = 300;

    /** @var int Stall recovery: the owner message keeps its first characters up to this */
    public int $stallRecoveryOwnerMessageCharacters = 600;

    /** @var int Stall recovery: the stalled step's text keeps its last characters up to this */
    public int $stallRecoveryAssistantTextCharacters = 300;

    /** @var AgentDecisionContextSettings|null A test seam: the settings {@see current()} answers with in this process */
    protected static ?AgentDecisionContextSettings $forCurrentProcess = null;

    /** The settings of the application (`Config::get('AI.decisionContext')`), or those a test set for the process. */
    public static function current(): AgentDecisionContextSettings
    {
        return static::$forCurrentProcess ?? static::fromConfigNode(Config::get(static::CONFIG_KEY));
    }

    /** Makes {@see current()} answer with these settings for the rest of the process (null = the config again). Tests only. */
    public static function useForCurrentProcess(?AgentDecisionContextSettings $agentDecisionContextSettings): void
    {
        static::$forCurrentProcess = $agentDecisionContextSettings;
    }

    /** The config array as settings. A missing or invalid value keeps the default. */
    public static function fromConfigNode(mixed $configNode): AgentDecisionContextSettings
    {
        $agentDecisionContextSettings = new static();
        if (!is_array($configNode)) {
            return $agentDecisionContextSettings;
        }
        $ownerConsentNode = is_array($configNode['ownerConsent'] ?? null) ? $configNode['ownerConsent'] : [];
        $agentDecisionContextSettings->ownerConsentLineCharacters = static::readCharacters($ownerConsentNode['lineCharacters'] ?? null, $agentDecisionContextSettings->ownerConsentLineCharacters);
        $agentDecisionContextSettings->ownerConsentAssistantLineMaxCharacters = static::readCharacters($ownerConsentNode['assistantLineMaxCharacters'] ?? null, $agentDecisionContextSettings->ownerConsentAssistantLineMaxCharacters);
        $agentDecisionContextSettings->ownerConsentExpandedLineCharacters = static::readCharacters($ownerConsentNode['expandedLineCharacters'] ?? null, $agentDecisionContextSettings->ownerConsentExpandedLineCharacters);
        if (is_bool($ownerConsentNode['secondPass'] ?? null)) {
            $agentDecisionContextSettings->ownerConsentSecondPass = $ownerConsentNode['secondPass'];
        }
        $agentDecisionContextSettings->ownerConsentContextSufficientMinimum = static::readProbability($ownerConsentNode['contextSufficientMinimum'] ?? null, $agentDecisionContextSettings->ownerConsentContextSufficientMinimum);
        $agentDecisionContextSettings->ownerConsentNearMissFloor = static::readProbability($ownerConsentNode['nearMissFloor'] ?? null, $agentDecisionContextSettings->ownerConsentNearMissFloor);
        $turnStartNode = is_array($configNode['turnStart'] ?? null) ? $configNode['turnStart'] : [];
        $agentDecisionContextSettings->turnStartOwnerMessageCharacters = static::readCharacters($turnStartNode['ownerMessageCharacters'] ?? null, $agentDecisionContextSettings->turnStartOwnerMessageCharacters);
        $agentDecisionContextSettings->turnStartAssistantTextCharacters = static::readCharacters($turnStartNode['assistantTextCharacters'] ?? null, $agentDecisionContextSettings->turnStartAssistantTextCharacters);
        $stallRecoveryNode = is_array($configNode['stallRecovery'] ?? null) ? $configNode['stallRecovery'] : [];
        $agentDecisionContextSettings->stallRecoveryOwnerMessageCharacters = static::readCharacters($stallRecoveryNode['ownerMessageCharacters'] ?? null, $agentDecisionContextSettings->stallRecoveryOwnerMessageCharacters);
        $agentDecisionContextSettings->stallRecoveryAssistantTextCharacters = static::readCharacters($stallRecoveryNode['assistantTextCharacters'] ?? null, $agentDecisionContextSettings->stallRecoveryAssistantTextCharacters);
        return $agentDecisionContextSettings;
    }

    /** A configured character count (a positive int), else the default. */
    protected static function readCharacters(mixed $configuredValue, int $defaultCharacters): int
    {
        return is_int($configuredValue) && $configuredValue > 0 ? $configuredValue : $defaultCharacters;
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
