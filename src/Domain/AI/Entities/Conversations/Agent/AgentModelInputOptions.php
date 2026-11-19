<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Libs\Config;

/**
 * What a host (and a purpose) leaves out of the model input to save tokens on every call: groups of meta tools, the
 * widget part of the base prompt, named sections of the base prompt, and the reachable tool schemas the append-only
 * epoch would send from the first call. Every switch defaults to the package's behaviour, so the model input of a host
 * that sets nothing is byte-identical to the one before the switches existed.
 *
 * Resolution, per conversation: the host's `config/app/AI/agentModelInputOptions.php` (`AI.agentModelInputOptions`,
 * replaced whole, a missing key keeps the default), then the `modelInputOptions:` map of the conversation's purpose
 * (each key it names wins). Read at every model call, never stored: the values of one conversation stay the same as
 * long as the configuration does, so the cached prefix holds.
 *
 * None of these switches rewrites a sentence: a base prompt section is dropped whole, from its `## ` heading to the next
 * `## ` heading ({@see AgentBasePromptSections}). A prompt text that still names a dropped tool is the host's to drop
 * with it (a named section) or to replace (its own prompt file).
 *
 * @phpstan-consistent-constructor
 */
class AgentModelInputOptions extends ValueObject
{
    /** @var string The configuration key of the host's options */
    public const string CONFIG_KEY = 'AI.agentModelInputOptions';

    /** @var string The heading of the base prompt's widget section ({@see $teachesWidgets}) */
    public const string BASE_PROMPT_SECTION_WIDGETS = 'Widgets';

    /** @var string The heading of the base prompt's section on owner statements, automatic memory and recall */
    public const string BASE_PROMPT_SECTION_MEMORY = 'Owner statements: setting, automatic memory, or recall';

    /** @var string[] The keys a configuration or a purpose map may name with a boolean */
    public const array BOOLEAN_KEYS = [
        'offersDelegationTools',
        'offersTaskPlanTools',
        'offersAskOwner',
        'offersFindWidgets',
        'teachesWidgets',
        'dropsMemorySectionWithoutMemoryTool',
        'offersReachableToolsFromTheFirstCall',
    ];

    /**
     * @var bool The delegation tools (`delegate`, `check_delegations`, `message_sub_agent`, `wait_for_sub_agents`) are
     * offered where the loop offers them. False: none of them.
     */
    public bool $offersDelegationTools = true;

    /** @var bool The task-plan tools (`update_task_plan`, `set_task_status`, `get_task_plans`) are offered where the loop offers them. False: none of them. */
    public bool $offersTaskPlanTools = true;

    /** @var bool `askOwner` (the closed question to the owner) is offered. */
    public bool $offersAskOwner = true;

    /** @var bool `findWidgets` is offered where the loop offers it. */
    public bool $offersFindWidgets = true;

    /**
     * @var bool The base prompt teaches widgets: its `Widgets` section with the generated composed-view kit. False (a
     * consumer that renders no widgets): the section and the kit are left out, and `findWidgets` is not offered.
     */
    public bool $teachesWidgets = true;

    /**
     * @var string[] Headings of base prompt sections left out (the text after `## `, with or without its
     * `[surface=…]` marker), e.g. `Status line + parallel calls`. A heading the prompt does not have drops nothing.
     */
    public array $droppedBasePromptSections = [];

    /**
     * @var bool The memory section ({@see BASE_PROMPT_SECTION_MEMORY}) is left out while no tool the conversation can
     * reach declares `MCPToolWritesAgentMemoryOnly`. Off by default: the default base prompt stays as it is.
     */
    public bool $dropsMemorySectionWithoutMemoryTool = false;

    /**
     * @var bool Under append-only loading, the whole reachable tool set rides top-level from the first call when it
     * fits `appendOnlyReachableToolSetCeilingTokens`. False: never — the tools of a skill arrive when the skill is
     * loaded (an `additional_tools` item per load), as above the ceiling.
     */
    public bool $offersReachableToolsFromTheFirstCall = true;

    /**
     * The options of a conversation: the host's configuration, then what its purpose's `modelInputOptions:` map names.
     *
     * @param AgentModelInputOptionsOverride|null $purposeOverride the purpose's override; null = none
     */
    public static function resolveFor(?AgentModelInputOptionsOverride $purposeOverride = null): static
    {
        $agentModelInputOptions = new static();
        $configuredOptions = Config::get(static::CONFIG_KEY);
        if (is_array($configuredOptions)) {
            $agentModelInputOptions->applyOptionsMap($configuredOptions);
        }
        $purposeOverride?->applyTo($agentModelInputOptions);
        return $agentModelInputOptions;
    }

    /**
     * Sets every option `$optionsMap` names; a key it does not name keeps its value, an unknown key is ignored.
     *
     * @param array<string, mixed> $optionsMap
     */
    public function applyOptionsMap(array $optionsMap): static
    {
        foreach (static::BOOLEAN_KEYS as $booleanKey) {
            if (array_key_exists($booleanKey, $optionsMap) && $optionsMap[$booleanKey] !== null) {
                $this->{$booleanKey} = (bool)$optionsMap[$booleanKey];
            }
        }
        if (array_key_exists('droppedBasePromptSections', $optionsMap) && is_array($optionsMap['droppedBasePromptSections'])) {
            $this->droppedBasePromptSections = array_values(array_unique(array_map('strval', $optionsMap['droppedBasePromptSections'])));
        }
        return $this;
    }

    /** Whether `findWidgets` may be offered: it is switched on and the base prompt teaches widgets. */
    public function offersFindWidgetsWithWidgetsTaught(): bool
    {
        return $this->offersFindWidgets && $this->teachesWidgets;
    }

    /**
     * The headings of the base prompt sections this call leaves out: the named ones, the widget section without
     * widgets, the memory section when asked and no memory tool is reachable.
     *
     * @return string[]
     */
    public function buildDroppedBasePromptSections(bool $memoryToolIsReachable): array
    {
        $droppedBasePromptSections = $this->droppedBasePromptSections;
        if (!$this->teachesWidgets) {
            $droppedBasePromptSections[] = static::BASE_PROMPT_SECTION_WIDGETS;
        }
        if ($this->dropsMemorySectionWithoutMemoryTool && !$memoryToolIsReachable) {
            $droppedBasePromptSections[] = static::BASE_PROMPT_SECTION_MEMORY;
        }
        return array_values(array_unique($droppedBasePromptSections));
    }
}
