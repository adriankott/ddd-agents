<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallImageSpoolService;
use DDD\Infrastructure\Cache\Cache;

/** The spool with its once-per-process report reset and reachable: two writes into the same cache in one process. */
class ProcessLocalCacheReportingSpoolServiceDouble extends AgentToolCallImageSpoolService
{
    public function reportTwoWritesInto(Cache $spoolCache): void
    {
        static::$processLocalCacheReported = false;
        $this->warnOnceWhenTheSpoolCacheIsProcessLocal($spoolCache);
        $this->warnOnceWhenTheSpoolCacheIsProcessLocal($spoolCache);
    }
}
