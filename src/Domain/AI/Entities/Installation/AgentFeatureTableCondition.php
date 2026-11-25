<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Installation;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * When the table of an entity is required: only while its feature is in use. `app:agent:check-installation` reports a
 * missing table as BLOCKING only while the condition holds; a table of a switched-off feature, or of a skill the
 * application does not bind, may be missing. A package declares its conditions through
 * {@see \DDD\Domain\AI\Services\Installation\AgentFeatureTableConditionProvider}.
 */
class AgentFeatureTableCondition extends ValueObject
{
    /** @var string The entity class (with namespace) whose table it is; an application subclass of it is meant too */
    public string $entityClassName = '';

    /** @var string What reads the table, for the reason (`the browser sessions (AI.Browser.sessions.enabled)`) */
    public string $featureDescription = '';

    /** @var bool|null Whether the feature is switched on; null = no switch, only {@see $requiredWhileSkillBound} decides */
    public ?bool $isFeatureSwitchedOn = null;

    /** @var string|null A skill name: the table is required only while config/app/AI/skillPackages.php binds that skill */
    public ?string $requiredWhileSkillBound = null;

    public static function create(string $entityClassName, string $featureDescription, ?bool $isFeatureSwitchedOn, ?string $requiredWhileSkillBound = null): static
    {
        $agentFeatureTableCondition = new static();
        $agentFeatureTableCondition->entityClassName = ltrim($entityClassName, '\\');
        $agentFeatureTableCondition->featureDescription = $featureDescription;
        $agentFeatureTableCondition->isFeatureSwitchedOn = $isFeatureSwitchedOn;
        $agentFeatureTableCondition->requiredWhileSkillBound = $requiredWhileSkillBound;
        return $agentFeatureTableCondition;
    }

    /**
     * Why the table is not required; null while it is.
     *
     * @param string[] $boundSkillNames the skills the application binds
     */
    public function findReasonTableIsNotRequired(array $boundSkillNames): ?string
    {
        if ($this->isFeatureSwitchedOn === false) {
            return $this->featureDescription . ' is switched off';
        }
        if ($this->requiredWhileSkillBound !== null && !in_array($this->requiredWhileSkillBound, $boundSkillNames, true)) {
            return "the skill '{$this->requiredWhileSkillBound}' is not bound";
        }
        return null;
    }

    /** Whether the condition is about the entity class (or a parent class of it) */
    public function isAboutEntityClass(string $entityClassName): bool
    {
        return $this->entityClassName !== '' && is_a($entityClassName, $this->entityClassName, true);
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->entityClassName . '|' . (string)spl_object_id($this));
    }
}
