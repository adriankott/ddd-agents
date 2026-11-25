<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\Prompts;

use DDD\Domain\AI\Entities\Prompts\AIPrompt;
use DDD\Domain\AI\Entities\Voice\AgentVoiceConfiguration;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Services\AIPromptsService;
use Throwable;

/**
 * The text of one prompt of the voice channel with its placeholders filled: the one place the voice classes read their
 * sentences from ({@see AgentVoiceAIPromptNames}).
 *
 * `{%AGENT_NAME%}` is filled in every text with the agent name of the application's persona
 * ({@see AgentVoiceConfiguration::$agentName}), else with {@see AgentVoiceCall::DEFAULT_AGENT_LABEL}. All placeholders
 * are replaced in one pass: a value that itself contains a placeholder (an owner's task text) is never filled again.
 */
class AgentVoicePromptText
{
    /** @var string The placeholder every voice prompt may use for the agent's name */
    public const string PARAMETER_AGENT_NAME = 'AGENT_NAME';

    /**
     * The prompt's text with `$parameters` filled, without surrounding white space. Empty when the prompt does not
     * exist: a missing file costs its own block, never the call.
     *
     * @param array<string, string|int|float> $parameters Placeholder name (without `{%` and `%}`) => value
     */
    public static function forName(string $promptName, array $parameters = []): string
    {
        try {
            $promptText = static::getAIPromptsService()->getAIPromptByName($promptName)?->promptText ?? '';
        } catch (Throwable) {
            return '';
        }
        $replacements = ['{%' . static::PARAMETER_AGENT_NAME . '%}' => static::getAgentName()];
        foreach ($parameters as $parameterName => $parameterValue) {
            $replacements['{%' . $parameterName . '%}'] = (string)$parameterValue;
        }

        return trim(strtr($promptText, $replacements));
    }

    /** What the agent is called in a voice prompt: the persona's agent name, else the neutral label of a call. */
    protected static function getAgentName(): string
    {
        $agentName = AgentVoiceConfiguration::current()->agentName;

        return $agentName !== '' ? $agentName : AgentVoiceCall::DEFAULT_AGENT_LABEL;
    }

    protected static function getAIPromptsService(): AIPromptsService
    {
        /** @var AIPromptsService $aIPromptsService */
        $aIPromptsService = AIPrompt::getService();

        return $aIPromptsService;
    }
}
