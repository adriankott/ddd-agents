<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Support;

use DDD\Infrastructure\Libs\Config;
use ReflectionProperty;

/**
 * Puts an application's `AI.webSearch` declaration where {@see Config::get()} reads it first (the values it has already
 * read), and puts the previous values back.
 */
class WebSearchTestConfiguration
{
    /**
     * @param array<string, mixed> $webSearchConfiguration the whole `AI.webSearch` array
     * @return array<string, mixed> the values Config held before
     */
    public static function declareWebSearchConfiguration(array $webSearchConfiguration): array
    {
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $configValuesBefore = $configTree->isInitialized() ? (array)$configTree->getValue() : [];
        $configValues = $configValuesBefore;
        $configValues['AI']['webSearch'] = $webSearchConfiguration;
        $configTree->setValue(null, $configValues);
        return $configValuesBefore;
    }

    /** @param array<string, mixed> $configValuesBefore */
    public static function restoreConfigValues(array $configValuesBefore): void
    {
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $configTree->setValue(null, $configValuesBefore);
    }

    /**
     * The package's default configuration with the research switched on and an endpoint for every capability.
     *
     * @return array<string, mixed>
     */
    public static function buildEnabledConfiguration(): array
    {
        $webSearchConfiguration = require dirname(__DIR__, 2) . '/config/app/AI/webSearch.php';
        $webSearchConfiguration['enabled'] = true;
        $webSearchConfiguration['endpoints'] = [
            'search' => '/web/search',
            'urlToMarkdown' => 'POST:/web/urlToMarkdown',
            'screenshot' => 'https://renderer.example/screenshot',
        ];
        return $webSearchConfiguration;
    }
}
