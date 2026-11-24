<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Wire;

use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentWireConfiguration;

/** A wire configuration that keeps the catalog values it would log as not spoken. */
class AgentWireConfigurationWithRecordedLog extends AgentWireConfiguration
{
    /** @var array<int, array{0: string|null, 1: string}> The logged pairs of model name and catalog value, in order */
    public static array $loggedValues = [];

    protected static function logCatalogWireApiNotSpoken(?string $modelName, string $catalogValue): void
    {
        static::$loggedValues[] = [$modelName, $catalogValue];
    }
}
