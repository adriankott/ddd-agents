<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Admission;

use DDD\Domain\AI\Entities\ModelCalls\AIModelCall;
use DDD\Domain\AI\Services\AISessionUsageService;
use DDD\Domain\Common\Entities\Money\MoneyAmount;

/** The session pricing of ddd-ai answering {@see $costsOfTheSeconds} and recording the seconds it was asked to price. */
class RecordingAISessionUsageService extends AISessionUsageService
{
    public ?MoneyAmount $costsOfTheSeconds = null;

    /** @var float[] */
    public array $pricedSeconds = [];

    public function calculateCostsOfSessionSeconds(AIModelCall $aiModelCall, float $sessionSeconds, ?MoneyAmount $costsPerSessionSecond = null): ?MoneyAmount
    {
        $this->pricedSeconds[] = $sessionSeconds;

        return $this->costsOfTheSeconds;
    }
}
