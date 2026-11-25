<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\DispatchTable;

use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;

/**
 * A purpose whose answers are given by the test: whether it seeds its task plan, the progress-step key of its closing
 * act, and the tool whose successful result ends it.
 */
class DispatchTablePurposeDouble extends AIConversationPurpose
{
    public bool $seedsItsTaskPlan = false;

    public ?string $closingProgressStepKey = null;

    /** The tool whose successful result ends the purpose; null = none */
    public ?string $purposeEndingToolName = null;

    public function seedsTaskPlan(): bool
    {
        return $this->seedsItsTaskPlan;
    }

    public function getClosingProgressStepKey(): ?string
    {
        return $this->closingProgressStepKey;
    }

    public function doesToolResultEndThePurpose(string $toolName, string $resultContent): bool
    {
        return $this->purposeEndingToolName !== null && $toolName === $this->purposeEndingToolName;
    }
}
