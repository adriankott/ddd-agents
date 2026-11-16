<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\MCPPackages\ToolCallComparisons;

use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonDefinitionsDump;

/** An application's definitions dump whose stored documents carry other keys for the loop schemas of a tool. */
class MappedKeysToolCallComparisonDefinitionsDump extends ToolCallComparisonDefinitionsDump
{
    public function getStoredKeyByField(): array
    {
        return [
            'loopSchemaAgentSpaceBound' => 'loopSchemaSpaceBound',
            'loopSchemaAgentSpaceLess' => 'loopSchemaSpaceLess',
            'loopSchemaAgentSpaceBoundPhoneLine' => 'loopSchemaSpaceBoundPhoneLine',
        ] + parent::getStoredKeyByField();
    }
}
