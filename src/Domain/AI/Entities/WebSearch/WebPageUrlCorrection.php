<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\WebSearch;

use DDD\Domain\Base\Entities\ValueObject;
use Normalizer;

/**
 * The page URLs a read tool receives, put back to the URL a search of the conversation listed when the model copied
 * it inexactly: a percent-encoded path decoded (`ma%C3%9Fnahmen` → `maßnahmen`), an umlaut or `ß` transliterated
 * (`massnahmen`), another letter case. A URL is replaced only when it is not itself a listed hit and exactly ONE listed
 * hit has the same folded form; a URL no search listed (pasted by the owner, from a tool) stays as it is. Pure.
 */
class WebPageUrlCorrection extends ValueObject
{
    /** Letters a model writes in their ASCII spelling. */
    public const array TRANSLITERATIONS = ['ß' => 'ss', 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'æ' => 'ae', 'ø' => 'o', 'å' => 'a'];

    /** @var string[] The URLs to read, in the order asked, corrected where a listed hit matched. */
    public array $urls = [];

    /** @var string[] The listed hit URLs that replaced an inexact copy. */
    public array $correctedUrls = [];

    /**
     * @param string[] $requestedUrls
     * @param string[] $earlierSearchHitUrls the page URLs the searches of the conversation listed
     */
    public static function fromRequestedUrls(array $requestedUrls, array $earlierSearchHitUrls): static
    {
        $urlCorrection = static::newInstance();
        $hitUrlsByCanonicalKey = [];
        $hitUrlsByFoldedKey = [];
        foreach ($earlierSearchHitUrls as $hitUrl) {
            $hitUrlsByCanonicalKey[WebPageMarkdown::canonicalUrlKey($hitUrl)] = $hitUrl;
            $hitUrlsByFoldedKey[static::buildFoldedUrlKey($hitUrl)][$hitUrl] = true;
        }
        foreach ($requestedUrls as $requestedUrl) {
            $requestedUrl = (string)$requestedUrl;
            if (isset($hitUrlsByCanonicalKey[WebPageMarkdown::canonicalUrlKey($requestedUrl)])) {
                $urlCorrection->urls[] = $requestedUrl;
                continue;
            }
            $matchingHitUrls = array_keys($hitUrlsByFoldedKey[static::buildFoldedUrlKey($requestedUrl)] ?? []);
            if (count($matchingHitUrls) === 1) {
                $urlCorrection->urls[] = (string)$matchingHitUrls[0];
                $urlCorrection->correctedUrls[] = (string)$matchingHitUrls[0];
                continue;
            }
            $urlCorrection->urls[] = $requestedUrl;
        }
        return $urlCorrection;
    }

    /** The note for the model when a URL was corrected; null otherwise. */
    public function buildNoteForModel(): ?string
    {
        if ($this->correctedUrls === []) {
            return null;
        }
        return 'NOTE: read the URL as the search listed it (' . implode(', ', $this->correctedUrls)
            . ') — copy URLs character for character, never decode, transliterate or shorten them.';
    }

    /** The URL without scheme, `www.`, fragment and trailing slash, percent-decoded, lower case, transliterated. */
    public static function buildFoldedUrlKey(string $url): string
    {
        $foldedKey = mb_strtolower(rawurldecode(WebPageMarkdown::canonicalUrlKey($url)));
        $foldedKey = strtr($foldedKey, self::TRANSLITERATIONS);
        if (class_exists(Normalizer::class)) {
            $decomposedKey = Normalizer::normalize($foldedKey, Normalizer::FORM_D);
            if (is_string($decomposedKey)) {
                $foldedKey = (string)preg_replace('/\p{Mn}+/u', '', $decomposedKey);
            }
        }
        return $foldedKey;
    }
}
