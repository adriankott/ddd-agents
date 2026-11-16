<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\DecisionFixtures;

use DDD\Domain\Base\Entities\ValueObject;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One printed row of a decision-fixtures table: the cells of one decision call, in the order of the table's headers.
 *
 * A plain object of one command run; no container service.
 *
 * @phpstan-consistent-constructor
 */
#[Exclude]
class AgentDecisionFixtureTableRow extends ValueObject
{
    /** @var string[] The cells of the row, in the order of the headers */
    public array $cells = [];

    /** The row of these cells. */
    public static function forCells(string ...$cells): static
    {
        $agentDecisionFixtureTableRow = static::newInstance();
        $agentDecisionFixtureTableRow->cells = array_values($cells);
        return $agentDecisionFixtureTableRow;
    }
}
