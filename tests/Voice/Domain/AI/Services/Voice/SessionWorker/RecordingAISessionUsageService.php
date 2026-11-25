<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

use DDD\Domain\AI\Entities\ModelCalls\AIModelCall;
use DDD\Domain\AI\Entities\ModelCalls\AIModelCallUsage;
use DDD\Domain\AI\Services\AISessionUsageService;
use DDD\Domain\Common\Entities\Money\MoneyAmount;

/** The usage recorder of a session: every booking is kept with the model call it was booked under, nothing is priced. */
class RecordingAISessionUsageService extends AISessionUsageService
{
    /** @var array<int, array{modelCall: AIModelCall, seconds: float}> */
    public array $recordedSessionSeconds = [];

    public function recordSessionSeconds(AIModelCall $aiModelCall, float $sessionSeconds, ?MoneyAmount $costsPerSessionSecond = null): AIModelCallUsage
    {
        $this->recordedSessionSeconds[] = ['modelCall' => $aiModelCall, 'seconds' => $sessionSeconds];

        return new AIModelCallUsage(costs: null, sessionSeconds: $sessionSeconds);
    }

    /** @return float[] The seconds of every booking, in order */
    public function getRecordedSeconds(): array
    {
        return array_column($this->recordedSessionSeconds, 'seconds');
    }
}
