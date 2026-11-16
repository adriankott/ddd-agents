<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeClasses;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Tests\Agents\Support\ComposedView\IconWithFormerNameComposedViewNode;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A former node type a class names in `LEGACY_NODE_TYPES` is read as that class and written with its current type; it
 * is no registered node type, so nothing offers it to the model.
 */
class ComposedViewLegacyNodeTypesTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ComposedViewNodeClasses::replaceNodeClasses(['Icon' => IconWithFormerNameComposedViewNode::class] + ComposedViewNodeClasses::getNodeClassesByNodeType());
    }

    protected function tearDown(): void
    {
        ComposedViewNodeClasses::replaceNodeClasses(null);
        parent::tearDown();
    }

    public function testAStoredTreeWithTheFormerTypeReadsAsTheCurrentClass(): void
    {
        $reading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Glyph', 'name' => 'check']]);

        self::assertInstanceOf(IconWithFormerNameComposedViewNode::class, $reading->view);
        self::assertSame('Icon', $reading->view::NODE_TYPE);
        self::assertSame([], $reading->findings->getCodes());
    }

    public function testTheFormerTypeIsNoRegisteredNodeType(): void
    {
        self::assertSame(IconWithFormerNameComposedViewNode::class, ComposedViewNodeClasses::getNodeClassForNodeType('Glyph'));
        self::assertNotContains('Glyph', ComposedViewNodeClasses::getNodeTypes());
        self::assertSame(['Glyph' => IconWithFormerNameComposedViewNode::class], ComposedViewNodeClasses::getNodeClassesByLegacyNodeType());
    }
}
