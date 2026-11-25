<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Installation;

use DDD\Domain\AI\Entities\Installation\AgentFeatureTableConditions;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The port through which a package (or the application) declares when the tables of its entities are required — only
 * while a feature is switched on, or while a skill is bound. `app:agent:check-installation` (`missingTables`) reports
 * a missing table of a package entity as BLOCKING only while every condition of it holds. Every service implementing
 * the interface is collected by its tag; a package ships it in a public `…\Services\` namespace (an organ package in
 * its optional part that requires ddd-agents).
 *
 * The provider reads configuration only and never throws; a provider that throws is skipped with a warning.
 */
#[AutoconfigureTag(AgentFeatureTableConditionProvider::TAG)]
interface AgentFeatureTableConditionProvider
{
    public const string TAG = 'ai.agent_feature_table_condition_provider';

    public function getFeatureTableConditions(): AgentFeatureTableConditions;
}
