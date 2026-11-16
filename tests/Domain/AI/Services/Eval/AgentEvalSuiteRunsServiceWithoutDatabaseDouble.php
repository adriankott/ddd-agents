<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Evals\SuiteRuns\AgentEvalSuiteRun;
use DDD\Domain\AI\Services\Eval\AgentEvalSuiteRunsService;
use RuntimeException;
use Throwable;

/**
 * The record service without a database: the insert either keeps the built run (and gives it an id) or fails with
 * the message of a missing table; a failure the service reports to the issues log is kept as one line.
 */
class AgentEvalSuiteRunsServiceWithoutDatabaseDouble extends AgentEvalSuiteRunsService
{
    /** @var bool Whether the insert fails */
    public bool $insertFails = false;

    /** @var AgentEvalSuiteRun|null The run the service handed to the insert */
    public ?AgentEvalSuiteRun $insertedAgentEvalSuiteRun = null;

    /** @var string|null "<command>|<label>|<case count>|<message>" of the reported failure */
    public ?string $loggedFailure = null;

    protected function insertSuiteRunWithItsCases(AgentEvalSuiteRun $agentEvalSuiteRun): AgentEvalSuiteRun
    {
        if ($this->insertFails) {
            throw new RuntimeException("Table 'AgentEvalSuiteRuns' doesn't exist");
        }
        $agentEvalSuiteRun->id = 17;
        $this->insertedAgentEvalSuiteRun = $agentEvalSuiteRun;
        return $agentEvalSuiteRun;
    }

    protected function logFailedRecordWrite(Throwable $throwable, string $command, ?string $suiteLabel, int $caseCount): void
    {
        $this->loggedFailure = "$command|$suiteLabel|$caseCount|" . $throwable->getMessage();
    }
}
