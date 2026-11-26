<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `updateGoalStatus`: one goal by its `#<id>` and its new status. */
class AgentGoalUpdateStatusRequestDto extends RequestDto
{
    /** @var int The `#<id>` of the goal, from getGoals or the result of addGoal */
    #[Parameter(in: Parameter::BODY, required: true)]
    public int $goalId = 0;

    /** @var string ACTIVE (confirm or resume), ACHIEVED, DROPPED, PAUSED or BLOCKED (a goal the agent holds) */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $status = '';

    /** @var string|null One line: what was reached, why it was dropped, or what blocks it (required for BLOCKED) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $statusNote = null;

    /** @var bool|null BLOCKED only: only the owner can unblock it */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?bool $requiresUserAction = null;

    /** @var bool|null BLOCKED only: a passing failure, a later try may work */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?bool $retryable = null;

    /**
     * The status as the goal stores it: trimmed, upper case.
     *
     * @return string
     */
    public function toStatus(): string
    {
        return strtoupper(trim($this->status));
    }
}
