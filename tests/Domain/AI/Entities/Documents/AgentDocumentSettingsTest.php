<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Documents;

use DDD\Domain\AI\Entities\Documents\AgentDocumentSettings;
use PHPUnit\Framework\TestCase;

/** `AI.documents`: the shipped file reads as the defaults, a missing or wrong key keeps its default, the caps hold. */
class AgentDocumentSettingsTest extends TestCase
{
    public function testTheShippedConfigFileReadsAsTheDefaults(): void
    {
        $shippedSettings = AgentDocumentSettings::fromConfigNode(require dirname(__DIR__, 5) . '/config/app/AI/documents.php');

        self::assertEquals(new AgentDocumentSettings(), $shippedSettings);
    }

    public function testMissingAndWrongKeysKeepTheirDefaultsAndTheCapsHold(): void
    {
        $settings = AgentDocumentSettings::fromConfigNode([
            'enabled' => 'no',
            'maxDocumentBytes' => -5,
            'ocr' => false,
            'cacheGroup' => ' redis ',
            'pageImages' => ['maxPerCall' => 50, 'resolutionDpi' => 1000],
        ]);

        self::assertTrue($settings->enabled);
        self::assertSame(26_214_400, $settings->maxDocumentBytes);
        self::assertFalse($settings->ocrEnabled);
        self::assertSame('redis', $settings->cacheGroup);
        self::assertSame(12, $settings->maxPageImagesPerCall);
        self::assertSame(300, $settings->pageImageResolutionDpi);
        self::assertSame(36, AgentDocumentSettings::fromConfigNode(['pageImages' => ['resolutionDpi' => 10]])->pageImageResolutionDpi);
        self::assertEquals(new AgentDocumentSettings(), AgentDocumentSettings::fromConfigNode(null));
    }
}
