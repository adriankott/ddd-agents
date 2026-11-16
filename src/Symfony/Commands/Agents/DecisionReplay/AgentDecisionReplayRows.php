<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\DecisionReplay;

use DDD\Domain\Base\Entities\ObjectSet;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The replayed decisions of one run ({@see AgentDecisionReplayRow}), in the order they were replayed.
 *
 * A plain object of one command run; no container service.
 *
 * @property AgentDecisionReplayRow[] $elements
 * @method AgentDecisionReplayRow[] getElements()
 * @method AgentDecisionReplayRow|null first()
 * @phpstan-consistent-constructor
 */
#[Exclude]
class AgentDecisionReplayRows extends ObjectSet
{
    /**
     * The stored document of the result file: one row object per replayed decision, in order.
     *
     * @return array<int, array<string, string|int|float|bool|null>>
     */
    public function toArray(): array
    {
        $storedRows = [];
        foreach ($this->getElements() as $agentDecisionReplayRow) {
            $storedRows[] = $agentDecisionReplayRow->toArray();
        }
        return $storedRows;
    }

    /** The result file as JSON: the rows of {@see toArray()}, pretty-printed, unescaped Unicode. */
    public function buildResultFileJson(): string
    {
        return (string)json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Prints the console table of every row ({@see AgentDecisionReplayRow::buildTableCells()}) under `$headers`.
     *
     * @param SymfonyStyle $symfonyStyle
     * @param string[] $headers
     * @return void
     */
    public function renderTable(SymfonyStyle $symfonyStyle, array $headers): void
    {
        $tableRows = [];
        foreach ($this->getElements() as $agentDecisionReplayRow) {
            $tableRows[] = $agentDecisionReplayRow->buildTableCells();
        }
        $symfonyStyle->table($headers, $tableRows);
    }

    /**
     * The table headers of the default cells: the field names of the first row; none for an empty set.
     *
     * @return string[]
     */
    public function buildFieldNameHeaders(): array
    {
        return $this->first()?->getFieldNames() ?? [];
    }
}
