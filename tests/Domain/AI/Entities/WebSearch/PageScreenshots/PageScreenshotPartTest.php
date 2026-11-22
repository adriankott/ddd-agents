<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\WebSearch\PageScreenshots;

use DDD\Domain\AI\Entities\WebSearch\PageScreenshots\PageScreenshot;
use DDD\Domain\AI\Entities\WebSearch\PageScreenshots\PageScreenshotPart;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The geometry of a screenshot part (two 2000-px slices per part), the coverage line (page height, parts, the other
 * parts, the URL marked as the only third-party text), and the capture's own measurements and cuts.
 */
class PageScreenshotPartTest extends UnitTestCase
{
    public function testThePartsOfATallPage(): void
    {
        $pageScreenshotPart = new PageScreenshotPart();
        $pageScreenshotPart->url = 'https://example.com';
        $pageScreenshotPart->pageWidthPx = 1024;
        $pageScreenshotPart->pageHeightPx = 9800;
        $pageScreenshotPart->partNumber = 2;
        $pageScreenshotPart->base64Images = ['a', 'b'];

        self::assertSame(3, $pageScreenshotPart->getPartCount());
        self::assertSame(4000, $pageScreenshotPart->getShownFromPx());
        self::assertSame(8000, $pageScreenshotPart->getShownUntilPx());
        self::assertSame(['part=1', 'part=3'], $pageScreenshotPart->buildOtherPartArguments());
        $coverageText = $pageScreenshotPart->toCoverageText();
        self::assertStringContainsString('9,800 px tall = 3 parts. Shown: part 2 of 3 (4,000–8,000 px) as 2 segment(s)', $coverageText);
        self::assertStringContainsString('Other parts: part=1, part=3.', $coverageText);
        self::assertStringContainsString('<untrusted-content source="https://example.com">https://example.com</untrusted-content>', $coverageText);
        self::assertSame(1, PageScreenshotPart::partCountForHeight(0));
    }

    public function testTheCaptureIsMeasuredAndCutIntoSegments(): void
    {
        $pageScreenshot = new PageScreenshot();
        self::assertSame(0, $pageScreenshot->getPixelHeight(), 'nothing captured');
        self::assertSame([], $pageScreenshot->buildSegmentWindowBase64(2000, 0, 2));
        self::assertFalse($pageScreenshot->hasEmptyBottom());

        $pageScreenshot->content = self::buildPngBase64(30, 50);
        self::assertSame(30, $pageScreenshot->getPixelWidth());
        self::assertSame(50, $pageScreenshot->getPixelHeight());
        if (!PageScreenshot::isImagickAvailable()) {
            self::assertSame([$pageScreenshot->content], $pageScreenshot->buildSegmentWindowBase64(20, 0, 2), 'without Imagick the first window is the whole capture');
            self::assertSame([], $pageScreenshot->buildSegmentWindowBase64(20, 2, 2));
            return;
        }
        $segments = $pageScreenshot->buildSegmentWindowBase64(20, 0, 2);
        self::assertCount(2, $segments);
        self::assertCount(1, $pageScreenshot->buildSegmentWindowBase64(20, 2, 2), 'the window past the page end yields what is left');
    }

    /** A plain one-colour PNG of the given size, built with GD. */
    protected static function buildPngBase64(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int)imagecolorallocate($image, 255, 255, 255));
        ob_start();
        imagepng($image);
        return base64_encode((string)ob_get_clean());
    }
}
