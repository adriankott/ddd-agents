<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\WebSearch;

use DDD\Domain\AI\Entities\WebSearch\PageScreenshots\PageScreenshot;
use DDD\Domain\AI\Entities\WebSearch\PageScreenshots\PageScreenshotPart;
use DDD\Tests\Agents\Domain\AI\Services\WebSearch\Fixtures\ScriptedAIWebSearchService;
use DDD\Tests\Agents\Support\UnitTestCase;
use Imagick;
use ImagickDraw;
use ImagickPixel;

/**
 * {@see \DDD\Domain\AI\Services\WebSearch\AIWebSearchService::capturePagePartForModel()} decides between a short page,
 * a page that blocks scrolling and an ordinary long page from synthetic captures; the capture itself is scripted, so
 * no page is rendered.
 */
class AIWebSearchServicePagePartTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!PageScreenshot::isImagickAvailable()) {
            self::markTestSkipped('the imagick extension is not available');
        }
    }

    public function testAShortPageEndingInsideTheWindowIsRenderedOnceAndNotCalledScrollBlocking(): void
    {
        $scriptedAIWebSearchService = $this->buildServiceReturningCaptures(
            $this->buildCaptureWithContentDownTo(PageScreenshotPart::DEFAULT_VIEWPORT_HEIGHT_PX, 450)
        );

        $pageScreenshotPart = $scriptedAIWebSearchService->capturePagePartForModel('https://example.com');

        self::assertSame(1, $scriptedAIWebSearchService->captureCallCount);
        self::assertFalse($pageScreenshotPart->renderedInTallWindow);
        self::assertSame(PageScreenshotPart::DEFAULT_VIEWPORT_HEIGHT_PX, $pageScreenshotPart->pageHeightPx);
        self::assertCount(1, $pageScreenshotPart->base64Images);
        self::assertStringNotContainsString('blocks scrolling', $pageScreenshotPart->toCoverageText());
    }

    public function testAShortPageWhoseTallRenderShrinksToOneWindowKeepsTheFirstRender(): void
    {
        // the content reaches the window edge, but in the tall window it ends above one window height
        $scriptedAIWebSearchService = $this->buildServiceReturningCaptures(
            $this->buildCaptureWithContentDownTo(PageScreenshotPart::DEFAULT_VIEWPORT_HEIGHT_PX, PageScreenshotPart::DEFAULT_VIEWPORT_HEIGHT_PX),
            $this->buildCaptureWithContentDownTo(PageScreenshotPart::TALL_VIEWPORT_HEIGHT_PX, 1250)
        );

        $pageScreenshotPart = $scriptedAIWebSearchService->capturePagePartForModel('https://example.com');

        self::assertSame(2, $scriptedAIWebSearchService->captureCallCount);
        self::assertFalse($pageScreenshotPart->renderedInTallWindow);
        self::assertSame(PageScreenshotPart::DEFAULT_VIEWPORT_HEIGHT_PX, $pageScreenshotPart->pageHeightPx);
        self::assertStringNotContainsString('blocks scrolling', $pageScreenshotPart->toCoverageText());
    }

    public function testAPageThatBlocksScrollingIsShownFromTheTallRender(): void
    {
        $scriptedAIWebSearchService = $this->buildServiceReturningCaptures(
            $this->buildCaptureWithContentDownTo(PageScreenshotPart::DEFAULT_VIEWPORT_HEIGHT_PX, PageScreenshotPart::DEFAULT_VIEWPORT_HEIGHT_PX),
            $this->buildCaptureWithContentDownTo(PageScreenshotPart::TALL_VIEWPORT_HEIGHT_PX, 5000)
        );

        $pageScreenshotPart = $scriptedAIWebSearchService->capturePagePartForModel('https://news.example');

        self::assertTrue($pageScreenshotPart->renderedInTallWindow);
        self::assertGreaterThanOrEqual(5000, $pageScreenshotPart->pageHeightPx, 'no content is cut');
        self::assertLessThanOrEqual(5100, $pageScreenshotPart->pageHeightPx, 'the empty window below is gone');
        self::assertSame(2, $pageScreenshotPart->getPartCount());
        self::assertStringContainsString('This site blocks scrolling', $pageScreenshotPart->toCoverageText());
    }

    public function testAnOrdinaryLongPageIsRenderedOnce(): void
    {
        $scriptedAIWebSearchService = $this->buildServiceReturningCaptures($this->buildCaptureWithContentDownTo(6000, 6000));

        $pageScreenshotPart = $scriptedAIWebSearchService->capturePagePartForModel('https://www.example.org');

        self::assertSame(1, $scriptedAIWebSearchService->captureCallCount);
        self::assertFalse($pageScreenshotPart->renderedInTallWindow);
        self::assertSame(6000, $pageScreenshotPart->pageHeightPx);
        self::assertSame(2, $pageScreenshotPart->getPartCount());
        self::assertCount(2, $pageScreenshotPart->base64Images);
        $coverageText = $pageScreenshotPart->toCoverageText();
        self::assertStringContainsString('6,000 px tall = 2 parts', $coverageText);
        self::assertStringNotContainsString('blocks scrolling', $coverageText);
    }

    public function testOnlyACaptureEndingInEmptyBackgroundHasAnEmptyBottom(): void
    {
        self::assertTrue($this->buildCaptureWithContentDownTo(1400, 450)->hasEmptyBottom());
        self::assertFalse($this->buildCaptureWithContentDownTo(1400, 1400)->hasEmptyBottom());
        self::assertFalse((new PageScreenshot())->hasEmptyBottom(), 'nothing captured');
    }

    /** A white capture with a black block (white margins left and right) from the top down to `$contentBottomPx`. */
    protected function buildCaptureWithContentDownTo(int $height, int $contentBottomPx): PageScreenshot
    {
        $imagick = new Imagick();
        $imagick->newImage(PageScreenshotPart::DESKTOP_VIEWPORT_WIDTH_PX, $height, new ImagickPixel('white'));
        $contentBlock = new ImagickDraw();
        $contentBlock->setFillColor(new ImagickPixel('black'));
        $contentBlock->rectangle(100, 0, 900, $contentBottomPx - 1);
        $imagick->drawImage($contentBlock);
        $imagick->setImageFormat('png');
        $pageScreenshot = new PageScreenshot();
        $pageScreenshot->content = base64_encode($imagick->getImageBlob());
        $imagick->clear();
        return $pageScreenshot;
    }

    protected function buildServiceReturningCaptures(PageScreenshot ...$capturesInCallOrder): ScriptedAIWebSearchService
    {
        $scriptedAIWebSearchService = new ScriptedAIWebSearchService();
        $scriptedAIWebSearchService->scriptedCaptures = $capturesInCallOrder;
        return $scriptedAIWebSearchService;
    }
}
