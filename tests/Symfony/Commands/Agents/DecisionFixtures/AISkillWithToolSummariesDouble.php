<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\DecisionFixtures;

use DDD\Domain\AI\Entities\Conversations\AIConversationEntitlement;
use DDD\Domain\AI\Entities\Skills\AISkill;

/** A skill whose tool summaries the test gives: no package is read. */
class AISkillWithToolSummariesDouble extends AISkill
{
    /** @var array<string, string> The tool summaries by tool name */
    public array $toolSummariesByName = [];

    public function getToolSummaries(
        ?AIConversationEntitlement $entitlement = null,
        int $maximumSummaryChars = self::MAX_DECISION_TOOL_SUMMARY_CHARS
    ): array {
        return $this->toolSummariesByName;
    }
}
