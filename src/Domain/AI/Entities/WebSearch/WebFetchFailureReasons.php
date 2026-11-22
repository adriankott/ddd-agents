<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\WebSearch;

/**
 * The failure reasons a page read (url-to-markdown) and a screenshot share, and the ONE sentence per reason with the
 * next move the model reads. Both capabilities ask here before their own reasons, so a code the endpoints send for
 * either is explained the same way. English: it is model-facing. Pure.
 */
class WebFetchFailureReasons
{
    /** The host has no DNS record: no such website, or a typo in the address. */
    public const string UNRESOLVABLE = 'UNRESOLVABLE';

    /** The address serves something other than a web page (a PDF, an image, a download). */
    public const string UNSUPPORTED_TYPE = 'UNSUPPORTED_TYPE';

    /** The page is larger than the endpoint reads or renders. */
    public const string TOO_LARGE = 'TOO_LARGE';

    /** The page loaded but yielded nothing readable or visible. */
    public const string NO_CONTENT = 'NO_CONTENT';

    /** The address redirects in a loop or too often. */
    public const string TOO_MANY_REDIRECTS = 'TOO_MANY_REDIRECTS';

    public const string CAPABILITY_PAGE_READ = 'pageRead';
    public const string CAPABILITY_SCREENSHOT = 'screenshot';

    /**
     * The sentence for `$reason` when it is a shared one, for a page read or a screenshot (`$capability`); null for
     * a reason the capability explains itself.
     */
    public static function findSharedFailureSentence(?string $reason, string $urlLabel, string $capability): ?string
    {
        $isScreenshot = $capability === self::CAPABILITY_SCREENSHOT;
        return match ($reason) {
            self::UNRESOLVABLE => "The address {$urlLabel} cannot be resolved (check the spelling): no such website, or a "
                . 'typo in the address — this is NOT a private address. NEVER retry it as written; correct the address '
                . 'only from a search result or the owner\'s words.',
            self::UNSUPPORTED_TYPE => $isScreenshot
                ? "The address {$urlLabel} is not a web page (a document such as a PDF, an image or a download), so it is "
                    . 'not rendered. Read its text with getWebPageAsMarkdown instead; do not retry the screenshot.'
                : "The address {$urlLabel} is not a web page (a document, image or download) and cannot be read as text. "
                    . 'Do not retry.',
            self::TOO_LARGE => "The page {$urlLabel} is too large to " . ($isScreenshot ? 'render' : 'read')
                . '. Retrying is pointless; use another page or source.',
            self::NO_CONTENT => "The page {$urlLabel} loaded but " . ($isScreenshot ? 'showed nothing to capture' : 'yielded no readable content')
                . ' (empty or fully scripted page). Do not retry it.',
            self::TOO_MANY_REDIRECTS => "The address {$urlLabel} redirects in a loop or too often, so it cannot be "
                . ($isScreenshot ? 'rendered' : 'read') . '. Do not retry it; use another page or source.',
            default => null,
        };
    }
}
