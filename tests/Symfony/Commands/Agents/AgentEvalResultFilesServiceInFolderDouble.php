<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Services\Eval\AgentEvalResultFilesService;

/**
 * The result files service of the evaluation commands' tests: its results folder is the test's folder.
 */
class AgentEvalResultFilesServiceInFolderDouble extends AgentEvalResultFilesService
{
    /** @var string The results folder */
    public string $resultsDirectoryOfTest = '';

    public function getResultsDirectory(): string
    {
        return $this->resultsDirectoryOfTest;
    }
}
