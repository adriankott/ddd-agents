<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\WebSearch;

use DDD\Domain\AI\Entities\WebSearch\WebSearchConfiguration;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;

/**
 * The load endpoint of a web-research repository comes from the application's configuration, not from the class:
 * `AI.webSearch.endpoints.<capability>` (the using class names its capability in `WEB_SEARCH_CAPABILITY`). A value
 * without a method is sent as POST; a relative path goes through the Argus gateway, an absolute URL is called
 * directly. `AI.webSearch.cacheTtlSeconds.<capability>` and `AI.webSearch.timeoutSeconds.<capability>` replace the
 * class defaults when set; `AI.webSearch.cacheInDatabase.<capability>` (true / false) adds or drops the database cache
 * beside the memory cache. Everything is set on THIS instance's settings (a copy of the attribute), never on the class.
 */
trait ArgusWebSearchConfiguredEndpointTrait
{
    /** The configured endpoint of this repository, method-prefixed; null when the application configures none. */
    public static function findConfiguredLoadEndpoint(): ?string
    {
        $configuredEndpoint = trim((string)(WebSearchConfiguration::findValue('endpoints.' . static::WEB_SEARCH_CAPABILITY) ?? ''));
        if ($configuredEndpoint === '') {
            return null;
        }
        if (preg_match('/^(GET|POST|PUT|PATCH|DELETE):/i', $configuredEndpoint) === 1) {
            return $configuredEndpoint;
        }
        return 'POST:' . $configuredEndpoint;
    }

    /** The configured request timeout of this capability in seconds; null keeps the class default. */
    public static function findConfiguredTimeoutSeconds(): ?float
    {
        $configuredTimeoutSeconds = WebSearchConfiguration::findValue('timeoutSeconds.' . static::WEB_SEARCH_CAPABILITY);
        return is_numeric($configuredTimeoutSeconds) && (float)$configuredTimeoutSeconds > 0 ? (float)$configuredTimeoutSeconds : null;
    }

    /**
     * Sets the configured endpoint, cache lifetime and timeout on this loader; false (nothing set) when the
     * application configures no endpoint for the capability.
     */
    public function applyConfiguredLoadSettings(): bool
    {
        $configuredEndpoint = static::findConfiguredLoadEndpoint();
        if ($configuredEndpoint === null) {
            return false;
        }
        $argusSettings = $this->getArgusSettings();
        $argusSettings->argusLoad->loadEndpoint = $configuredEndpoint;
        $configuredCacheTtlSeconds = WebSearchConfiguration::findValue('cacheTtlSeconds.' . static::WEB_SEARCH_CAPABILITY);
        if (is_numeric($configuredCacheTtlSeconds) && (int)$configuredCacheTtlSeconds >= 0) {
            $argusSettings->argusLoad->cacheTtl = (int)$configuredCacheTtlSeconds;
        }
        $configuredCacheInDatabase = WebSearchConfiguration::findValue('cacheInDatabase.' . static::WEB_SEARCH_CAPABILITY);
        if (is_bool($configuredCacheInDatabase) && $argusSettings->argusLoad->cacheLevel !== ArgusCache::CACHELEVEL_NONE) {
            $argusSettings->argusLoad->cacheLevel = $configuredCacheInDatabase ? ArgusCache::CACHELEVEL_MEMORY_AND_DB : ArgusCache::CACHELEVEL_MEMORY;
        }
        $configuredTimeoutSeconds = static::findConfiguredTimeoutSeconds();
        if ($configuredTimeoutSeconds !== null) {
            $argusSettings->timeout = $configuredTimeoutSeconds;
        }
        return true;
    }
}
