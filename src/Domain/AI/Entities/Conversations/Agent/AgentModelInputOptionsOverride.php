<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The model-input options a purpose names in its `modelInputOptions:` map: each field it sets wins over the host's
 * configuration ({@see AgentModelInputOptions::resolveFor()}), a field it leaves null keeps the host's value.
 *
 * @phpstan-consistent-constructor
 */
class AgentModelInputOptionsOverride extends ValueObject
{
    /** @var bool|null See {@see AgentModelInputOptions::$offersDelegationTools}; null = the host's */
    public ?bool $offersDelegationTools = null;

    /** @var bool|null See {@see AgentModelInputOptions::$offersTaskPlanTools}; null = the host's */
    public ?bool $offersTaskPlanTools = null;

    /** @var bool|null See {@see AgentModelInputOptions::$offersAskOwner}; null = the host's */
    public ?bool $offersAskOwner = null;

    /** @var bool|null See {@see AgentModelInputOptions::$offersFindWidgets}; null = the host's */
    public ?bool $offersFindWidgets = null;

    /** @var bool|null See {@see AgentModelInputOptions::$teachesWidgets}; null = the host's */
    public ?bool $teachesWidgets = null;

    /** @var string[]|null See {@see AgentModelInputOptions::$droppedBasePromptSections}; null = the host's */
    public ?array $droppedBasePromptSections = null;

    /** @var bool|null See {@see AgentModelInputOptions::$dropsMemorySectionWithoutMemoryTool}; null = the host's */
    public ?bool $dropsMemorySectionWithoutMemoryTool = null;

    /** @var bool|null See {@see AgentModelInputOptions::$offersReachableToolsFromTheFirstCall}; null = the host's */
    public ?bool $offersReachableToolsFromTheFirstCall = null;

    /**
     * The override a purpose's `modelInputOptions:` map names; an unknown key is ignored.
     *
     * @param array<string, mixed> $optionsMap
     */
    public static function fromOptionsMap(array $optionsMap): static
    {
        $agentModelInputOptionsOverride = new static();
        foreach (AgentModelInputOptions::BOOLEAN_KEYS as $booleanKey) {
            if (array_key_exists($booleanKey, $optionsMap) && $optionsMap[$booleanKey] !== null) {
                $agentModelInputOptionsOverride->{$booleanKey} = (bool)$optionsMap[$booleanKey];
            }
        }
        if (isset($optionsMap['droppedBasePromptSections']) && is_array($optionsMap['droppedBasePromptSections'])) {
            $agentModelInputOptionsOverride->droppedBasePromptSections = array_values(array_map('strval', $optionsMap['droppedBasePromptSections']));
        }
        return $agentModelInputOptionsOverride;
    }

    /** Sets on `$agentModelInputOptions` every field this override names. */
    public function applyTo(AgentModelInputOptions $agentModelInputOptions): void
    {
        foreach (AgentModelInputOptions::BOOLEAN_KEYS as $booleanKey) {
            if ($this->{$booleanKey} !== null) {
                $agentModelInputOptions->{$booleanKey} = $this->{$booleanKey};
            }
        }
        if ($this->droppedBasePromptSections !== null) {
            $agentModelInputOptions->droppedBasePromptSections = array_values(array_unique($this->droppedBasePromptSections));
        }
    }
}
