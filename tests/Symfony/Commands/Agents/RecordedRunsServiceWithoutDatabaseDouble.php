<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunSession;
use DDD\Domain\AI\Services\Eval\AgentRecordedRunsService;

/**
 * The package's service of recorded runs, building the session whose database reads are replaced
 * ({@see RecordedRunSessionWithoutDatabaseDouble}); the recording file is read and written as the package does.
 */
class RecordedRunsServiceWithoutDatabaseDouble extends AgentRecordedRunsService
{
    protected function newSession(): AgentRecordedRunSession
    {
        return new RecordedRunSessionWithoutDatabaseDouble();
    }
}
