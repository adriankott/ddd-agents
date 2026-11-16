<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One trajectory of an eviction fixture: a name and the owner prompts that run one after the other on one continuing
 * conversation. The trajectory's other keys (e.g. the agent space it runs in, or an application's own key of its
 * scope) reach the scope resolution as the fields of a case ({@see buildScopeCase()}).
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalEvictionTrajectory extends ValueObject
{
    /** @var string The name a trajectory without one gets */
    public const string DEFAULT_NAME = 'trajectory';

    /** @var string The key of the trajectory's name */
    public const string KEY_NAME = 'name';

    /** @var string The key of the trajectory's owner prompts */
    public const string KEY_PROMPTS = 'prompts';

    /** @var string The name of the trajectory */
    public string $name = self::DEFAULT_NAME;

    /** @var string[] The owner prompts in the order they run; entries that are no string are left out */
    public array $prompts = [];

    /** @var array<string, mixed> The trajectory as decoded from the fixture file */
    protected array $trajectoryFields = [];

    /**
     * The trajectory of a decoded fixture entry.
     *
     * @param array<string, mixed> $trajectoryFields
     * @return static
     */
    public static function fromDecodedFields(array $trajectoryFields): static
    {
        $agentEvalEvictionTrajectory = static::newInstance();
        $agentEvalEvictionTrajectory->trajectoryFields = $trajectoryFields;
        $agentEvalEvictionTrajectory->name = (string)($trajectoryFields[self::KEY_NAME] ?? self::DEFAULT_NAME);
        $decodedPrompts = is_array($trajectoryFields[self::KEY_PROMPTS] ?? null) ? $trajectoryFields[self::KEY_PROMPTS] : [];
        $agentEvalEvictionTrajectory->prompts = array_values(array_filter($decodedPrompts, 'is_string'));
        return $agentEvalEvictionTrajectory;
    }

    /** Whether the trajectory has a prompt to run. */
    public function hasPrompts(): bool
    {
        return $this->prompts !== [];
    }

    /**
     * The trajectory as a case for the scope resolution of the run hooks: every key of the trajectory, its name as
     * the case id.
     *
     * @return AgentEvalCase
     */
    public function buildScopeCase(): AgentEvalCase
    {
        return AgentEvalCase::fromArray($this->trajectoryFields + ['id' => $this->name]);
    }
}
