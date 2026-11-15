<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\MCPPackages;

use DDD\Domain\AI\Entities\MCPPackages\ToolDefinition;
use DDD\Tests\Agents\Domain\AI\Entities\MCPPackages\Probes\ProbeMCPSchemaWithFixedWireArray;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * An `@example {}` of a tool's argument set is an empty object on the wire (`"examples":[{}]`), never an empty list.
 */
class ToolDefinitionEmptyObjectExampleTest extends UnitTestCase
{
    public function testAnEmptyExampleOfTheArgumentSetIsSentAsAnEmptyObject(): void
    {
        $inputSchema = new ProbeMCPSchemaWithFixedWireArray();
        $inputSchema->fixedWireArray = ['type' => 'object', 'properties' => [], 'examples' => [[], ['top' => 5]]];
        $toolDefinition = new ToolDefinition();
        $toolDefinition->name = 'probeTool';
        $toolDefinition->description = 'Reads a probe.';
        $toolDefinition->inputSchema = $inputSchema;

        $wireJson = (string)json_encode($toolDefinition->toOpenAiToolSchema()['function']['parameters']);

        self::assertStringContainsString('"examples":[{},{"top":5}]', $wireJson);
        self::assertStringContainsString('"properties":{}', $wireJson);
    }
}
