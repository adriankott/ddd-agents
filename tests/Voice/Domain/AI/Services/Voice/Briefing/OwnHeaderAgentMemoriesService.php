<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Briefing;

use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoriesService;

/**
 * The memory port of an application that keeps the package's memory store and only names the header of the
 * standing-directives block itself.
 */
class OwnHeaderAgentMemoriesService extends AgentMemoriesService
{
    protected function getDirectivesBlockHeader(): string
    {
        return '# Standing rules the shop owner set';
    }
}
