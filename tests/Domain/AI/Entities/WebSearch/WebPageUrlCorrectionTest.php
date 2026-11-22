<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\WebSearch;

use DDD\Domain\AI\Entities\WebSearch\WebPageUrlCorrection;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A URL copied inexactly from a search result is put back to the listed one; a listed URL, a URL no search listed and
 * an ambiguous match stay as they are.
 */
class WebPageUrlCorrectionTest extends UnitTestCase
{
    protected const array LISTED_URLS = [
        'https://www.example.org/ma%C3%9Fnahmen/foerderung',
        'https://example.com/Stra%C3%9Fe',
        'https://example.net/a',
        'https://example.net/A',
    ];

    public function testADecodedOrTransliteratedCopyIsPutBackToTheListedUrl(): void
    {
        $urlCorrection = WebPageUrlCorrection::fromRequestedUrls(
            ['https://www.example.org/massnahmen/foerderung', 'https://example.com/straße/'],
            self::LISTED_URLS
        );

        self::assertSame(['https://www.example.org/ma%C3%9Fnahmen/foerderung', 'https://example.com/Stra%C3%9Fe'], $urlCorrection->urls);
        self::assertStringContainsString('character for character', (string)$urlCorrection->buildNoteForModel());
    }

    public function testListedUnlistedAndAmbiguousUrlsStay(): void
    {
        $urlCorrection = WebPageUrlCorrection::fromRequestedUrls(
            ['https://example.org/ma%C3%9Fnahmen/foerderung/', 'https://owner-pasted.example/page', 'https://example.net/%61'],
            self::LISTED_URLS
        );

        self::assertSame(['https://example.org/ma%C3%9Fnahmen/foerderung/', 'https://owner-pasted.example/page', 'https://example.net/%61'], $urlCorrection->urls);
        self::assertNull($urlCorrection->buildNoteForModel());
    }
}
