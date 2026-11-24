<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallImageStoreService;
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\ObjectStorage\ObjectStorage;

/**
 * The picture store with its configuration, cache groups and object store given by the test; the tier logic, the
 * warnings and the checks are the real ones. {@see resetProcessState()} is the start of a new process.
 */
class TieredAgentToolCallImageStoreServiceDouble extends AgentToolCallImageStoreService
{
    public const string DEFAULT_GROUP = '(default)';

    /** @var array<string, mixed> The configuration nodes by key (a missing key is not set) */
    public array $configNodesByKey = [];

    /** @var array<string, Cache> The cache groups by name ({@see DEFAULT_GROUP} for null) */
    public array $cachesByGroup = [];

    /** @var array<string, ObjectStorage> The object stores by name */
    public array $objectStoragesByName = [];

    public const string PROCESS_CACHE_GROUP = 'l1';

    public const string SHARED_CACHE_GROUP = 'l2';

    public const string OBJECT_STORAGE_NAME = 'images';

    public ?InMemoryCacheGroupDouble $processCache = null;

    public ?InMemoryCacheGroupDouble $sharedCache = null;

    public ?ObjectStorage $objectStorage = null;

    /**
     * The store with the given tiers ({@see PROCESS_CACHE_GROUP}, {@see SHARED_CACHE_GROUP}, {@see OBJECT_STORAGE_NAME})
     * and its process state reset; `$extraConfig` is merged over the generated `AI.toolCallImageStore` node.
     *
     * @param array<string, mixed> $extraConfig
     */
    public static function withTiers(bool $withProcessCache, bool $withSharedCache, ?ObjectStorage $objectStorage, array $extraConfig = []): static
    {
        $storeService = new static();
        $storeService->resetProcessState();
        if ($withProcessCache) {
            $storeService->processCache = new InMemoryCacheGroupDouble();
            $storeService->cachesByGroup[self::PROCESS_CACHE_GROUP] = $storeService->processCache;
        }
        if ($withSharedCache) {
            $storeService->sharedCache = new InMemoryCacheGroupDouble();
            $storeService->cachesByGroup[self::SHARED_CACHE_GROUP] = $storeService->sharedCache;
        }
        if ($objectStorage !== null) {
            $storeService->objectStorage = $objectStorage;
            $storeService->objectStoragesByName[self::OBJECT_STORAGE_NAME] = $objectStorage;
        }
        $storeService->configNodesByKey[self::CONFIG_KEY] = array_replace_recursive([
            'processCache' => ['enabled' => $withProcessCache, 'cacheGroup' => self::PROCESS_CACHE_GROUP],
            'sharedCache' => ['enabled' => $withSharedCache, 'cacheGroup' => self::SHARED_CACHE_GROUP],
            'objectStore' => ['enabled' => $objectStorage !== null, 'objectStorage' => self::OBJECT_STORAGE_NAME],
        ], $extraConfig);
        return $storeService;
    }

    public function resetProcessState(): void
    {
        static::$reportedWarnings = [];
        static::$processCacheWindowStartedAt = 0.0;
        static::$processCacheBytesInWindow = 0;
    }

    protected function readConfigNode(string $configKey): mixed
    {
        return $this->configNodesByKey[$configKey] ?? null;
    }

    protected function resolveCacheGroup(?string $cacheGroup): ?Cache
    {
        return $this->cachesByGroup[$cacheGroup ?? self::DEFAULT_GROUP] ?? null;
    }

    protected function resolveObjectStorage(string $objectStorageName): ?ObjectStorage
    {
        return $this->objectStoragesByName[$objectStorageName] ?? null;
    }
}
