<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Installation;

use DDD\Domain\AI\Entities\Installation\AgentInstallationFindings;
use DDD\Domain\AI\Services\Installation\AgentInstallationCheckInterface;
use RuntimeException;

/** An added check whose read fails */
class AgentInstallationCheckThrowingDouble implements AgentInstallationCheckInterface
{
    public function getInstallationCheckName(): string
    {
        return 'brokenHostCheck';
    }

    public function findInstallationFindings(): AgentInstallationFindings
    {
        throw new RuntimeException("the host store did not answer\nsecond line is not shown");
    }
}
