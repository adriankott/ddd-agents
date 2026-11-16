<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Support\ComposedView;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Data\IconComposedViewNode;

/**
 * The `Icon` node as if it had been called `Glyph` before: stored trees with `Glyph` read as `Icon`. The declarations
 * are read off the class itself, so the stub repeats the prop it is read with.
 */
#[ComposedViewNodeProp(name: 'name', types: [ComposedViewNodeProp::TYPE_STRING], isRequired: true, allowedValues: IconComposedViewNode::ICON_NAMES, description: 'the icon')]
class IconWithFormerNameComposedViewNode extends IconComposedViewNode
{
    public const array LEGACY_NODE_TYPES = ['Glyph'];
}
