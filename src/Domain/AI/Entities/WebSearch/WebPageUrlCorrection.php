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

    /** @var string[] The page URLs the searches of the conversation listed. */
    public array $listedHitUrls = [];

    /** The failure reasons after which a URL no search listed is named as such, with the listed pages of its site. */
    public const array REASONS_OF_A_PAGE_THAT_MAY_NOT_EXIST = [WebPageMarkdown::REASON_FETCH_FAILED, 'NOT_FOUND'];

    /** How many listed pages of the site the note names at most. */
    public const int MAX_LISTED_PAGES_OF_THE_SITE_NAMED = 5;

    /**
     * @param string[] $requestedUrls
     * @param string[] $earlierSearchHitUrls the page URLs the searches of the conversation listed
     */
    public static function fromRequestedUrls(array $requestedUrls, array $earlierSearchHitUrls): static
    {
        $urlCorrection = static::newInstance();
        $urlCorrection->listedHitUrls = array_values(array_map('strval', $earlierSearchHitUrls));
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

    /**
     * For a page that failed as if it did not exist ({@see REASONS_OF_A_PAGE_THAT_MAY_NOT_EXIST}) and that no search
     * listed: the note that names the listed pages of the same site, so the model reads one of those instead of an
     * address it built itself. Null when the URL was listed, the read failed otherwise, or no page of its site was
     * listed.
     */
    public function buildUnlistedFailedUrlNote(WebPageMarkdown $failedWebPageMarkdown): ?string
    {
        $failedUrl = (string)($failedWebPageMarkdown->url ?? '');
        if ($failedUrl === '' || ($failedWebPageMarkdown->ok ?? false)
            || !in_array($failedWebPageMarkdown->reason, static::REASONS_OF_A_PAGE_THAT_MAY_NOT_EXIST, true)
        ) {
            return null;
        }
        $failedCanonicalKey = WebPageMarkdown::canonicalUrlKey($failedUrl);
        $failedHost = static::findHostOfCanonicalKey($failedCanonicalKey);
        $listedPagesOfTheSite = [];
        foreach ($this->listedHitUrls as $listedHitUrl) {
            $listedCanonicalKey = WebPageMarkdown::canonicalUrlKey($listedHitUrl);
            if ($listedCanonicalKey === $failedCanonicalKey) {
                return null;
            }
            if ($failedHost !== '' && static::findHostOfCanonicalKey($listedCanonicalKey) === $failedHost
                && !in_array($listedHitUrl, $listedPagesOfTheSite, true)
            ) {
                $listedPagesOfTheSite[] = $listedHitUrl;
            }
        }
        if ($listedPagesOfTheSite === []) {
            return null;
        }
        return 'NOTE: ' . $failedUrl . ' was not in the search results — never build a URL yourself. Listed pages of this site: '
            . implode(', ', array_slice($listedPagesOfTheSite, 0, static::MAX_LISTED_PAGES_OF_THE_SITE_NAMED)) . '.';
    }

    /** The host part of a {@see WebPageMarkdown::canonicalUrlKey()}. */
    protected static function findHostOfCanonicalKey(string $canonicalKey): string
    {
        return strtolower((string)preg_replace('~[/?].*$~s', '', $canonicalKey));
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
