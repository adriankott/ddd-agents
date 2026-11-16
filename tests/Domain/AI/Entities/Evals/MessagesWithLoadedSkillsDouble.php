<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;

/**
 * Messages whose loaded skills are given by the test (the loaded capabilities of a real row need a stored skill catalog).
 */
class MessagesWithLoadedSkillsDouble extends AIConversationMessages
{
    /** @var string[] The skills the messages report as loaded */
    public array $loadedSkillNamesOfTest = [];

    /** @return string[] */
    public function getLoadedSkillNames(): array
    {
        return $this->loadedSkillNamesOfTest;
    }
}
