<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Goals;

use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Infrastructure\Libs\Config;
use ReflectionProperty;

/**
 * Switches goals on or off for one test (`AI.agentGoals.enabled` where Config reads it first) and puts the process's
 * config and resolved skill binding back afterwards: {@see switchGoals()} in the test, {@see restore()} in tearDown.
 */
class AgentGoalsSwitch
{
    /** @var array<string, mixed> The config tree before the first switch */
    public array $configValuesBefore = [];

    /** @var array<string, string[]>|null The resolved skill binding before the first switch */
    public ?array $packageClassesBySkillNameBefore = null;

    public bool $hasSwitched = false;

    /**
     * Sets `AI.agentGoals.enabled` and forgets the resolved skill binding (it is resolved again from the switch).
     *
     * @param bool $isEnabled
     * @return void
     */
    public function switchGoals(bool $isEnabled): void
    {
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $skillBinding = new ReflectionProperty(AISkill::class, 'packageClassesBySkillName');
        if (!$this->hasSwitched) {
            $this->configValuesBefore = $configTree->isInitialized() ? (array)$configTree->getValue() : [];
            $this->packageClassesBySkillNameBefore = $skillBinding->getValue();
            $this->hasSwitched = true;
        }
        $configValues = $configTree->isInitialized() ? (array)$configTree->getValue() : [];
        $configValues['AI']['agentGoals'] = ['enabled' => $isEnabled];
        $configTree->setValue(null, $configValues);
        $skillBinding->setValue(null, null);
    }

    /**
     * Puts the config tree and the resolved skill binding back as they were before the first switch.
     *
     * @return void
     */
    public function restore(): void
    {
        if (!$this->hasSwitched) {
            return;
        }
        (new ReflectionProperty(Config::class, 'configTree'))->setValue(null, $this->configValuesBefore);
        (new ReflectionProperty(AISkill::class, 'packageClassesBySkillName'))->setValue(null, $this->packageClassesBySkillNameBefore);
        $this->hasSwitched = false;
    }
}
