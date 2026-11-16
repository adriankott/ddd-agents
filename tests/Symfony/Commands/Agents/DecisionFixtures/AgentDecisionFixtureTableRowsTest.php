<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\DecisionFixtures;

use DDD\Symfony\Commands\Agents\DecisionFixtures\AgentDecisionFixtureScore;
use DDD\Tests\Agents\Support\UnitTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The table rows of a decision-fixtures run are row objects in call order: two calls with the same cells are two
 * rows, and the printed table is the one the plain cell lists printed.
 */
class AgentDecisionFixtureTableRowsTest extends UnitTestCase
{
    public function testTheRowsArePrintedInCallOrderUnderTheHeaders(): void
    {
        $agentDecisionFixtureScore = AgentDecisionFixtureScore::newInstance();
        $agentDecisionFixtureScore->addTableRow('case-1', 'skill.a (0.91)', 'skill.a', '✓', 'skill.a · 120 ms');
        $agentDecisionFixtureScore->addTableRow('case-1', 'skill.a (0.91)', 'skill.a', '✓', 'skill.a · 120 ms');
        $agentDecisionFixtureScore->addTableRow('case-2', 'ERROR timeout', '', '', '');

        $printedOutput = new BufferedOutput();
        $agentDecisionFixtureScore->renderTable(new SymfonyStyle(new ArrayInput([]), $printedOutput), ['case', 'picked (conf)', 'expected', '', 'top-3 · latency']);
        $expectedOutput = new BufferedOutput();
        (new SymfonyStyle(new ArrayInput([]), $expectedOutput))->table(['case', 'picked (conf)', 'expected', '', 'top-3 · latency'], [
            ['case-1', 'skill.a (0.91)', 'skill.a', '✓', 'skill.a · 120 ms'],
            ['case-1', 'skill.a (0.91)', 'skill.a', '✓', 'skill.a · 120 ms'],
            ['case-2', 'ERROR timeout', '', '', ''],
        ]);

        self::assertSame(3, $agentDecisionFixtureScore->countTableRows(), 'two equal calls stay two rows');
        self::assertSame(['case-2', 'ERROR timeout', '', '', ''], $agentDecisionFixtureScore->tableRows->getElements()[2]->cells);
        self::assertSame($expectedOutput->fetch(), $printedOutput->fetch());
    }
}
