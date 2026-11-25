<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Installation;

use DDD\Domain\AI\Entities\Installation\AgentInstallationFinding;
use DDD\Domain\AI\Entities\Installation\AgentInstallationFindings;
use DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallImageStoreService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\ObjectStorageService;
use DDD\Infrastructure\Services\Service;

/**
 * Installation check `toolImageStore`: the picture store's object store (L3, `AI.toolCallImageStore.objectStore`) is
 * switched on but names a store that is not configured (`config/app/Common/ObjectStorage.php`,
 * `OBJECT_STORAGE_<NAME>_*`). The pictures then live only as long as the shared cache keeps them; a resumed
 * conversation loses them. WARNING.
 */
class AgentToolImageStoreInstallationCheckService extends Service implements AgentInstallationCheckInterface
{
    public const string CHECK_NAME = 'toolImageStore';

    public function getInstallationCheckName(): string
    {
        return static::CHECK_NAME;
    }

    public function findInstallationFindings(): AgentInstallationFindings
    {
        $agentInstallationFindings = AgentInstallationFindings::newInstance();
        /** @var AgentToolCallImageStoreService $agentToolCallImageStoreService */
        $agentToolCallImageStoreService = DDDService::instance()->getService(AgentToolCallImageStoreService::class);
        $imageStoreSettings = $agentToolCallImageStoreService->getSettings();
        if (!$imageStoreSettings->enabled || !$imageStoreSettings->objectStoreEnabled) {
            return $agentInstallationFindings;
        }
        if (!$this->isObjectStorageConfigured($imageStoreSettings->objectStorageName)) {
            $agentInstallationFindings->addFinding(
                static::CHECK_NAME,
                AgentInstallationFinding::SEVERITY_WARNING,
                $imageStoreSettings->objectStorageName,
                "The picture store's object store '{$imageStoreSettings->objectStorageName}' (AI.toolCallImageStore.objectStore) is not configured: tool pictures live only in the caches and are lost after their TTL or a resume.",
                "Configure the store in config/app/Common/ObjectStorage.php (credentials OBJECT_STORAGE_<NAME>_*), or set objectStore.enabled to false in config/app/AI/toolCallImageStore.php."
            );
        }
        return $agentInstallationFindings;
    }

    /** Whether Core has a store of that name (overridable for a test) */
    protected function isObjectStorageConfigured(string $objectStorageName): bool
    {
        /** @var ObjectStorageService $objectStorageService */
        $objectStorageService = DDDService::instance()->getService(ObjectStorageService::class);
        return $objectStorageService->isObjectStorageConfigured($objectStorageName);
    }
}
