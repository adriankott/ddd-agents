<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Services\Skills\AISkillsService;

/**
 * The skills service of a test: every skill exists, with the sub-skills the test gives it.
 */
class AISkillsServiceWithSubSkillsDouble extends AISkillsService
{
    /** @var array<string, string[]> The sub-skills of each skill, by skill name */
    public array $subSkillNamesBySkillName = [];

    public function getAISkillByName(string $skillName): ?AISkill
    {
        $aISkill = new AISkill();
        $aISkill->name = $skillName;
        $aISkill->subSkillNames = $this->subSkillNamesBySkillName[$skillName] ?? [];
        return $aISkill;
    }
}
