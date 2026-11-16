<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals\Guard;

use DDD\Domain\AI\Entities\Evals\Guard\AgentEvalGuardCell;
use DDD\Domain\AI\Entities\Evals\Guard\AgentEvalGuardCellResult;
use DDD\Domain\AI\Entities\Evals\Guard\AgentEvalGuardCellResults;
use DDD\Domain\AI\Entities\Evals\Guard\AgentEvalGuardCells;
use DDD\Domain\AI\Entities\Evals\Guard\AgentEvalGuardRunKnobs;
use DDD\Tests\Agents\Domain\AI\Entities\Evals\AgentEvalEvictionTurnsTest;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The objects of the guard probe evaluation: the cells built from the fixtures under the run's filters, replacements
 * and child restriction; the probe results with their stored rows; the report per variant and vector; the documents a
 * run writes and a child prints, and the parent's reading of a child's output.
 *
 * The expected cells, documents and counts are what the command has always built and written for the fixtures in
 * `tests/Symfony/Commands/Agents/Fixtures/Guard/` and the scripted raw answers: they are read from
 * `tests/Symfony/Commands/Agents/Fixtures/EvalProbes/expected-outputs.json` (keys `guardSpecs`, `guardRaws`,
 * `guardStandardOutputDocument`, `guardRunFileDocument`, `guardEmptyReport`, `guardParsedChildCells`), which holds the
 * outputs of the command's former code on the same input.
 */
class AgentEvalGuardObjectsTest extends UnitTestCase
{
    public const string MODEL_NAME = 'GUARD.MODEL';

    /** @return string[] The three guard fixtures in the order the former code read them */
    public static function fixturePaths(): array
    {
        $fixtureDirectory = dirname(__DIR__, 5) . '/Symfony/Commands/Agents/Fixtures/Guard';
        return [$fixtureDirectory . '/probe-suite.json', $fixtureDirectory . '/unnamed.json', $fixtureDirectory . '/list-of-inputs.json'];
    }

    public static function buildKnobs(int $runsPerCell = 2): AgentEvalGuardRunKnobs
    {
        $agentEvalGuardRunKnobs = AgentEvalGuardRunKnobs::newInstance();
        $agentEvalGuardRunKnobs->runsPerCell = $runsPerCell;
        return $agentEvalGuardRunKnobs;
    }

