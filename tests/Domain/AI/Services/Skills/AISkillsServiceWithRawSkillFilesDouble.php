<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Skills;

use DDD\Domain\AI\Services\Skills\AISkillsService;

/** The skills service reading fixed skill file texts instead of the config roots; no overlay, no persona. */
class AISkillsServiceWithRawSkillFilesDouble extends AISkillsService
{
    /**
     * @param string[] $rawSkillFilesBySkillName skill name => file text
     */
    public function __construct(protected array $rawSkillFilesBySkillName)
    {
    }

    public function findRawSkillFile(string $skillName): mixed
    {
        return $this->rawSkillFilesBySkillName[$skillName] ?? null;
    }

    public function getSkillRequirementsBySkillName(): array
    {
        return [];
    }

    public function getAgentPersona(): array
    {
        return [];
    }
}
