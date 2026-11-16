<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\MCPPackages\ToolCallComparisons;

use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonRecording;

/** An application's recording whose stored documents carry other keys for its agent space and its own recorders. */
class MappedKeysToolCallComparisonRecording extends ToolCallComparisonRecording
{
    public function getStoredKeyByField(): array
    {
        return ['comparisonAgentSpaceId' => 'spaceIdOfTheRun', 'hostRecordedCalls' => 'backEndCalls'] + parent::getStoredKeyByField();
    }
}
