<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Fixtures\ToolCallComparisons\Host\Domain;

/**
 * Fixture: the message the comparison fixture tool dispatches after it kept a snapshot — the ids of the two rows it
 * wrote. A comparison run captures it; nothing handles it.
 */
class ComparisonSnapshotKeptMessage
{
    public function __construct(
        public int $snapshotId,
        public int $traceEventId
    ) {
    }
}
