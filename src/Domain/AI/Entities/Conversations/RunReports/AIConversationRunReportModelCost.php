<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\RunReports;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The cost of a group of model calls of a run as a usage log books them: the dollars and the number of calls. The run
 * report carries the decision-model calls this way, apart from the agent model's cost on the message rows.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationRunReportModelCost extends ValueObject
{
    /** @var float The cost in US dollars */
    public float $costInUSD = 0.0;

    /** @var int The model calls */
    public int $calls = 0;

    /** @var string|null The class of the exception the read of the usage log ended in; null when the read worked or none was made */
    public ?string $readFailure = null;

    /**
     * @param float $costInUSD
     * @param int $calls
     * @return static
     */
    public static function forCostAndCalls(float $costInUSD, int $calls): static
    {
        $modelCost = static::newInstance();
        $modelCost->costInUSD = $costInUSD;
        $modelCost->calls = $calls;
        return $modelCost;
    }

    /**
     * No cost and no call: the cost of a run that has no usage log to read.
     *
     * @return static
     */
    public static function none(): static
    {
        return static::forCostAndCalls(0.0, 0);
    }

    /**
     * No cost and no call because the read of the usage log failed: the class of the exception is kept, so the report
     * says the cost was not read instead of showing a zero as if it were measured.
     *
     * @param string $readFailure the class of the exception the read ended in
     * @return static
     */
    public static function forReadFailure(string $readFailure): static
    {
        $modelCost = static::none();
        $modelCost->readFailure = $readFailure;
        return $modelCost;
    }
}
