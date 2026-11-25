<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Admission;

use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRightsInterface;

/** Account rights that hold exactly {@see $heldRightNames}. */
class FixedAgentAccountRights implements AgentAccountRightsInterface
{
    /** @param string[] $heldRightNames */
    public function __construct(public array $heldRightNames)
    {
    }

    public function getMissingRightNames(array $rightNames): array
    {
        return array_values(array_diff($rightNames, $this->heldRightNames));
    }
}
