<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Evals\AgentEvalTiersResult;
use DDD\Domain\AI\Entities\Models\Selection\ModelTier;
use DDD\Symfony\Commands\Agents\AgentEvalConsoleCommand;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The tool evaluation per model tier: the header, per tier its model, its result line and (with `--full`) the failed
 * cases, an unknown tier and a tier without an eligible model, then the summary table; a case runs with its scope,
 * prompt, restore and tool block only, and a case that names no scope runs on the space of the scope options.
 *
 * The expected lines are the evaluation's line formats applied to the test's tiers (`Per-tier tool-eval — <cases>
 * case(s) × <tiers> tier(s) — LIVE`, `── <tier> → <model> ──`, `  %d/%d passed (%.0f%%) | avg %.1f tool-calls (%.1f
 * failed) | avg %s tokens | avg %.1fs`, `    FAIL <case>`, `Unknown tier '<tier>' — skipped`, `%-10s unresolved: %s`,
 * the summary header `%-10s %-30s %8s %7s %7s %9s %7s` and its rows `%-10s %-30s %7.0f%% %7.1f %7.1f %9s %7.1f`).
 */
class AgentEvalTiersCommandTest extends IntegrationTestCase
{
    protected string $workDirectory = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDirectory = sys_get_temp_dir() . '/agent-eval-tiers-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->workDirectory, 0775, true);
        file_put_contents($this->workDirectory . '/Catalog.json', (string)json_encode(['cases' => [
            ['id' => 'tiers.pass', 'agentSpaceId' => 7, 'prompt' => 'List', 'expect' => ['tool' => 'listItems'], 'followUpPrompts' => ['And more'], 'purposeName' => 'Some.Purpose', 'blockedTools' => ['deleteItem']],
            ['id' => 'tiers.fail', 'agentSpaceId' => 7, 'prompt' => 'Other', 'expect' => ['tool' => 'otherTool']],
            ['id' => 'tiers.error', 'agentSpaceId' => 7, 'prompt' => 'Break', 'expect' => []],
            ['id' => 'tiers.no-scope', 'prompt' => 'Nowhere', 'expect' => ['tool' => 'listItems']],
            ['id' => 'tiers.no-prompt', 'agentSpaceId' => 7],
        ]]));
    }

    protected function onTearDown(): void
    {
        @unlink($this->workDirectory . '/Catalog.json');
        @rmdir($this->workDirectory);
    }

    public function testEveryTierPrintsItsResultAndTheSummaryTable(): void
    {
        $probeCommand = $this->buildProbeCommand();

        $commandTester = $this->runProbeCommand($probeCommand, ['--tiers' => 'CHEAP, STANDARD,BOGUS,PREMIUM', '--full' => true]);

        self::assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $resultLine = sprintf('  %d/%d passed (%.0f%%) | avg %.1f tool-calls (%.1f failed) | avg %s tokens | avg %.1fs', 1, 3, 1 / 3 * 100.0, 1.0, 0.0, number_format(300), 0.0);
        self::assertSame(
            "Per-tier tool-eval — 3 case(s) × 4 tier(s) — LIVE\n"
            . "\n── CHEAP → VENDOR.SMALL ──\n"
            . $resultLine . "\n"
            . "    FAIL tiers.fail\n"
            . "    FAIL tiers.error (error: the model did not answer)\n"
            . "\n── STANDARD → VENDOR.MEDIUM ──\n"
            . $resultLine . "\n"
            . "    FAIL tiers.fail\n"
            . "    FAIL tiers.error (error: the model did not answer)\n"
            . "Unknown tier 'BOGUS' — skipped\n"
            . sprintf('%-10s unresolved: %s', 'PREMIUM', 'No eligible model in tier PREMIUM') . "\n"
            . "\n════ Summary (efficiency = lower is better) ════\n"
            . sprintf('%-10s %-30s %8s %7s %7s %9s %7s', 'TIER', 'MODEL', 'PASS%', 'CALLS', 'FAILED', 'TOKENS', 'DUR(s)') . "\n"
            . sprintf('%-10s %-30s %7.0f%% %7.1f %7.1f %9s %7.1f', 'CHEAP', 'VENDOR.SMALL', 1 / 3 * 100.0, 1.0, 0.0, number_format(300), 0.0) . "\n"
            . sprintf('%-10s %-30s %7.0f%% %7.1f %7.1f %9s %7.1f', 'STANDARD', 'VENDOR.MEDIUM', 1 / 3 * 100.0, 1.0, 0.0, number_format(300), 0.0) . "\n",
            $commandTester->getDisplay()
        );
        self::assertSame(['List', 'Other', 'Break', 'List', 'Other', 'Break'], $probeCommand->runnerServiceDouble->ranPrompts);
        self::assertSame(
            ['VENDOR.SMALL|persist=0|writesBlocked=0|keepSeed=0', 'VENDOR.SMALL|persist=0|writesBlocked=0|keepSeed=0', 'VENDOR.SMALL|persist=0|writesBlocked=0|keepSeed=0'],
            array_slice($probeCommand->runnerServiceDouble->runSettingsOfRuns, 0, 3)
        );
        self::assertSame('{"agentSpaceId":7}|-', $probeCommand->runnerServiceDouble->resolvedScopes[0], 'no follow-ups, purpose or other keys reach the run');
    }

    public function testACaseWithoutAScopeRunsOnTheSpaceOfTheScopeOption(): void
    {
        $probeCommand = $this->buildProbeCommand();

        $commandTester = $this->runProbeCommand($probeCommand, ['--tiers' => 'CHEAP', '--agentSpaceId' => '7']);

        self::assertStringStartsWith("Per-tier tool-eval — 4 case(s) × 1 tier(s) — LIVE\n", $commandTester->getDisplay());
        self::assertSame(['List', 'Other', 'Break', 'Nowhere'], $probeCommand->runnerServiceDouble->ranPrompts);
        self::assertSame('[]|7', $probeCommand->runnerServiceDouble->resolvedScopes[3]);
        self::assertStringNotContainsString('    FAIL ', $commandTester->getDisplay(), 'the failed cases are listed with --full only');
    }

    public function testNoCaseFails(): void
    {
        $commandTester = $this->runProbeCommand($this->buildProbeCommand(), ['--fixture' => $this->workDirectory . '/missing.json']);

        self::assertSame(Command::FAILURE, $commandTester->getStatusCode());
        self::assertSame("No tool-eval cases found\n", $commandTester->getDisplay());
        $probeCommand = $this->buildProbeCommand();
        self::assertInstanceOf(AgentEvalConsoleCommand::class, $probeCommand);
        self::assertSame(implode(',', ModelTier::getAll()), $probeCommand->getDefinition()->getOption('tiers')->getDefault());
        self::assertFalse($probeCommand->getDefinition()->hasOption('ignore-budget'), 'the tier run declares no budget option');
    }

    public function testATierWithoutAMeasuredRunPrintsZeros(): void
    {
        $agentEvalTiersResult = AgentEvalTiersResult::forTier('CHEAP', null);
        $agentEvalTiersResult->countFailedCase('a (error: x)');
        $agentEvalTiersResult->countFailedCase('a (error: x)');

        self::assertSame('  0/2 passed (0%) | avg 0.0 tool-calls (0.0 failed) | avg 0 tokens | avg 0.0s', $agentEvalTiersResult->buildResultLine());
        self::assertSame(['a (error: x)'], $agentEvalTiersResult->getDistinctFailureNotes());
        self::assertSame('(default)', $agentEvalTiersResult->getModelLabel());
    }

    /** @param array<string, mixed> $commandInput */
    protected function runProbeCommand(AgentEvalTiersProbeCommand $probeCommand, array $commandInput): CommandTester
    {
        $probeCommand->planServiceDouble->fixturePathsByName = ['tools' => $this->workDirectory];
        $probeCommand->modelsServiceDouble->modelNamesByTier = ['CHEAP' => 'VENDOR.SMALL', 'STANDARD' => 'VENDOR.MEDIUM'];
        $probeCommand->runnerServiceDouble->throwingPrompts = ['Break'];
        $commandTester = new CommandTester($probeCommand);
        $commandTester->execute($commandInput, ['capture_stderr_separately' => true]);
        return $commandTester;
    }

    protected function buildProbeCommand(): AgentEvalTiersProbeCommand
    {
        return new AgentEvalTiersProbeCommand();
    }
}
