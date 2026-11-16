<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Evals\SuiteRuns\AgentEvalSuiteRun;

/**
 * The default trace sentence of a stored run names the results folder as configured (`AI.evalSuite.resultsDirectory`,
 * not resolved against this host) and the run's folder in it; the package default when nothing is configured.
 */
class AgentEvalSuiteRunTraceSentenceTest extends AgentEvalResultFilesTestCase
{
    public function testTheTraceSentenceNamesTheConfiguredResultsFolder(): void
    {
        $agentEvalSuiteRun = AgentEvalSuiteRun::newInstance();
        $agentEvalSuiteRun->host = 'runner-1';
        $agentEvalSuiteRun->logDirectoryName = '2026-11-16_100000-suite';

        $this->setResultsDirectoryConfigValue('storage/evals/');
        self::assertStringContainsString('Host: runner-1 · traces under storage/evals/2026-11-16_100000-suite on that host', $agentEvalSuiteRun->toLlmMarkdown());

        $this->setResultsDirectoryConfigValue(null);
        $agentEvalSuiteRun->logDirectoryName = null;
        self::assertStringContainsString('Host: runner-1 · traces under var/agent-evals/(not written) on that host', $agentEvalSuiteRun->toLlmMarkdown());
        self::assertSame('var/agent-evals', $this->getResultFilesService()->getConfiguredResultsDirectory());
    }
}
