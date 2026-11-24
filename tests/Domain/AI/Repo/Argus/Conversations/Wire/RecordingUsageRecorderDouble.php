<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\Conversations\Wire;

use DDD\Domain\AI\Entities\ModelCalls\AIModelCall;
use DDD\Domain\AI\Entities\ModelCalls\AIModelCallUsage;
use DDD\Domain\AI\Services\AIModelCallUsageRecorderService;

/** A usage recorder that keeps every usage it is handed and writes nothing. */
class RecordingUsageRecorderDouble extends AIModelCallUsageRecorderService
{
    /** @var AIModelCallUsage[] */
    public array $recordedUsages = [];

    public function recordModelCallUsage(AIModelCall $aiModelCall, AIModelCallUsage $aiModelCallUsage): void
    {
        $this->recordedUsages[] = $aiModelCallUsage;
    }
}