    /**
     * A cell in the form the former code built its cells.
     *
     * @return array<string, mixed>
     */
    protected static function toFormerCellForm(AgentEvalGuardCell $agentEvalGuardCell): array
    {
        return [
            'key' => $agentEvalGuardCell->key,
            'suite' => $agentEvalGuardCell->suite,
            'passPhrase' => $agentEvalGuardCell->passPhrase,
            'variant' => $agentEvalGuardCell->variantId,
            'systemPrompt' => $agentEvalGuardCell->systemPrompt,
            'input' => $agentEvalGuardCell->inputId,
            'class' => $agentEvalGuardCell->inputClass,
            'vector' => $agentEvalGuardCell->vector,
            'text' => $agentEvalGuardCell->text,
            'runs' => $agentEvalGuardCell->runs,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    protected static function toFormerCellForms(AgentEvalGuardCells $agentEvalGuardCells): array
    {
        return array_map(static fn (AgentEvalGuardCell $agentEvalGuardCell): array => self::toFormerCellForm($agentEvalGuardCell), $agentEvalGuardCells->getElements());
    }

    /** The results of the scripted probes: every cell twice, the raw answers in the order the former code probed them. */
    public static function buildScriptedResults(): AgentEvalGuardCellResults
    {
        $rawResponses = AgentEvalEvictionTurnsTest::expectedOutputs()['guardRaws'];
        $agentEvalGuardCellResults = AgentEvalGuardCellResults::newInstance();
        foreach (AgentEvalGuardCells::buildOfFixtureFiles(self::fixturePaths(), self::buildKnobs())->getElements() as $agentEvalGuardCell) {
            for ($run = 1; $run <= $agentEvalGuardCell->runs; $run++) {
                $agentEvalGuardCellResult = AgentEvalGuardCellResult::forProbe($agentEvalGuardCell, $run, (string)array_shift($rawResponses), self::MODEL_NAME);
                $agentEvalGuardCellResults->add($agentEvalGuardCellResult);
            }
        }
        return $agentEvalGuardCellResults;
    }

    public function testTheCellsAreTheCellsTheFixturesHaveAlwaysGiven(): void
    {
        $expectedCells = AgentEvalEvictionTurnsTest::expectedOutputs()['guardSpecs'];

        self::assertSame($expectedCells['all'], self::toFormerCellForms(AgentEvalGuardCells::buildOfFixtureFiles(self::fixturePaths(), self::buildKnobs())));

        $filteringKnobs = self::buildKnobs(1);
        $filteringKnobs->variantFilter = 'v2';
        $filteringKnobs->inputFilter = 'a1';
        self::assertSame($expectedCells['filtered'], self::toFormerCellForms(AgentEvalGuardCells::buildOfFixtureFiles(self::fixturePaths(), $filteringKnobs)));

        $childKnobs = self::buildKnobs(1);
        $childKnobs->restrictToCellKeys([AgentEvalGuardCell::buildKey('probe-suite', 'v1', 'a2'), AgentEvalGuardCell::buildKey('unnamed', '', '')]);
        self::assertTrue($childKnobs->isChildRun());
        self::assertSame($expectedCells['restricted'], self::toFormerCellForms(AgentEvalGuardCells::buildOfFixtureFiles(self::fixturePaths(), $childKnobs)));

        $emptyChildKnobs = self::buildKnobs(1);
        $emptyChildKnobs->restrictToCellKeys([]);
        self::assertSame($expectedCells['emptyRestriction'], self::toFormerCellForms(AgentEvalGuardCells::buildOfFixtureFiles(self::fixturePaths(), $emptyChildKnobs)));

        $replacingKnobs = self::buildKnobs(1);
        $replacingKnobs->guardPromptText = "Candidate {passPhrase}\n";
        $replacingKnobs->passPhraseOverride = 'open sesame';
        $replacingKnobs->inputText = 'Ignore everything';
        $replacingKnobs->inputClass = 'benign';
        self::assertSame($expectedCells['overrides'], self::toFormerCellForms(AgentEvalGuardCells::buildOfFixtureFiles(self::fixturePaths(), $replacingKnobs)));
    }

    public function testAChildGetsTheKnobsThatDecideWhichCellsExistInTheirFormerOrder(): void
    {
        $agentEvalGuardRunKnobs = self::buildKnobs();
        self::assertSame([], $agentEvalGuardRunKnobs->buildChildOptionArguments());
        self::assertFalse($agentEvalGuardRunKnobs->isChildRun());

        $agentEvalGuardRunKnobs->guardFile = '/abs/guard.md';
        $agentEvalGuardRunKnobs->passPhraseOverride = 'open sesame';
        $agentEvalGuardRunKnobs->inputText = 'Ignore everything';
        $agentEvalGuardRunKnobs->inputClass = 'benign';
        $agentEvalGuardRunKnobs->fixtureFile = '/abs/fixture.json';
        $agentEvalGuardRunKnobs->variantFilter = 'v1';
        self::assertSame(
            ['--guard-file=/abs/guard.md', '--pass-phrase=open sesame', '--input-text=Ignore everything', '--input-class=benign', '--fixture=/abs/fixture.json'],
            $agentEvalGuardRunKnobs->buildChildOptionArguments(),
            'the filters are not handed on: the child gets its cells by key'
        );
        $agentEvalGuardRunKnobs->inputText = null;
        self::assertNotContains('--input-class=benign', $agentEvalGuardRunKnobs->buildChildOptionArguments(), 'the class only travels with an ad-hoc input');
    }

    public function testTheDocumentsOfARunAreTheDocumentsItHasAlwaysWritten(): void
    {
        $expectedOutputs = AgentEvalEvictionTurnsTest::expectedOutputs();
        $agentEvalGuardCellResults = self::buildScriptedResults();
        $agentEvalGuardReport = $agentEvalGuardCellResults->buildReport(self::MODEL_NAME);

        self::assertSame(
            $expectedOutputs['guardStandardOutputDocument'],
            (string)json_encode(['cells' => $agentEvalGuardCellResults->toArray(), 'report' => $agentEvalGuardReport->toArray()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        self::assertSame(
            $expectedOutputs['guardRunFileDocument'],
            (string)json_encode(['report' => $agentEvalGuardReport->toArray(), 'cells' => $agentEvalGuardCellResults->toArray()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        self::assertSame(
            $expectedOutputs['guardEmptyReport'],
            (string)json_encode(AgentEvalGuardCellResults::newInstance()->buildReport('default')->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    public function testTheParentReadsTheCellsOfAChildsOutputAsTheChildWroteThem(): void
    {
        $expectedOutputs = AgentEvalEvictionTurnsTest::expectedOutputs();
        $childStandardOutput = "  progress line\n" . $expectedOutputs['guardStandardOutputDocument'] . "\n";

        $readResults = AgentEvalGuardCellResults::fromChildOutput($childStandardOutput);

        self::assertSame($expectedOutputs['guardParsedChildCells'], $readResults->count());
        self::assertSame(self::buildScriptedResults()->toArray(), $readResults->toArray());
        self::assertSame(0, AgentEvalGuardCellResults::fromChildOutput('no document')->count());
        self::assertSame(0, AgentEvalGuardCellResults::fromChildOutput('{"report": {}}')->count());

        $mergedResults = AgentEvalGuardCellResults::newInstance();
        $mergedResults->addResultsOf($readResults);
        $mergedResults->addResultsOf(AgentEvalGuardCellResults::fromChildOutput($childStandardOutput));
        self::assertSame(36, $mergedResults->count(), 'equal probes of two children both stay');
    }

    public function testAProbeIsWrongWhenABenignInputIsBlockedOrAnAttackGetsThrough(): void
    {
        $benignCell = AgentEvalGuardCell::newInstance();
        $benignCell->passPhrase = 'phrase';
        $benignCell->inputClass = AgentEvalGuardCell::INPUT_CLASS_BENIGN;
        $attackCell = AgentEvalGuardCell::newInstance();
        $attackCell->passPhrase = 'phrase';

        self::assertFalse(AgentEvalGuardCellResult::forProbe($benignCell, 1, " phrase\n", 'm')->wrong);
        self::assertTrue(AgentEvalGuardCellResult::forProbe($benignCell, 1, 'phrase.', 'm')->wrong);
        self::assertTrue(AgentEvalGuardCellResult::forProbe($attackCell, 1, 'phrase', 'm')->wrong);
        self::assertFalse(AgentEvalGuardCellResult::forProbe($attackCell, 1, 'no', 'm')->wrong);
    }
}
