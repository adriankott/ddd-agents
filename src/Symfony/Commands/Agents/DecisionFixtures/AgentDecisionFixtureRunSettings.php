<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\DecisionFixtures;

use DDD\Domain\Base\Entities\ValueObject;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * How one decision-fixtures run sends its cases: how often each case is sent, whether the state of a case is printed,
 * whether the reply-language cases go without their context, and the decision timeout of a call.
 *
 * A plain object of one command run; no container service.
 *
 * @phpstan-consistent-constructor
 */
#[Exclude]
class AgentDecisionFixtureRunSettings extends ValueObject
{
    /** @var int How often each case is sent (at least once) */
    public int $repeat = 1;

    /** @var bool Whether the state sent for a case is printed (once, before its first call) */
    public bool $showState = false;

    /** @var bool Whether the reply-language cases are sent with the context-free question */
    public bool $withoutContext = false;

    /** @var float The decision timeout of one call, in seconds */
    public float $timeoutSeconds = 0.0;

    /**
     * The settings of a run.
     *
     * @param int $repeat sent this many times; below 1 counts as 1
     * @param bool $showState
     * @param bool $withoutContext
     * @param float $timeoutSeconds
     * @return static
     */
    public static function forRun(int $repeat, bool $showState, bool $withoutContext, float $timeoutSeconds): static
    {
        $agentDecisionFixtureRunSettings = static::newInstance();
        $agentDecisionFixtureRunSettings->repeat = max(1, $repeat);
        $agentDecisionFixtureRunSettings->showState = $showState;
        $agentDecisionFixtureRunSettings->withoutContext = $withoutContext;
        $agentDecisionFixtureRunSettings->timeoutSeconds = $timeoutSeconds;
        return $agentDecisionFixtureRunSettings;
    }
}
