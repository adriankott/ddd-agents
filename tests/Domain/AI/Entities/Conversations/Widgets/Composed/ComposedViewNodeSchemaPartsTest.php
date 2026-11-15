<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeClasses;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeSchema;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeSchemaParts;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The split of the node schema by the declaring class: without an application every registered type is the module's,
 * so the module's schema is the whole schema and the application's part carries no type; a type without a registered
 * class is the application's.
 */
class ComposedViewNodeSchemaPartsTest extends UnitTestCase
{
    public function testWithoutAnApplicationTheModulesSchemaIsTheWholeSchema(): void
    {
        self::assertSame(ComposedViewNodeSchema::buildJson(), ComposedViewNodeSchemaParts::buildModuleSchemaJson());
    }

    public function testWithoutAnApplicationItsPartCarriesNoType(): void
    {
        $applicationSchemaPart = json_decode(ComposedViewNodeSchemaParts::buildApplicationSchemaPartJson(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['version', 'generatedBy', 'nodeTypes', 'embeddableWidgets', 'widgetTypes', 'dataSources', 'entityTypes'], array_keys($applicationSchemaPart));
        self::assertSame(ComposedViewNodeSchema::SCHEMA_VERSION, $applicationSchemaPart['version']);
        self::assertSame(ComposedViewNodeSchemaParts::APPLICATION_SCHEMA_PART_GENERATED_BY, $applicationSchemaPart['generatedBy']);
        self::assertSame([], $applicationSchemaPart['nodeTypes']);
        self::assertSame([], $applicationSchemaPart['widgetTypes']);
        self::assertStringContainsString('"nodeTypes": {}', ComposedViewNodeSchemaParts::buildApplicationSchemaPartJson(), 'an empty map stays a JSON object');
    }

    public function testATypeIsSplitByItsRegisteredClass(): void
    {
        $nodeClassesByNodeType = ComposedViewNodeClasses::getNodeClassesByNodeType();
        $moduleNodeType = (string)array_key_first($nodeClassesByNodeType);
        $entriesByType = [$moduleNodeType => ['summary' => 'module'], 'UnregisteredNode' => ['summary' => 'application']];

        self::assertTrue(ComposedViewNodeSchemaParts::isTypeDeclaredByModule($nodeClassesByNodeType[$moduleNodeType], 'NODE_TYPE'));
        self::assertSame([$moduleNodeType => ['summary' => 'module']], ComposedViewNodeSchemaParts::filterEntriesByDeclaringSide($entriesByType, $nodeClassesByNodeType, 'NODE_TYPE', true));
        self::assertSame(['UnregisteredNode' => ['summary' => 'application']], ComposedViewNodeSchemaParts::filterEntriesByDeclaringSide($entriesByType, $nodeClassesByNodeType, 'NODE_TYPE', false));
        self::assertSame(['UnregisteredNode'], ComposedViewNodeSchemaParts::filterTypesByDeclaringSide([$moduleNodeType, 'UnregisteredNode'], $nodeClassesByNodeType, 'NODE_TYPE', false));
    }
}
