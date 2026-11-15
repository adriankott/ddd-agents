<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Skills;

use DDD\Domain\AI\Services\Skills\AISkillsService;

/** The skills service with a fixed list of registered skill names instead of the package registry. */
class AISkillsServiceWithFixedSkillNames extends AISkillsService
{
    /** @param string[] $fixedSkillNames */
    public function __construct(protected array $fixedSkillNames)
    {
    }

    public function getAllSkillNames(): array
    {
        return $this->fixedSkillNames;
    }
}
