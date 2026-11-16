<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\DecisionFixtures;

use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Entities\Skills\AISkills;
use DDD\Domain\AI\Services\Skills\AISkillsService;

/**
 * A skills service that knows three skills and reads no skill file: a root with two sub-skills, the first of which a
 * case lists as loaded.
 */
class AISkillsServiceWithFixtureSkillsDouble extends AISkillsService
{
    public const string ROOT_SKILL_NAME = 'Fixture.Root';

    public const string LOADED_SUB_SKILL_NAME = 'Fixture.Loaded';

    public const string OFFERED_SUB_SKILL_NAME = 'Fixture.Offered';

    /** @var array<string, AISkill> The skills by name */
    public array $skillsByName = [];

    public function __construct()
    {
        $rootSkill = AISkillWithToolSummariesDouble::newInstance();
        $rootSkill->name = self::ROOT_SKILL_NAME;
        $rootSkill->description = "Works on   the root's\n topic.";
        $rootSkill->subSkillNames = [self::LOADED_SUB_SKILL_NAME, self::OFFERED_SUB_SKILL_NAME, 'Fixture.Unknown'];
        $rootSkill->toolSummariesByName = ['readRootState' => 'Reads the state of the root topic.'];
        $loadedSubSkill = AISkill::newInstance();
        $loadedSubSkill->name = self::LOADED_SUB_SKILL_NAME;
        $loadedSubSkill->description = 'Works on the loaded part. More text.';
        $loadedSubSkill->shortDescription = 'The loaded part';
        $offeredSubSkill = AISkill::newInstance();
        $offeredSubSkill->name = self::OFFERED_SUB_SKILL_NAME;
        $offeredSubSkill->description = 'Works on the offered part.';
        $this->skillsByName = [
            self::ROOT_SKILL_NAME => $rootSkill,
            self::LOADED_SUB_SKILL_NAME => $loadedSubSkill,
            self::OFFERED_SUB_SKILL_NAME => $offeredSubSkill,
        ];
    }

    public function getAISkillByName(string $skillName): ?AISkill
    {
        return $this->skillsByName[$skillName] ?? null;
    }

    public function getCatalog(): AISkills
    {
        $aISkills = AISkills::newInstance();
        foreach ($this->skillsByName as $aISkill) {
            $aISkills->add($aISkill);
        }
        return $aISkills;
    }
}
