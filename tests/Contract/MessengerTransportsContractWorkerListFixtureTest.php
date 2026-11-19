<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Contract;

use PHPUnit\Framework\TestCase;

/**
 * The transport contract against an application that runs its workers from the framework's worker list
 * (`config/system/workers.yaml` beside `config/packages/messenger.yaml`) and has no supervisor file: no override
 * beyond the Messenger configuration's path.
 */
class MessengerTransportsContractWorkerListFixtureTest extends TestCase
{
    use MessengerTransportsHaveWorkerBlocksContract;

    protected function messengerConfigPath(): string
    {
        return __DIR__ . '/Fixtures/WorkerListApplication/config/packages/messenger.yaml';
    }

    public function testTheWorkerListBesideTheMessengerConfigurationIsFound(): void
    {
        self::assertSame(__DIR__ . '/Fixtures/WorkerListApplication/config/system/workers.yaml', $this->workerListConfigPath());
        self::assertNull($this->supervisorConfigPath());
    }
}
