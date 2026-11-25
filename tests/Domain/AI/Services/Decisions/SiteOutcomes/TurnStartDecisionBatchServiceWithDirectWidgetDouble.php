<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Services\Conversations\Agent\AgentDirectWidgetDecisionsService;

/** The turn-start batch over the site doubles with the direct-widget site double beside the others. */
class TurnStartDecisionBatchServiceWithDirectWidgetDouble extends TurnStartDecisionBatchServiceOverSiteDoubles
{
    public ?DirectWidgetServiceWithScriptedInputDouble $directWidgetServiceDouble = null;

    protected function directWidgetDecisionsService(): AgentDirectWidgetDecisionsService
    {
        return $this->directWidgetServiceDouble ?? parent::directWidgetDecisionsService();
    }
}
