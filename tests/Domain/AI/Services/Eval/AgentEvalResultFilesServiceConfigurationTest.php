<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Services\Eval\AgentEvalResultFilesService;
use DDD\Infrastructure\Services\DDDService;

/**
 * The configuration of the evaluation suite: the package ships `config/app/AI/evalSuite.php` with a results folder
 * below the kernel project directory and no resource group and no exclusive fixture; a relative folder is taken
 * relative to the kernel project directory, an absolute one as it is, `.` and `..` resolved; the container gives the
 * package's service.
 */
class AgentEvalResultFilesServiceConfigurationTest extends AgentEvalResultFilesTestCase
{
    public const string PACKAGE_CONFIG_FILE = __DIR__ . '/../../../../../config/app/AI/evalSuite.php';

    public function testThePackageFileShipsEveryKeyAndTheGroupsAreEmpty(): void
    {
        $evalSuiteConfiguration = require self::PACKAGE_CONFIG_FILE;

        self::assertSame(['resultsDirectory' => AgentEvalResultFilesService::DEFAULT_RESULTS_DIRECTORY, 'resourceGroupByFixture' => [], 'exclusiveFixtures' => [], 'suites' => [], 'fixturePaths' => []], $evalSuiteConfiguration);
    }

    public function testARelativeFolderIsBelowTheKernelProjectDirectory(): void
    {
        $projectDirectory = rtrim(DDDService::instance()->getRootDir(), '/');

        $this->setResultsDirectoryConfigValue('../shared/./evals/');
        self::assertSame(dirname($projectDirectory) . '/shared/evals', $this->getResultFilesService()->getResultsDirectory());

        $this->setResultsDirectoryConfigValue(null);
        self::assertSame($projectDirectory . '/' . AgentEvalResultFilesService::DEFAULT_RESULTS_DIRECTORY, $this->getResultFilesService()->getResultsDirectory(), 'no configured folder: the default');

        $this->setResultsDirectoryConfigValue('  ');
        self::assertSame($projectDirectory . '/' . AgentEvalResultFilesService::DEFAULT_RESULTS_DIRECTORY, $this->getResultFilesService()->getResultsDirectory());
    }

    public function testAnAbsoluteFolderIsTakenAsItIs(): void
    {
        $this->setResultsDirectoryConfigValue($this->resultsDirectory . '/');

        self::assertSame($this->resultsDirectory, $this->getResultFilesService()->getResultsDirectory());
    }

    public function testTheContainerGivesThePackagesServiceAndItsRetention(): void
    {
        $agentEvalResultFilesService = $this->getResultFilesService();

        self::assertSame(AgentEvalResultFilesService::class, $agentEvalResultFilesService::class);
        self::assertSame(28, $agentEvalResultFilesService->getRetentionDays());
        self::assertSame([AgentEvalResultFilesService::RUN_ENTRY_NAME_PATTERN], $agentEvalResultFilesService->getRetainedFolderNamePatterns());
        self::assertSame([AgentEvalResultFilesService::RUN_ENTRY_NAME_PATTERN], $agentEvalResultFilesService->getRetainedFileNamePatterns());
    }
}
