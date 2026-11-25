<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Support;

use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * Base class for the voice channel's tests of pure domain logic: the package's {@see UnitTestCase} (no kernel of
 * its own; the bootstrap kernel is running) with the per-test config of {@see AgentVoiceTestConfigurationTrait}.
 */
abstract class AgentVoiceUnitTestCase extends UnitTestCase
{
    use AgentVoiceTestConfigurationTrait;

    protected function tearDown(): void
    {
        $this->restoreConfigurationOfTheTest();
        parent::tearDown();
    }
}
