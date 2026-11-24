<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\WebSearch;

use DDD\Domain\AI\Entities\WebSearch\WebPageMarkdown;
use DDD\Domain\AI\Entities\WebSearch\WebPageMarkdowns;
use DDD\Presentation\Mcp\Client\Agents\Dtos\WebPageMarkdownMcpResponseDto;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * One `getWebPageAsMarkdown` call reads several pages (`urls`) and renders them as one result in the order asked: a
 * failed page stays in place with its reason, what to do about it is the platform note (never the screened result),
 * the per-page cap shrinks with the count but keeps its floor; a single page keeps the single shape.
 */
class WebPageMarkdownsBatchResponseTest extends UnitTestCase
{
    public function testSeveralPagesRenderInOrderWithFailuresInline(): void
    {
        $webPageMarkdowns = new WebPageMarkdowns();
        $firstPage = $this->buildPage('https://a.example/', 'A', str_repeat('alpha ', 50));
        $webPageMarkdowns->add($firstPage);
        $failedPage = new WebPageMarkdown();
        $failedPage->url = 'https://b.example/';
        $failedPage->ok = false;
        $failedPage->reason = WebPageMarkdown::REASON_FETCH_FAILED;
        $webPageMarkdowns->add($failedPage);
        $thirdPage = $this->buildPage('https://c.example/', 'C', str_repeat('gamma ', 50));
        $webPageMarkdowns->add($thirdPage);

        $responseDto = WebPageMarkdownMcpResponseDto::ofPages($webPageMarkdowns);
        $rendered = $responseDto->toLlmMarkdown();

        self::assertStringContainsString('## Page 1 of 3', $rendered);
        self::assertStringContainsString('## Page 2 of 3', $rendered);
        self::assertStringContainsString('## Page 3 of 3', $rendered);
        $failedPagePosition = strpos($rendered, 'https://b.example/');
        self::assertNotFalse($failedPagePosition);
        self::assertLessThan($failedPagePosition, strpos($rendered, 'alpha'));
        self::assertLessThan(strpos($rendered, 'gamma'), $failedPagePosition);
        self::assertStringNotContainsString('Do not retry', $rendered);
        self::assertStringContainsString($failedPage->buildFailureExplanationForAgent(), (string)$responseDto->buildPlatformNoteForModel());
    }

    public function testThePerPageCapShrinksWithTheCountButKeepsTheFloor(): void
    {
        $webPageMarkdowns = new WebPageMarkdowns();
        for ($pageIndex = 0; $pageIndex < 5; $pageIndex++) {
            $page = $this->buildPage('https://p' . $pageIndex . '.example/', 'P' . $pageIndex, str_repeat('word ', 6000));
            $webPageMarkdowns->add($page);
        }
        $rendered = WebPageMarkdownMcpResponseDto::ofPages($webPageMarkdowns)->toLlmMarkdown();

        // each page is cut (never the whole 30,000 characters), but far more than a sliver of each survives
        self::assertLessThan(5 * 30000, strlen($rendered));
        self::assertGreaterThan(5 * 1000, strlen($rendered));
        self::assertSame(5, substr_count($rendered, '[Page truncated'));
    }

    public function testASinglePageStillRendersThroughTheSingleShape(): void
    {
        $rendered = WebPageMarkdownMcpResponseDto::of($this->buildPage('https://a.example/', 'A', 'body'))->toLlmMarkdown();

        self::assertStringNotContainsString('## Page 1 of', $rendered);
        self::assertStringContainsString('body', $rendered);
    }

    protected function buildPage(string $url, string $title, string $markdown): WebPageMarkdown
    {
        $webPageMarkdown = new WebPageMarkdown();
        $webPageMarkdown->url = $url;
        $webPageMarkdown->ok = true;
        $webPageMarkdown->title = $title;
        $webPageMarkdown->markdown = $markdown;
        return $webPageMarkdown;
    }
}
