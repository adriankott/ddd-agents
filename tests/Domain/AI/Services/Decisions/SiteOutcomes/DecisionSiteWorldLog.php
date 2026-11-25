<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

/**
 * The ordered record of what a decision site did: every double of the site-outcome tests appends one line per call — a
 * request sent to the decision model (its label and the mode it was answered in), a breaker trip or close request, a
 * working-state slice write, a decision card, a turn event, a site-service call of the turn start. A row of an outcome
 * table is the compact form of these lines ({@see DecisionSiteOutcomeTestCase::summarizeWorldLog()}).
 */
class DecisionSiteWorldLog
{
    /** @var string[] the lines, in call order */
    public array $lines = [];

    public function record(string $line): void
    {
        $this->lines[] = $line;
    }
}
