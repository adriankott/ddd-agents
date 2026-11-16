<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\DecisionFixtures;

use DDD\Domain\Base\Entities\ValueObject;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The score of one decision-fixtures run: one table row per decision call, the hits and the call latencies, and the
 * score lines the console prints under the table.
 *
 * A plain object of one command run; no container service.
 *
 * @phpstan-consistent-constructor
 */
#[Exclude]
class AgentDecisionFixtureScore extends ValueObject
{
    /** @var int The calls with an expectation (sub-skill pass) or every answered call (reply language) */
    public int $scoredCount = 0;

    /** @var int The sub-skill picks that hit the expected one */
    public int $pickHitCount = 0;

    /** @var int The written-language answers that hit */
    public int $writtenHitCount = 0;

    /** @var int The requested-language answers that hit */
    public int $requestedHitCount = 0;

    /** @var int The resolved replies that hit */
    public int $replyHitCount = 0;

    /** @var int[] The latency of every answered call, in milliseconds, in call order */
    public array $latenciesMs = [];

    /** @var AgentDecisionFixtureTableRows The table rows, one per decision call, in call order */
    public AgentDecisionFixtureTableRows $tableRows;

    public function __construct()
    {
        parent::__construct();
        $this->tableRows = AgentDecisionFixtureTableRows::newInstance();
    }

    /**
     * Adds a table row.
     *
     * @param string ...$cells
     * @return void
     */
    public function addTableRow(string ...$cells): void
    {
        $agentDecisionFixtureTableRow = AgentDecisionFixtureTableRow::forCells(...$cells);
        $this->tableRows->add($agentDecisionFixtureTableRow);
    }

    /** The rows added so far. */
    public function countTableRows(): int
    {
        return $this->tableRows->count();
    }

    /**
     * Prints the table of every row under `$headers`.
     *
     * @param SymfonyStyle $symfonyStyle
     * @param string[] $headers
     * @return void
     */
    public function renderTable(SymfonyStyle $symfonyStyle, array $headers): void
    {
        $this->tableRows->renderTable($symfonyStyle, $headers);
    }

    /** Counts a sub-skill pick with an expectation: a hit when `$hit`. */
    public function recordScoredPick(bool $hit): void
    {
        $this->scoredCount++;
        $this->pickHitCount += $hit ? 1 : 0;
    }

    /** Counts an answered reply-language call and its three hits. */
    public function recordReplyLanguageAnswer(bool $writtenHit, bool $requestedHit, bool $replyHit): void
    {
        $this->scoredCount++;
        $this->writtenHitCount += $writtenHit ? 1 : 0;
        $this->requestedHitCount += $requestedHit ? 1 : 0;
        $this->replyHitCount += $replyHit ? 1 : 0;
    }

    /** Records the latency of an answered call. */
    public function recordLatencyMs(int $latencyMs): void
    {
        $this->latenciesMs[] = $latencyMs;
    }

    /**
     * The median of the recorded latencies: the middle value of the sorted latencies, the upper one of the two middle
     * values for an even count; 0 when no call was answered.
     */
    public function getMedianLatencyMs(): int
    {
        if ($this->latenciesMs === []) {
            return 0;
        }
        $sortedLatenciesMs = $this->latenciesMs;
        sort($sortedLatenciesMs);
        return (int)$sortedLatenciesMs[(int)floor(count($sortedLatenciesMs) / 2)];
    }

    /** `$hitCount` of the scored calls as a whole percent (`83 %`), or `n/a` when nothing was scored. */
    public function buildHitPercentText(int $hitCount): string
    {
        return $this->scoredCount > 0 ? number_format(100 * $hitCount / $this->scoredCount, 0) . ' %' : 'n/a';
    }

    /** The score line of a sub-skill pass run. */
    public function buildPickScoreLine(): string
    {
        return sprintf('Score: %d / %d expected picks hit (%s).', $this->pickHitCount, $this->scoredCount, $this->buildHitPercentText($this->pickHitCount));
    }

    /** The score line of a reply-language run under the arm `$armLabel`. */
    public function buildReplyLanguageScoreLine(string $armLabel): string
    {
        return sprintf(
            'Arm: %s · written %d / %d · requested %d / %d · REPLY %d / %d (%s) · median latency %d ms',
            $armLabel,
            $this->writtenHitCount,
            $this->scoredCount,
            $this->requestedHitCount,
            $this->scoredCount,
            $this->replyHitCount,
            $this->scoredCount,
            $this->buildHitPercentText($this->replyHitCount),
            $this->getMedianLatencyMs()
        );
    }
}
