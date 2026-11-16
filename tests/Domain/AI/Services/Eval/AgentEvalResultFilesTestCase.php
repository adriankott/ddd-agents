<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Services\Eval\AgentEvalResultFilesService;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use ReflectionProperty;

/**
 * The base of the tests of the result files: every test works in a temporary folder of its own, created before and
 * removed after it (links inside it are removed as links, their targets are never entered), and points the results
 * folder of the evaluation runs (`AI.evalSuite.resultsDirectory`) at a folder inside it.
 */
abstract class AgentEvalResultFilesTestCase extends IntegrationTestCase
{
    /** @var string The folder of the test's expected files: the stored format of every file a run writes */
    public const string FIXTURE_DIRECTORY = __DIR__ . '/Fixtures/ResultFiles';

    /** @var string The temporary folder of the test */
    protected string $temporaryDirectory = '';

    /** @var string The results folder inside the temporary folder */
    protected string $resultsDirectory = '';

    /** @var array<string, mixed>|null the config values read so far, restored after every test */
    protected ?array $configTreeBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = sys_get_temp_dir() . '/agent-eval-result-files-' . bin2hex(random_bytes(6));
        mkdir($this->temporaryDirectory, 0775, true);
        $this->resultsDirectory = $this->temporaryDirectory . '/results';
        mkdir($this->resultsDirectory, 0775, true);
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $this->configTreeBefore = $configTree->isInitialized() ? (array)$configTree->getValue() : null;
        $this->setResultsDirectoryConfigValue($this->resultsDirectory);
    }

    protected function onTearDown(): void
    {
        (new ReflectionProperty(Config::class, 'configTree'))->setValue(null, $this->configTreeBefore ?? []);
        if ($this->temporaryDirectory !== '' && is_dir($this->temporaryDirectory)) {
            $this->removeTemporaryEntry($this->temporaryDirectory);
        }
    }

    /** Sets the configured results folder the way an application's config file would. */
    protected function setResultsDirectoryConfigValue(?string $resultsDirectory): void
    {
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $configValues = $configTree->isInitialized() ? (array)$configTree->getValue() : [];
        $configValues['AI']['evalSuite'] = ['resultsDirectory' => $resultsDirectory, 'resourceGroupByFixture' => [], 'exclusiveFixtures' => []];
        $configTree->setValue(null, $configValues);
    }

    /** The package's result files service, as the container gives it. */
    protected function getResultFilesService(): AgentEvalResultFilesService
    {
        /** @var AgentEvalResultFilesService $agentEvalResultFilesService */
        $agentEvalResultFilesService = DDDService::instance()->getService(AgentEvalResultFilesService::class);
        return $agentEvalResultFilesService;
    }

    /**
     * The decoded input the expected files were written from.
     *
     * @return array<string, mixed>
     */
    protected function readFixtureInput(): array
    {
        return (array)json_decode((string)file_get_contents(self::FIXTURE_DIRECTORY . '/input.json'), true);
    }

    /** Writes a file below the temporary folder, creating its folder. */
    protected function writeTemporaryFile(string $filePath, string $content): void
    {
        if (!is_dir(dirname($filePath))) {
            mkdir(dirname($filePath), 0775, true);
        }
        file_put_contents($filePath, $content);
    }

    /** Removes an entry of the temporary folder: a link as a link, a folder with everything below it. */
    protected function removeTemporaryEntry(string $entryPath): void
    {
        if (is_link($entryPath) || !is_dir($entryPath)) {
            @unlink($entryPath);
            return;
        }
        foreach ((array)scandir($entryPath) as $childName) {
            if (is_string($childName) && $childName !== '.' && $childName !== '..') {
                $this->removeTemporaryEntry($entryPath . '/' . $childName);
            }
        }
        @rmdir($entryPath);
    }
}
