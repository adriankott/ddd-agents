<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\MessageHandlers;

use DDD\Domain\AI\MessageHandlers\AIConversationSummarizeMessage;
use DDD\Tests\Agents\Support\UnitTestCase;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A handler may run in-process from a console command with a higher memory limit. `ini_set('memory_limit', '512M')`
 * as its first line lowered that limit, and with the usage already above 512M PHP warned "Failed to set memory limit",
 * which the dev kernel turned into an exception and the job failed. Handlers and the services a job runs raise the
 * limit ({@see \DDD\Infrastructure\Services\DDDService::raiseMemoryLimitToAtLeast()}); only a command, its own process,
 * sets it.
 */
class HandlersRaiseTheMemoryLimitTest extends UnitTestCase
{
    protected const int MEGABYTE = 1024 * 1024;

    public function testAHandlerKeepsAHigherLimitOfTheProcessItRunsIn(): void
    {
        self::assertSame((string)(2048 * self::MEGABYTE), $this->findMemoryLimitAfterTheHandlerStarted((string)(2048 * self::MEGABYTE)));
    }

    public function testAHandlerKeepsAnUnlimitedProcessUnlimited(): void
    {
        self::assertSame('-1', $this->findMemoryLimitAfterTheHandlerStarted('-1'));
    }

    public function testAHandlerRaisesALowerLimitToItsOwn(): void
    {
        $lowerMemoryLimitInBytes = memory_get_usage() + 32 * self::MEGABYTE;
        if ($lowerMemoryLimitInBytes >= 512 * self::MEGABYTE) {
            self::markTestSkipped('the suite already uses more than the handler\'s own limit');
        }
        self::assertSame('512M', $this->findMemoryLimitAfterTheHandlerStarted((string)$lowerMemoryLimitInBytes));
    }

    public function testNoJobCodeSetsTheMemoryLimit(): void
    {
        $sourceDirectory = dirname(__DIR__, 4) . '/src';
        $commandsDirectory = $sourceDirectory . '/Symfony/Commands/';
        $filesSettingTheLimit = [];
        /** @var SplFileInfo $sourceFile */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDirectory, RecursiveDirectoryIterator::SKIP_DOTS)) as $sourceFile) {
            if ($sourceFile->getExtension() !== 'php' || str_starts_with($sourceFile->getPathname(), $commandsDirectory)) {
                continue;
            }
            if (preg_match('/ini_set\(\s*[\'"]memory_limit[\'"]/', (string)file_get_contents($sourceFile->getPathname()))) {
                $filesSettingTheLimit[] = substr($sourceFile->getPathname(), strlen($sourceDirectory) + 1);
            }
        }
        self::assertSame([], $filesSettingTheLimit, 'outside a console command the memory limit is raised, never set');
    }

    /**
     * Runs the summarize handler up to its first step after the limits with $memoryLimit set, and restores the
     * limits of the suite afterwards.
     */
    protected function findMemoryLimitAfterTheHandlerStarted(string $memoryLimit): string
    {
        $memoryLimitOfTheSuite = (string)ini_get('memory_limit');
        $timeLimitOfTheSuite = (int)ini_get('max_execution_time');
        $summarizeHandler = new SummarizeHandlerStoppingAfterTheMemoryLimitDouble();
        ini_set('memory_limit', $memoryLimit);
        try {
            $summarizeHandler(new AIConversationSummarizeMessage(1));
            self::fail('the handler did not reach its auth step');
        } catch (LogicException $stopException) {
            self::assertSame(SummarizeHandlerStoppingAfterTheMemoryLimitDouble::STOP_MESSAGE, $stopException->getMessage());
            return (string)ini_get('memory_limit');
        } finally {
            ini_set('memory_limit', $memoryLimitOfTheSuite);
            set_time_limit($timeLimitOfTheSuite);
        }
    }
}
