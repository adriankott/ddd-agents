<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\Eval;

use DDD\Domain\AI\Services\Eval\AgentEvalResultFilesService;

/**
 * The result files service with the results folder of one test: every file of a suite run lands below the test's
 * temporary folder, whatever the configuration of the kernel says.
 */
class AgentEvalResultFilesServiceInTestFolderDouble extends AgentEvalResultFilesService
{
    public function __construct(public string $resultsDirectoryOfTest)
    {
    }

    public function getResultsDirectory(): string
    {
        return $this->resultsDirectoryOfTest;
    }
}
