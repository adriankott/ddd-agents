<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\MCPPackages\ToolCallComparisons;

use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonCaseOutcome;

/** An application's case outcome whose stored documents carry another key for the requests of its own recorders. */
class MappedKeysToolCallComparisonCaseOutcome extends ToolCallComparisonCaseOutcome
{
    public const string HOST_REQUEST_LABEL = 'back-end request';

    public function getStoredKeyByField(): array
    {
        return ['hostRequests' => 'backEndRequests'] + parent::getStoredKeyByField();
    }
}
