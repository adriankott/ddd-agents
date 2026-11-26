<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Memories;

/** The memory port of an application that names the header of the standing-directives block itself. */
class AgentMemoriesServiceWithOwnDirectivesHeaderDouble extends AgentMemoriesServiceSwitchedOnDouble
{
    public const string OWN_DIRECTIVES_BLOCK_HEADER = '# Standing rules the shop owner set';

    protected function getDirectivesBlockHeader(): string
    {
        return self::OWN_DIRECTIVES_BLOCK_HEADER;
    }
}
