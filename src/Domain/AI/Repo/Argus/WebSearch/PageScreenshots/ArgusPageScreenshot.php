<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\WebSearch\PageScreenshots;

use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Domain\AI\Entities\WebSearch\PageScreenshots\PageScreenshot;
use DDD\Domain\AI\Entities\WebSearch\PageScreenshots\PageScreenshotPart;
use DDD\Domain\AI\Repo\Argus\WebSearch\ArgusWebSearchConfiguredEndpointTrait;

/**
 * A URL rendered to a base64 PNG through the application's screenshot endpoint (`AI.webSearch.endpoints.screenshot`):
 * the URL and the viewport on the loader, the real page rendered opaquely with JavaScript, full page, waiting for
 * network idle. Answer: `{"status": "success", "content": "<base64>"}`; further fields (`finalUrl`, `width`, `height`,
 * `truncated`, …) are ignored. The bytes land on {@see PageScreenshot::$content}. A failure may name its reason
 * (`reason` at the top, under `data` or `error`, e.g. `BOT_CHALLENGE`, `TIMEOUT`): it lands on
 * {@see PageScreenshot::$failureReason}; a failure is never cached.
 *
 * Cached in memory only by default: a capture is megabytes of base64 and the endpoint usually caches it itself.
 * `AI.webSearch.cacheInDatabase.screenshot: true` adds the database cache.
 *
 * @method PageScreenshot|null toEntity()
 */
#[ArgusLoad(
    cacheLevel: ArgusCache::CACHELEVEL_MEMORY,
    cacheTtl: ArgusCache::CACHE_TTL_TEN_MINUTES // a live page changes; a short lifetime keeps repeat views cheap
)]
class ArgusPageScreenshot extends PageScreenshot
{
    use ArgusTrait, ArgusWebSearchConfiguredEndpointTrait;

    /** The capability of this repository: its endpoint, cache lifetime and timeout keys under `AI.webSearch`. */
    public const string WEB_SEARCH_CAPABILITY = 'screenshot';
    protected const string RESPONSE_TYPE_BASE64 = 'base64';

    /** @var string|null The URL to render and capture. */
    public ?string $url = null;

    /** @var int Browser viewport width in px (native capture width). */
    public int $viewportWidth = 1024;

    /** @var int Browser viewport height in px (the visible fold height; full-page capture extends below it). */
    public int $viewportHeight = 1400;

    /** @var bool Capture the whole scrollable page (true) or just the viewport fold (false). */
    public bool $forceFullPage = true;

    /** @var bool Emulate a mobile device viewport. */
    public bool $isMobile = false;

    /** @var int Page-load timeout in seconds. */
    public int $domTimeout = 30;

    /** @var int Extra seconds to wait after load before capturing (lets late JS settle). */
    public int $minWait = 2;

    /** @var string Output image format. */
    public string $format = 'png';

    /**
     * Stores the base64 image on success. Otherwise the reason the answer or the rejected call names lands on
     * {@see PageScreenshot::$failureReason} and nothing is cached (the caller reads a null
     * {@see PageScreenshot::getImageBlob()} as "no screenshot").
     */
    public function handleLoadResponse(
        mixed &$callResponseData = null,
        ?ArgusApiOperation &$apiOperation = null,
    ): void {
        if (!(is_object($callResponseData) && ($callResponseData->status ?? null) === 'success' && is_string($callResponseData->content ?? null) && $callResponseData->content !== '')) {
            $this->content = null;
            $this->failureReason = static::findFailureReasonOfAnswer($callResponseData, $apiOperation);
            $this->postProcessLoadResponse($callResponseData, false, cacheFailure: false);
            return;
        }
        $this->content = $callResponseData->content;
        $this->failureReason = null;
        $this->postProcessLoadResponse($callResponseData);
    }

    /**
     * The failure code of a screenshot answer: `reason` at the top, under `data` or under `error` (also an `error`
     * string that is a code), upper-cased; for a rejected call without an answer TIMEOUT when its reason says so, else
     * FETCH_FAILED; null when nothing names a reason.
     */
    public static function findFailureReasonOfAnswer(mixed $callResponseData, ?ArgusApiOperation $apiOperation = null): ?string
    {
        $reasonCandidates = [];
        if (is_object($callResponseData)) {
            $reasonCandidates[] = $callResponseData->reason ?? null;
            $reasonCandidates[] = is_object($callResponseData->data ?? null) ? ($callResponseData->data->reason ?? null) : null;
            $upstreamError = $callResponseData->error ?? null;
            $reasonCandidates[] = is_object($upstreamError) ? ($upstreamError->reason ?? $upstreamError->code ?? null) : $upstreamError;
        }
        foreach ($reasonCandidates as $reasonCandidate) {
            if (is_string($reasonCandidate) && preg_match('/^[A-Za-z][A-Za-z0-9_]{1,40}$/', trim($reasonCandidate)) === 1) {
                return strtoupper(trim($reasonCandidate));
            }
        }
        $rejectionReason = $apiOperation !== null ? trim((string)($apiOperation->rejectionReason ?? '')) : '';
        if ($rejectionReason !== '') {
            return stripos($rejectionReason, 'timed out') !== false || stripos($rejectionReason, 'timeout') !== false
                ? PageScreenshotPart::REASON_TIMEOUT
                : PageScreenshotPart::REASON_FETCH_FAILED;
        }
        return null;
    }

    /**
     * The capture is identified by WHAT it renders (url, viewport width and height, mobile emulation, full page), so
     * the cache is per page and viewport, never shared across urls.
     */
    public function uniqueKey(): string
    {
        // the viewport HEIGHT belongs to the identity too: a page that blocks scrolling renders only its
        // viewport, and the tall-window retry must not be answered by the cached short capture
        return self::uniqueKeyStatic(md5(($this->url ?? '') . '|' . $this->viewportWidth . 'x' . $this->viewportHeight . '|' . ($this->isMobile ? 'm' : 'd') . '|' . ($this->forceFullPage ? 'full' : 'fold')));
    }

    /**
     * The request body, built from this loader's url and viewport. Null when no url is set, so the load stops instead of
     * calling the endpoint with an empty target.
     *
     * @return array{body: array<string, mixed>}|null
     */
    protected function getLoadPayload(): ?array
    {
        if ($this->url === null || $this->url === '') {
            return null;
        }
        return [
            'body' => [
                'url' => $this->url,
                'responseType' => self::RESPONSE_TYPE_BASE64,
                'viewport_width' => $this->viewportWidth,
                'viewport_height' => $this->viewportHeight,
                'width' => $this->viewportWidth,
                'height' => $this->viewportHeight,
                'density' => 1,
                'type' => $this->format,
                'javascript_enabled' => true,
                'redirect_enabled' => true,
                'dom_timeout' => $this->domTimeout,
                'min_wait' => $this->minWait,
                'is_mobile' => $this->isMobile,
                'addPoweredByHeader' => false,
                'force_full_page' => $this->forceFullPage,
                'emulate_full_page' => true,
                'wait_until' => 'networkidle0',
                'omit_background' => false, // opaque — a quality/description model needs the REAL rendered background
            ],
        ];
    }
}
