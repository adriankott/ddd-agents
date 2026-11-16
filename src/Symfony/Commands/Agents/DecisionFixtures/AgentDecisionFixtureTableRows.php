<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\DecisionFixtures;

use DDD\Domain\Base\Entities\ObjectSet;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The printed rows of a decision-fixtures table ({@see AgentDecisionFixtureTableRow}), in call order. Each row is held
 * under the key of its own object: two rows with the same cells stay two rows.
 *
 * A plain object of one command run; no container service.
 *
 * @property AgentDecisionFixtureTableRow[] $elements
 * @method AgentDecisionFixtureTableRow[] getElements()
 * @method AgentDecisionFixtureTableRow|null first()
 * @method AgentDecisionFixtureTableRow|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
#[Exclude]
class AgentDecisionFixtureTableRows extends ObjectSet
{
    /**
     * Prints the table of every row under `$headers`.
     *
     * @param SymfonyStyle $symfonyStyle
     * @param string[] $headers
     * @return void
     */
    public function renderTable(SymfonyStyle $symfonyStyle, array $headers): void
    {
        $printedRows = [];
        foreach ($this->getElements() as $agentDecisionFixtureTableRow) {
            $printedRows[] = $agentDecisionFixtureTableRow->cells;
        }
        $symfonyStyle->table($headers, $printedRows);
    }
}
