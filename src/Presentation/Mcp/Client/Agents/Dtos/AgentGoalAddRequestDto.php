<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Domain\AI\Entities\Conversations\Goals\AgentGoal;
use DDD\Infrastructure\Base\DateTime\Date;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Input of `addGoal`: one goal as one sentence of the state the owner wants. It belongs to the space of the call (the
 * conversation's, or the one `agentSpaceId` names), else to the account.
 */
class AgentGoalAddRequestDto extends RequestDto
{
    /** @var string[] The horizon as a model may write it => the stored horizon */
    public const array HORIZON_LEVELS_BY_SPELLING = [
        'VISION' => AgentGoal::HORIZON_VISION,
        'LONG_TERM' => AgentGoal::HORIZON_LONG_TERM,
        'LONGTERM' => AgentGoal::HORIZON_LONG_TERM,
        'SHORT_TERM' => AgentGoal::HORIZON_SHORT_TERM,
        'SHORTTERM' => AgentGoal::HORIZON_SHORT_TERM,
    ];

    /** @var string The goal as one neutral sentence of the state wanted, in the owner's meaning */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $statement = '';

    /** @var string|null VISION (a direction, never completes), LONG_TERM or SHORT_TERM; only when the owner's words carry one, default LONG_TERM */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $horizonLevel = null;

    /** @var string|null Why it matters to the owner */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $reason = null;

    /** @var string|null How one would notice it worked, in one line */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $successSignal = null;

    /** @var string|null The day it should be reached by, YYYY-MM-DD; only when the owner named one */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $targetDate = null;

    /** @var int|null The attention weight, higher first; default 0 */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $priority = null;

    /** @var int|null The `#<id>` of the goal this one serves or breaks down */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $parentGoalId = null;

    /** @var bool|null true (default): the owner stated it; false: your own proposal, stored as PROPOSED until the owner confirms */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?bool $statedByOwner = null;

    /** @var string|null The key of the standard goal tree's node this goal adopts (from getGoalBlueprint) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $blueprintKey = null;

    /** @var int|null The space the goal belongs to; omit it for the space this conversation works for */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $agentSpaceId = null;

    /**
     * The horizon as the goal stores it; null when none is given. Upper or lower case, a blank or a hyphen for the
     * underscore are read alike.
     *
     * @return string|null
     * @throws BadRequestException for a horizon that does not exist
     */
    public function toHorizonLevel(): ?string
    {
        if ($this->horizonLevel === null || trim($this->horizonLevel) === '') {
            return null;
        }
        $spelling = strtoupper(str_replace([' ', '-'], '_', trim($this->horizonLevel)));
        if (!isset(self::HORIZON_LEVELS_BY_SPELLING[$spelling])) {
            throw new BadRequestException('horizonLevel is one of ' . implode(', ', AgentGoal::HORIZONS) . '.');
        }
        return self::HORIZON_LEVELS_BY_SPELLING[$spelling];
    }

    /**
     * The target day; null when none is given.
     *
     * @return Date|null
     * @throws BadRequestException when it is no calendar day in the form YYYY-MM-DD
     */
    public function toTargetDate(): ?Date
    {
        if ($this->targetDate === null || trim($this->targetDate) === '') {
            return null;
        }
        $targetDay = trim($this->targetDate);
        $dayParts = [];
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $targetDay, $dayParts) !== 1
            || !checkdate((int)$dayParts[2], (int)$dayParts[3], (int)$dayParts[1])
        ) {
            throw new BadRequestException('targetDate is a day like 2027-03-31.');
        }
        $targetDate = Date::fromString($targetDay);
        if (!$targetDate instanceof Date) {
            throw new BadRequestException('targetDate is a day like 2027-03-31.');
        }
        return $targetDate;
    }

    /**
     * The blueprint key, trimmed; null when none is given.
     *
     * @return string|null
     * @throws BadRequestException for a key longer than a node key can be
     */
    public function toBlueprintKey(): ?string
    {
        if ($this->blueprintKey === null || trim($this->blueprintKey) === '') {
            return null;
        }
        $blueprintKey = trim($this->blueprintKey);
        if (mb_strlen($blueprintKey) > 64) {
            throw new BadRequestException('blueprintKey is the key of a node of getGoalBlueprint.');
        }
        return $blueprintKey;
    }

    /**
     * Whether the owner stated the goal (the default) or it is the agent's proposal.
     *
     * @return bool
     */
    public function isStatedByOwner(): bool
    {
        return $this->statedByOwner ?? true;
    }
}
