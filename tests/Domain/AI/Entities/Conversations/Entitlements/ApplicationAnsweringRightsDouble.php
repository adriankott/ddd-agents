<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Entitlements;

use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRightsAnsweringInterface;

/** An application's rights object that answers some package rights itself, a "not held" included. */
class ApplicationAnsweringRightsDouble extends ApplicationKnownRightsDouble implements AgentAccountRightsAnsweringInterface
{
    /**
     * @param string[] $heldRightNames
     * @param string[] $answeredRightNames
     */
    public function __construct(array $heldRightNames = [], public array $answeredRightNames = [])
    {
        parent::__construct($heldRightNames);
    }

    public function answersRightName(string $rightName): bool
    {
        return in_array($rightName, $this->answeredRightNames, true);
    }
}
