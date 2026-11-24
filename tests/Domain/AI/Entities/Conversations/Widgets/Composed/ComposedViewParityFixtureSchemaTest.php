<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeSchemaFilePaths;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeSchemaParts;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The parity fixture hosts read (`web/src/renderer/widget-tree/composed-view-parity.json`, `stubSchema`) carries the
 * module's whole schema plus its test-only stubs: every widget, embeddable widget and data source the module exports
 * is in it. A widget the module declares and the fixture lacks fails a host's parity test against the package
 * fixture. The shipped module schema itself is compared by ComposedViewNodeSchemaExportCommandTest.
 */
class ComposedViewParityFixtureSchemaTest extends UnitTestCase
{
    public const string PARITY_FIXTURE_RELATIVE_PATH = 'web/src/renderer/widget-tree/composed-view-parity.json';

    public function testTheShippedModuleSchemaIsTheExport(): void
    {
        $moduleSchemaFilePath = dirname(__DIR__, 7) . '/' . ComposedViewNodeSchemaFilePaths::MODULE_SCHEMA_FILE_RELATIVE_PATH;

        self::assertSame(ComposedViewNodeSchemaParts::buildModuleSchemaJson(), file_get_contents($moduleSchemaFilePath));
    }

    public function testTheParityFixtureCarriesEveryWidgetAndDataSourceOfTheModule(): void
    {
        $moduleSchema = json_decode(ComposedViewNodeSchemaParts::buildModuleSchemaJson(), true, 512, JSON_THROW_ON_ERROR);
        $parityFixture = json_decode(
            (string)file_get_contents(dirname(__DIR__, 7) . '/' . self::PARITY_FIXTURE_RELATIVE_PATH),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $stubSchema = $parityFixture['stubSchema'];

        self::assertSame([], array_values(array_diff($moduleSchema['widgetTypes'], $stubSchema['widgetTypes'])), 'widgetTypes missing in the parity fixture');
        self::assertSame([], array_values(array_diff(array_keys($moduleSchema['embeddableWidgets']), array_keys($stubSchema['embeddableWidgets']))), 'embeddableWidgets missing in the parity fixture');
        self::assertSame([], array_values(array_diff(array_keys($moduleSchema['dataSources']), array_keys($stubSchema['dataSources']))), 'dataSources missing in the parity fixture');
        foreach (['budgets', 'categories', 'sizeClasses', 'actionTypes', 'bindingKinds', 'findingCodes', 'noteFindingCodes'] as $sharedSection) {
            self::assertSame($moduleSchema[$sharedSection], $stubSchema[$sharedSection], "{$sharedSection} of the parity fixture differs from the module's schema");
        }
    }
}
