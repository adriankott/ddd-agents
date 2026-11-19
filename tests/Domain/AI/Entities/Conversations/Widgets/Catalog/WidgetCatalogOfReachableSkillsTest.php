<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Catalog;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Widgets\Catalog\ConversationWidgetCatalog;
use DDD\Domain\AI\Entities\Conversations\Widgets\CustomVisualizationConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\QuickRepliesConversationWidget;
use DDD\Domain\AI\Entities\Skills\AISkills;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * `findWidgets` is offered only while a skill the conversation can reach brings a widget's data: the catalog answers
 * whether given skills bring one; a widget the model writes without a skill (taught by the base prompt) or a meta
 * tool writes does not count.
 */
class WidgetCatalogOfReachableSkillsTest extends UnitTestCase
{
    public function testOnlyAWidgetBroughtByOneOfTheSkillsCounts(): void
    {
        $conversationWidgetCatalog = ConversationWidgetCatalog::buildFrom(
            [
                CustomVisualizationConversationWidget::WIDGET_TYPE => CustomVisualizationConversationWidget::class,
                QuickRepliesConversationWidget::WIDGET_TYPE => QuickRepliesConversationWidget::class,
            ],
            new AISkills(),
            ['Demo.Tickets' => "return new QuickRepliesConversationWidget();", 'Demo.Notes' => 'return "no widget";'],
            ['askOwner' => "'CustomVisualization'"]
        );

        self::assertTrue($conversationWidgetCatalog->hasEntryProvidedByOneOfSkills(['Demo.Notes', 'Demo.Tickets']));
        self::assertFalse($conversationWidgetCatalog->hasEntryProvidedByOneOfSkills(['Demo.Notes']), 'a skill without a widget');
        self::assertFalse($conversationWidgetCatalog->hasEntryProvidedByOneOfSkills([]), 'no skill: the meta tools\' and the base prompt\'s widgets do not count');
    }

    public function testTheOfferAsksTheReachableSkillsAndStaysOpenWhenTheCatalogCannotBeBuilt(): void
    {
        $offerSource = (string)file_get_contents((string)(new \ReflectionClass(AIConversation::class))->getFileName());

        self::assertStringContainsString('&& $this->hasWidgetsBroughtByReachableSkills($anchorLoadedCapabilities);', $offerSource, 'the offer condition of findWidgets');
        self::assertTrue((new \ReflectionMethod(AIConversation::class, 'hasWidgetsBroughtByReachableSkills'))->isPublic());
    }
}
