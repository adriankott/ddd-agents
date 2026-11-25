<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Installation;

use DDD\Domain\AI\Services\Installation\AgentToolImageStoreInstallationCheckService;

/** The picture store check with the object stores of `$configuredObjectStorageNames` */
class AgentToolImageStoreInstallationCheckWithStoresDouble extends AgentToolImageStoreInstallationCheckService
{
    /** @var string[] */
    public array $configuredObjectStorageNames = [];

    protected function isObjectStorageConfigured(string $objectStorageName): bool
    {
        return in_array($objectStorageName, $this->configuredObjectStorageNames, true);
    }
}
