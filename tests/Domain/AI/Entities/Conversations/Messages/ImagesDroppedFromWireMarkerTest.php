<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The marker of a result whose pictures left the wire keeps what the model wrote about them valid: it saw them in an
 * earlier step, never "could not see" them; only new details are barred.
 */
class ImagesDroppedFromWireMarkerTest extends UnitTestCase
{
    public function testTheMarkerKeepsTheModelsDescriptionValid(): void
    {
        $marker = ToolCall::buildImagesDroppedFromWireMarker('2 pictures', 2);

        self::assertStringContainsString('no longer on the wire', $marker);
        self::assertStringContainsString('You saw them in an earlier step', $marker);
        self::assertStringContainsString('is your record and stays valid', $marker);
        self::assertStringContainsString('never say you could not see them', $marker);
        self::assertStringNotContainsString('Do not describe or rely on what they showed', $marker);
        self::assertStringContainsString('You saw it in an earlier step', ToolCall::buildImagesDroppedFromWireMarker('picture', 1));
    }
}
