<?php

declare(strict_types=1);

namespace DDD\Testing\Agents\Installation;

use DDD\Domain\AI\Entities\Installation\AgentInstallationFinding;
use DDD\Domain\AI\Entities\Installation\AgentInstallationFindings;
use DDD\Domain\AI\Services\Installation\AgentInstallationCheckService;
use DDD\Infrastructure\Services\DDDService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;

/**
 * Runs the installation check of the agent (`app:agent:check-installation`) as a test of the application's suite, under
 * its own container and config: how the application bound the packages' skills, mapped their rights, named its
 * commands and overlaid their tools is checked with every test run, not only when somebody runs the command.
 *
 *   class AgentInstallationCheckTest extends \DDD\Testing\Agents\Installation\AgentInstallationCheckTestCase
 *   {
 *       protected function createConsoleApplication(): ?Application
 *       {
 *           return new \Symfony\Bundle\FrameworkBundle\Console\Application(MyTestKernel::get());
 *       }
 *   }
 *
 * {@see testTheInstallationHasNoFindingOfAFailingSeverity()} fails on every finding of a failing severity
 * ({@see getFailingSeverities()}: BLOCKING; an application that keeps its installation free of warnings adds WARNING).
 * A finding the application accepts is accepted where the command reads it (`config/app/AI/installationCheck.php`:
 * the accepted names of a check, or `skippedChecks`), so the test and the command always agree.
 *
 * The application's test bootstrap boots its kernel; this class boots nothing. Without a console application the
 * check of the command names does not run ({@see createConsoleApplication()}).
 */
abstract class AgentInstallationCheckTestCase extends TestCase
{
    /**
     * @return Application|null The console of the application, so that the command names are checked; null = that
     * check is skipped
     */
    protected function createConsoleApplication(): ?Application
    {
        return null;
    }

    /**
     * @return string[] The severities a finding fails the test with
     */
    protected function getFailingSeverities(): array
    {
        return [AgentInstallationFinding::SEVERITY_BLOCKING];
    }

    /**
     * @return string[] Check names whose findings never fail the test (a check that needs what a test run does not
     * have: `missingTables` in a suite without a database)
     */
    protected function getCheckNamesNotFailingTheTest(): array
    {
        return [];
    }

    public function testTheInstallationHasNoFindingOfAFailingSeverity(): void
    {
        $failingFindingLines = [];
        $failingSeverities = $this->getFailingSeverities();
        $checkNamesNotFailingTheTest = $this->getCheckNamesNotFailingTheTest();
        foreach ($this->findInstallationFindings()->getElements() as $agentInstallationFinding) {
            if (!in_array($agentInstallationFinding->severity, $failingSeverities, true)
                || in_array($agentInstallationFinding->checkName, $checkNamesNotFailingTheTest, true)
            ) {
                continue;
            }
            $failingFindingLines[] = sprintf(
                '%s %s [%s] %s Hint: %s',
                $agentInstallationFinding->severity,
                $agentInstallationFinding->checkName,
                $agentInstallationFinding->subject,
                $agentInstallationFinding->message,
                $agentInstallationFinding->hint
            );
        }
        self::assertSame(
            [],
            $failingFindingLines,
            sprintf(
                "The installation check has %d finding(s) of a failing severity (%s). Fix the cause, or accept a finding in config/app/AI/installationCheck.php.\n%s",
                count($failingFindingLines),
                implode(', ', $failingSeverities),
                implode("\n", $failingFindingLines)
            )
        );
    }

    protected function findInstallationFindings(): AgentInstallationFindings
    {
        /** @var AgentInstallationCheckService $agentInstallationCheckService */
        $agentInstallationCheckService = DDDService::instance()->getService(AgentInstallationCheckService::class);
        return $agentInstallationCheckService->findInstallationFindings($this->createConsoleApplication());
    }
}
