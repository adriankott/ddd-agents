<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets;

use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetClasses;
use DDD\Domain\AI\Entities\Conversations\Widgets\ImageGalleryConversationWidget;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Replacing\HostReplacing\ImageGalleryConversationWidget as HostReplacingImageGalleryConversationWidget;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Replacing\HostWithoutFlag\ImageGalleryConversationWidget as HostWithoutFlagImageGalleryConversationWidget;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Replacing\OtherHostReplacing\ImageGalleryConversationWidget as OtherHostReplacingImageGalleryConversationWidget;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * An application's widget that declares REPLACES_PACKAGE_WIDGET takes the place of the package's widget of its wire name
 * without extending it, in either order of discovery; without the flag, and between two application classes, the
 * second class of a wire name stays a build error.
 */
class ConversationWidgetReplacingPackageWidgetTest extends TestCase
{
    public function testTheApplicationsClassWithTheFlagReplacesThePackagesWidgetInEitherOrder(): void
    {
        self::assertSame(
            HostReplacingImageGalleryConversationWidget::class,
            ConversationWidgetClasses::buildWidgetClassesByWidgetType(ImageGalleryConversationWidget::class, HostReplacingImageGalleryConversationWidget::class)['ImageGallery']
        );
        self::assertSame(
            HostReplacingImageGalleryConversationWidget::class,
            ConversationWidgetClasses::buildWidgetClassesByWidgetType(HostReplacingImageGalleryConversationWidget::class, ImageGalleryConversationWidget::class)['ImageGallery']
        );
    }

    public function testWithoutTheFlagTheSecondClassOfTheWireNameIsABuildError(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Two widget classes declare the WIDGET_TYPE "ImageGallery"');
        ConversationWidgetClasses::buildWidgetClassesByWidgetType(ImageGalleryConversationWidget::class, HostWithoutFlagImageGalleryConversationWidget::class);
    }

    public function testTwoApplicationClassesOfOneWireNameAreABuildErrorEvenWithTheFlag(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Two widget classes declare the WIDGET_TYPE "ImageGallery"');
        ConversationWidgetClasses::buildWidgetClassesByWidgetType(HostReplacingImageGalleryConversationWidget::class, OtherHostReplacingImageGalleryConversationWidget::class);
    }

    public function testOnlyTheWidgetsOfThePackagesNamespaceArePackageWidgets(): void
    {
        self::assertTrue(ConversationWidgetClasses::isPackageWidgetClass(ImageGalleryConversationWidget::class));
        self::assertFalse(ConversationWidgetClasses::isPackageWidgetClass(HostReplacingImageGalleryConversationWidget::class));
    }
}
