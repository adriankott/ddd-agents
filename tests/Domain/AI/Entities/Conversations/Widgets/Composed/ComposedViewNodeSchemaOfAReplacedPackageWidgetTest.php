<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeSchema;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeSchemaParts;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetClasses;
use DDD\Domain\AI\Entities\Conversations\Widgets\ImageGalleryConversationWidget;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Replacing\InstalledConversationWidgetClasses;
use DDD\Tests\Agents\Support\UnitTestCase;
use ExampleApplication\Domain\AI\Entities\Conversations\Widgets\ImageGalleryConversationWidget as ApplicationImageGalleryConversationWidget;

/**
 * An application that replaces a package widget (REPLACES_PACKAGE_WIDGET) leaves the module's half of the schema as the
 * package ships it; its own part names the replaced type and carries its own embeddable entry.
 */
class ComposedViewNodeSchemaOfAReplacedPackageWidgetTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        InstalledConversationWidgetClasses::installWidgetClassesByWidgetType(null);
        parent::tearDown();
    }

    public function testTheModulesSchemaStaysThePackagesAndTheApplicationsPartNamesTheReplacedType(): void
    {
        require_once dirname(__DIR__) . '/Replacing/ExampleApplication/ImageGalleryConversationWidget.php';
        $packageSchemaJson = ComposedViewNodeSchema::buildJson();
        self::assertSame([], ConversationWidgetClasses::getReplacedPackageWidgetClassesByWidgetType(), 'nothing is replaced without an application class');
        $widgetClassesByWidgetType = ConversationWidgetClasses::getWidgetClassesByWidgetType();
        self::assertSame(ImageGalleryConversationWidget::class, $widgetClassesByWidgetType['ImageGallery']);
        InstalledConversationWidgetClasses::installWidgetClassesByWidgetType(
            array_replace($widgetClassesByWidgetType, ['ImageGallery' => ApplicationImageGalleryConversationWidget::class])
        );

        self::assertSame(['ImageGallery' => ImageGalleryConversationWidget::class], ConversationWidgetClasses::getReplacedPackageWidgetClassesByWidgetType());
        self::assertSame($packageSchemaJson, ComposedViewNodeSchemaParts::buildModuleSchemaJson());
        $applicationSchemaPart = json_decode(ComposedViewNodeSchemaParts::buildApplicationSchemaPartJson(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['ImageGallery'], $applicationSchemaPart['replacedPackageWidgets']);
        self::assertSame(['ImageGallery' => ['sizeClass' => 'compact']], $applicationSchemaPart['embeddableWidgets']);
    }
}
