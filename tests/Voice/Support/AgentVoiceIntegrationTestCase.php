<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Support;

use DDD\Tests\Agents\Support\IntegrationTestCase;

/**
 * Base class for the voice tests that need the kernel's container: the package's {@see IntegrationTestCase}
 * (`replaceService()` puts a double behind a container service; the authenticated account is cleared and the DDD
 * bundle pointed back at the bootstrap container after every test) with the per-test config of
 * {@see AgentVoiceTestConfigurationTrait}.
 */
abstract class AgentVoiceIntegrationTestCase extends IntegrationTestCase
{
    use AgentVoiceTestConfigurationTrait;

    protected function onTearDown(): void
    {
        $this->restoreConfigurationOfTheTest();
    }
}
