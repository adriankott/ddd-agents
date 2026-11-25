<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Agent\OfferedSkillCatalog;
use RuntimeException;

/**
 * A conversation that records the run every catalog build was handed, then stops the build: the site that asked
 * swallows the failure as it does every catalog error, so only the handed run is observed.
 */
class CatalogBuildRecordingConversation extends AIConversation
{
    /** @var array<int, AgentRun|null> the run of every catalog build, in call order */
    public array $catalogBuildAgentRuns = [];

    public function buildOfferedSkillCatalog(?AIConversationMessage $anchorMessage = null, array $assumeLoadedSkillNames = [], ?AgentRun $agentRun = null): OfferedSkillCatalog
    {
        $this->catalogBuildAgentRuns[] = $agentRun;
        throw new RuntimeException('the catalog is not built in this test');
    }
}
