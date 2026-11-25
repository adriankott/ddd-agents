<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDirectWidgetDecisionQuestion;
use DDD\Domain\AI\Services\Conversations\Agent\AgentDirectWidgetDecisionsService;

/** The direct-widget site with its turn-start request INPUT given by the test and its application run as it is. */
class DirectWidgetServiceWithScriptedInputDouble extends AgentDirectWidgetDecisionsService
{
    /** The question the site contributes this turn (null = it does not run). */
    public ?AgentDirectWidgetDecisionQuestion $scriptedInput = null;

    public function __construct()
    {
    }

    public function buildRequestInput(AIConversation $conversation, AgentDecisionModelSettings $decisionModelSettings): ?AgentDirectWidgetDecisionQuestion
    {
        return $this->scriptedInput;
    }
}
