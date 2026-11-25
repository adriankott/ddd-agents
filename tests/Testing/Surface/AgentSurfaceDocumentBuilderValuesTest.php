<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Testing\Surface;

use DDD\Testing\Agents\Surface\AgentSurfaceDocumentBuilder;
use PHPUnit\Framework\TestCase;

/**
 * How the surface document holds a value: short values plain, long texts and larger structures as a hash token, small
 * maps as maps, empty fields left out. These rules need no container.
 */
class AgentSurfaceDocumentBuilderValuesTest extends TestCase
{
    public function testShortValuesStayAsTheyAre(): void
    {
        $agentSurfaceDocumentBuilder = new AgentSurfaceDocumentBuilder();

        self::assertSame('Agent.Tasks', $agentSurfaceDocumentBuilder->condenseValue('Agent.Tasks'));
        self::assertSame(42, $agentSurfaceDocumentBuilder->condenseValue(42));
        self::assertTrue($agentSurfaceDocumentBuilder->condenseValue(true));
        self::assertSame(['getTasks', 'endTask'], $agentSurfaceDocumentBuilder->condenseValue(['getTasks', 'endTask']));
    }

    public function testALongTextBecomesItsHashTokenWithItsLength(): void
    {
        $agentSurfaceDocumentBuilder = new AgentSurfaceDocumentBuilder();
        $longText = str_repeat('a', 300);

        $hashToken = $agentSurfaceDocumentBuilder->condenseValue($longText);

        self::assertSame('#' . substr(sha1($longText), 0, 12) . ' (300 characters)', $hashToken);
        self::assertSame($hashToken, $agentSurfaceDocumentBuilder->condenseValue($longText));
        self::assertNotSame($hashToken, $agentSurfaceDocumentBuilder->condenseValue($longText . 'b'));
    }

    public function testASmallMapStaysAMapAndALargeOneBecomesOneToken(): void
    {
        $agentSurfaceDocumentBuilder = new AgentSurfaceDocumentBuilder();

        self::assertSame(['effect' => 'READ', 'resultIsReusedWithinTurn' => true], $agentSurfaceDocumentBuilder->condenseValue(['resultIsReusedWithinTurn' => true, 'effect' => 'READ']));
        $largeMap = ['type' => 'object', 'description' => str_repeat('d', 400)];
        self::assertStringStartsWith('#', (string)$agentSurfaceDocumentBuilder->condenseValue($largeMap));
    }

    public function testAListWithALongTextOrAStructureBecomesOneToken(): void
    {
        $agentSurfaceDocumentBuilder = new AgentSurfaceDocumentBuilder();

        self::assertStringStartsWith('#', (string)$agentSurfaceDocumentBuilder->condenseValue(['short', str_repeat('x', 200)]));
        self::assertStringStartsWith('#', (string)$agentSurfaceDocumentBuilder->condenseValue([['name' => 'a']]));
    }

    public function testFieldsThatHoldNothingAndFieldsOutsideTheSurfaceAreLeftOutAndTheRestIsSorted(): void
    {
        $agentSurfaceDocumentBuilder = new AgentSurfaceDocumentBuilder();

        $condensedFields = $agentSurfaceDocumentBuilder->condenseFields([
            'title' => 'getTasks',
            'objectType' => 'DDD\\Some\\Class',
            'isParallelSafe' => true,
            'isAlwaysLoad' => false,
            'executionTimeoutSeconds' => null,
            'requiredAccountRightNames' => [],
            'summary' => '',
            'imageBudget' => 0,
        ]);

        self::assertSame(['imageBudget' => 0, 'isParallelSafe' => true, 'title' => 'getTasks'], $condensedFields);
    }

    public function testAToolEntryNamesEveryParameterAndTheRestOfItsSchema(): void
    {
        $agentSurfaceDocumentBuilder = new AgentSurfaceDocumentBuilder();

        $toolEntry = $agentSurfaceDocumentBuilder->buildToolEntry([
            'objectType' => 'DDD\\Some\\ToolDefinition',
            'name' => 'getTasks',
            'title' => 'getTasks',
            'description' => str_repeat('d', 300),
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'top' => ['type' => 'integer'],
                    'filters' => ['type' => 'string', 'description' => str_repeat('f', 500)],
                ],
            ],
        ]);

        self::assertSame(['description', 'title', 'parameters', 'inputSchemaWithoutParameters'], array_keys($toolEntry));
        self::assertSame(['type' => 'integer'], $toolEntry['parameters']['top']);
        self::assertStringStartsWith('#', $toolEntry['parameters']['filters']);
        self::assertSame($agentSurfaceDocumentBuilder->buildHash(['type' => 'object']), $toolEntry['inputSchemaWithoutParameters']);
    }
}
