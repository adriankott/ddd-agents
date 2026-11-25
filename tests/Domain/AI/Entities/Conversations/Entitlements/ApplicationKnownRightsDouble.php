<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Entitlements;

use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRightsInterface;

/** An application's rights object that knows only its own right names: every other name is "not held". */
class ApplicationKnownRightsDouble implements AgentAccountRightsInterface
{
    /** @param string[] $heldRightNames */
    public function __construct(public array $heldRightNames = [])
    {
    }

    public function getMissingRightNames(array $rightNames): array
    {
        return array_values(array_diff(array_map('strval', $rightNames), $this->heldRightNames));
    }
}
