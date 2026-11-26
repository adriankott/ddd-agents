<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\Client\Agents;

use DDD\Domain\AI\Entities\Skills\AISkill;

/** A skill whose packages the test gives (the configured binding and the package switches are not read). */
class AISkillOfPackagesDouble extends AISkill
{
    /** @var string[] The MCP package classes of the skill */
    public array $packageClasses = [];

    public function getPackages(): array
    {
        return $this->packageClasses;
    }

    public function getResidentBodyTokens(): int
    {
        return 100;
    }
}
