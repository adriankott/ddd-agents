<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\Images\AgentToolCallImageReadResult;
use DDD\Domain\AI\Entities\Conversations\Agent\Images\AgentToolCallImageStoreSettings;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallImageReference;
use DDD\Domain\AI\Entities\MCPPackages\ToolResult;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultImage;
use DDD\Infrastructure\Cache\Apc;
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\ObjectStorage\BasicObjectStorage;
use DDD\Infrastructure\ObjectStorage\ParallelReadableObjectStorage;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\IssuesLogService;
use DDD\Infrastructure\Services\ObjectStorageService;
use DDD\Infrastructure\Services\Service;
use DDD\Infrastructure\Untrusted\RasterImageDecoder;
use Imagick;
use Psr\Log\LogLevel;
use RuntimeException;
use Throwable;

/**
 * The store of tool-result pictures that have no store of their own (a browser viewport, a workspace image): a
 * picture is normalized ONCE when its call completes, written to the tiers, and every later model call re-reads the
 * SAME bytes, so a tool result stays byte-identical across worker invocations, turns and a resume.
 *
 * - Capture ({@see storeInlineToolResultImages()}): the full check (magic bytes, the pinned decoder, the pixel caps)
 *   and the wire profile (long edge, megapixels, WebP or JPEG, quality) run once; the sha256 is taken over the wire
 *   form, which is what the tiers hold and what the model gets. Written to L1 (process cache), L2 (shared cache, one
 *   pipeline) and L3 (object store `<keyPrefix><scope>/<sha256>`); the reference is durable only once L3 acknowledged.
 *   A failed L3 write leaves the picture usable through L2/L1 and writes an issues-log entry; no tier at all leaves it
 *   inline (one invocation). A picture never fails its call.
 * - Read ({@see readStoredToolCallImages()}): L1 hit → done; the L1 misses in ONE L2 read (MGET), the L2 misses from
 *   L3 in parallel; the found pictures are written back to L2 (one pipeline) and L1. A read checks only the sha256 and
 *   the header (magic bytes against the reference's type), never decodes again.
 *
 * Settings: `AI.toolCallImageStore` ({@see AgentToolCallImageStoreSettings}); the legacy key `AI.toolCallImageSpool`
 * is read too and reported once per process. A shared cache that is local to the process, and a missing object
 * store, are reported once per process in the issues log (warning); nothing here throws.
 */
class AgentToolCallImageStoreService extends Service
{
    public const string CONFIG_KEY = 'AI.toolCallImageStore';

    /** @var string The key of 0.3.7–0.3.11, read when a host still ships it */
    public const string LEGACY_CONFIG_KEY = 'AI.toolCallImageSpool';

    /** @var string The prefix of a picture's key in L1 and L2 (content-addressed: the sha256 follows) */
    public const string CACHE_KEY_PREFIX = 'agentToolImage.';

    public const string WARNING_LEGACY_CONFIG = 'legacyConfig';

    public const string WARNING_SHARED_CACHE_PROCESS_LOCAL = 'sharedCacheProcessLocal';

    public const string WARNING_OBJECT_STORE_MISSING = 'objectStoreMissing';

    /** @var string The folder of L3 for pictures a package store rendered from its own source: `sources/<store>/<sha256>` */
    public const string SOURCE_PICTURE_KEY_FOLDER = 'sources/';

    /** @var string[] The warnings this process already wrote (each once per process) */
    protected static array $reportedWarnings = [];

    /** @var float When the current L1 byte window of this process started (microtime) */
    protected static float $processCacheWindowStartedAt = 0.0;

    /** @var int The bytes this process wrote into L1 in the current window */
    protected static int $processCacheBytesInWindow = 0;

    /**
     * The settings: `AI.toolCallImageStore`, with a host's legacy `AI.toolCallImageSpool` applied over it (reported
     * once per process).
     */
    public function getSettings(): AgentToolCallImageStoreSettings
    {
        $settings = AgentToolCallImageStoreSettings::fromConfigNode($this->readConfigNode(self::CONFIG_KEY));
        $legacySpoolNode = $this->readConfigNode(self::LEGACY_CONFIG_KEY);
        if (is_array($legacySpoolNode)) {
            $settings->applyLegacySpoolSettings($legacySpoolNode);
            $this->reportWarningOnce(
                self::WARNING_LEGACY_CONFIG,
                "The settings AI.toolCallImageSpool (config/app/AI/toolCallImageSpool.php) are read as AI.toolCallImageStore; "
                . 'move them to config/app/AI/toolCallImageStore.php (sharedCache.cacheGroup, objectStore.objectStorage, objectStore.keyPrefix).'
            );
        }
        return $settings;
    }

    /** A node of the configuration (null when it is not set). */
    protected function readConfigNode(string $configKey): mixed
    {
        return Config::get($configKey);
    }

    public function isStoreEnabled(): bool
    {
        return $this->getSettings()->enabled;
    }

    /**
     * Normalizes and stores the inline pictures of a completed call's result and points them at the store (their
     * references are built from them afterwards; the transient carry holds the same objects, so the call itself sends
     * the stored wire form). An error result, a picture that names a store of its own, a disabled store or a
     * conversation without an id change nothing; a picture the decoder refuses stays as the tool returned it, inline.
     */
    public function storeInlineToolResultImages(ToolResult $toolResult, AIConversation $conversation): void
    {
        if ($toolResult->isError || $toolResult->images === []) {
            return;
        }
        $settings = $this->getSettings();
        $scopeKey = $this->buildScopeKey($conversation);
        if (!$settings->enabled || $scopeKey === null) {
            return;
        }
        /** @var array<string, ToolResultImage> $imagesBySha256 */
        $imagesBySha256 = [];
        foreach ($toolResult->images as $toolResultImage) {
            if ($toolResultImage->sourceStore !== ToolCallImageReference::SOURCE_STORE_INLINE) {
                continue;
            }
            try {
                if (!$this->normalizeToWireProfile($toolResultImage, $settings)) {
                    continue;
                }
            } catch (Throwable) {
                continue; // a picture must never fail the tool call
            }
            $imagesBySha256[$toolResultImage->sha256] = $toolResultImage;
        }
        if ($imagesBySha256 === []) {
            return;
        }
        $base64TextsByCacheKey = [];
        foreach ($imagesBySha256 as $sha256 => $toolResultImage) {
            $base64TextsByCacheKey[$this->buildCacheKey((string)$sha256)] = $toolResultImage->base64Data;
        }
        $writtenToProcessCache = $this->writeToProcessCache($base64TextsByCacheKey, $settings);
        $writtenToSharedCache = $this->writeToSharedCache($base64TextsByCacheKey, $settings);
        $objectStorage = $this->findObjectStorage($settings);
        foreach ($imagesBySha256 as $sha256 => $toolResultImage) {
            $storeImageId = $scopeKey . '/' . $sha256;
            $writtenToObjectStore = false;
            if ($objectStorage !== null) {
                try {
                    // the raw picture with its type (before 0.5.0: base64 text as text/plain, still read)
                    $objectStorage->putObject($settings->objectKeyPrefix . $storeImageId, (string)base64_decode($toolResultImage->base64Data, true), $toolResultImage->mimeType);
                    $writtenToObjectStore = true;
                } catch (Throwable $objectStoreFailure) {
                    $this->logStoreFailure($objectStoreFailure, 'write', $storeImageId);
                }
            }
            if (!$writtenToProcessCache && !$writtenToSharedCache && !$writtenToObjectStore) {
                continue;
            }
            $toolResultImage->sourceStore = ToolCallImageReference::SOURCE_STORE_SPOOL;
            $toolResultImage->sourceImageId = $storeImageId;
            $toolResultImage->isDurable = $writtenToObjectStore;
        }
    }

    /**
     * The picture behind one store reference ({@see readStoredToolCallImages()}); null when it is gone or no longer
     * matches.
     */
    public function readStoredToolCallImage(ToolCallImageReference $toolCallImageReference): ?ToolResultImage
    {
        return $this->readStoredToolCallImages([$toolCallImageReference])->findImageOfSha256($toolCallImageReference->sha256);
    }

    /**
     * The pictures behind store references: L1, then the L1 misses in one L2 read, then the L2 misses from L3 in
     * parallel; what L2 or L3 delivered is written back to L1 (and from L3 to L2). References of another store are
     * skipped. Never throws.
     *
     * @param ToolCallImageReference[] $toolCallImageReferences
     */
    public function readStoredToolCallImages(array $toolCallImageReferences): AgentToolCallImageReadResult
    {
        $readStartedAt = hrtime(true);
        $agentToolCallImageReadResult = new AgentToolCallImageReadResult();
        /** @var array<string, ToolCallImageReference> $pendingReferencesBySha256 */
        $pendingReferencesBySha256 = [];
        foreach ($toolCallImageReferences as $toolCallImageReference) {
            if ($toolCallImageReference->sourceStore === ToolCallImageReference::SOURCE_STORE_SPOOL
                && $toolCallImageReference->sourceImageId !== '' && $toolCallImageReference->sha256 !== ''
            ) {
                $pendingReferencesBySha256[$toolCallImageReference->sha256] = $toolCallImageReference;
            }
        }
        if ($pendingReferencesBySha256 === []) {
            return $agentToolCallImageReadResult;
        }
        $settings = $this->getSettings();
        // L1
        foreach ($this->readFromCache($this->getProcessCache($settings), array_keys($pendingReferencesBySha256)) as $sha256 => $base64Data) {
            $toolResultImage = $this->buildImageFromStoredBase64($pendingReferencesBySha256[$sha256], $base64Data);
            if ($toolResultImage !== null) {
                $agentToolCallImageReadResult->addImage($toolResultImage);
                $agentToolCallImageReadResult->processCacheHits++;
                unset($pendingReferencesBySha256[$sha256]);
            }
        }
        /** @var array<string, string> $backfillBase64TextsByCacheKey */
        $backfillBase64TextsByCacheKey = [];
        // L2: one read for every L1 miss
        $sharedCache = $pendingReferencesBySha256 !== [] ? $this->getSharedCache($settings) : null;
        $sharedCacheHitKeys = [];
        foreach ($this->readFromCache($sharedCache, array_keys($pendingReferencesBySha256)) as $sha256 => $base64Data) {
            $toolResultImage = $this->buildImageFromStoredBase64($pendingReferencesBySha256[$sha256], $base64Data);
            if ($toolResultImage !== null) {
                $agentToolCallImageReadResult->addImage($toolResultImage);
                $agentToolCallImageReadResult->sharedCacheHits++;
                $sharedCacheHitKeys[] = $this->buildCacheKey((string)$sha256);
                $backfillBase64TextsByCacheKey[$this->buildCacheKey((string)$sha256)] = $base64Data;
                unset($pendingReferencesBySha256[$sha256]);
            }
        }
        if ($sharedCache !== null && $sharedCacheHitKeys !== [] && $settings->sharedCacheExtendsTtlOnRead) {
            try {
                $sharedCache->extendTtlOfKeys($sharedCacheHitKeys, $settings->sharedCacheTtlSeconds);
            } catch (Throwable) {
                // the entry keeps its TTL; L3 still holds the picture
            }
        }
        // L3: every L2 miss at once
        $objectStorage = $pendingReferencesBySha256 !== [] ? $this->findObjectStorage($settings) : null;
        $objectStoreBackfillBase64TextsByCacheKey = [];
        if ($objectStorage !== null) {
            $sha256sByObjectKey = [];
            foreach ($pendingReferencesBySha256 as $sha256 => $toolCallImageReference) {
                $sha256sByObjectKey[$settings->objectKeyPrefix . $toolCallImageReference->sourceImageId] = (string)$sha256;
            }
            foreach ($this->readFromObjectStorage($objectStorage, array_keys($sha256sByObjectKey)) as $objectKey => $objectContent) {
                $sha256 = $sha256sByObjectKey[$objectKey] ?? null;
                $base64Data = $sha256 !== null && is_string($objectContent) ? static::convertStoredObjectToBase64($objectContent, $sha256) : null;
                $toolResultImage = $sha256 !== null && is_string($base64Data) ? $this->buildImageFromStoredBase64($pendingReferencesBySha256[$sha256], $base64Data) : null;
                if ($toolResultImage !== null) {
                    $agentToolCallImageReadResult->addImage($toolResultImage);
                    $agentToolCallImageReadResult->objectStoreHits++;
                    $objectStoreBackfillBase64TextsByCacheKey[$this->buildCacheKey($sha256)] = $base64Data;
                    unset($pendingReferencesBySha256[$sha256]);
                }
            }
        }
        $agentToolCallImageReadResult->misses = count($pendingReferencesBySha256);
        $this->writeToSharedCache($objectStoreBackfillBase64TextsByCacheKey, $settings);
        $this->writeToProcessCache($backfillBase64TextsByCacheKey + $objectStoreBackfillBase64TextsByCacheKey, $settings);
        $agentToolCallImageReadResult->readMilliseconds = (hrtime(true) - $readStartedAt) / 1_000_000;
        return $agentToolCallImageReadResult;
    }

    /**
     * Keeps a picture a package's own store ({@see \DDD\Domain\AI\Entities\MCPPackages\ToolCallImageStore}) rendered
     * from its source (a file's picture for the model), so the next model call does not render it again: L1, L2, and
     * L3 under `sources/<store>/<sha256>` unless `$isDurableCopyAllowed` is false (a picture that must not lie in a
     * durable store beside its source). The picture is brought to the wire profile first; it must name its store.
     * Sets and answers whether L3 acknowledged the bytes. Never throws; with the store off nothing is kept.
     */
    public function writeSourcePicture(ToolResultImage $toolResultImage, bool $isDurableCopyAllowed = true): bool
    {
        $toolResultImage->isDurable = false;
        $settings = $this->getSettings();
        if (!$settings->enabled || !$this->isSourceStoreName($toolResultImage->sourceStore)) {
            return false;
        }
        try {
            if (!$this->normalizeToWireProfile($toolResultImage, $settings)) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }
        $base64TextsByCacheKey = [$this->buildCacheKey($toolResultImage->sha256) => $toolResultImage->base64Data];
        $this->writeToProcessCache($base64TextsByCacheKey, $settings);
        $this->writeToSharedCache($base64TextsByCacheKey, $settings);
        $objectStorage = $isDurableCopyAllowed ? $this->findObjectStorage($settings) : null;
        if ($objectStorage === null) {
            return false;
        }
        $objectKey = $settings->objectKeyPrefix . $this->buildSourcePictureStoreId($toolResultImage->sourceStore, $toolResultImage->sha256);
        try {
            $objectStorage->putObject($objectKey, (string)base64_decode($toolResultImage->base64Data, true), $toolResultImage->mimeType);
            $toolResultImage->isDurable = true;
        } catch (Throwable $objectStoreFailure) {
            $this->logStoreFailure($objectStoreFailure, 'write', $objectKey);
        }
        return $toolResultImage->isDurable;
    }

    /**
     * The kept picture of a package store's reference ({@see writeSourcePicture()}): L1, then L2, then — for a
     * picture L3 acknowledged, and unless `$isDurableCopyAllowed` is false — L3; what L2 or L3 delivered is written
     * back to the tiers above. Null on a miss: the store then renders the picture from its source again. The caller
     * checks the rights on the source BEFORE it asks: the tiers are keyed by the bytes and know no caller.
     */
    public function readSourcePicture(ToolCallImageReference $toolCallImageReference, bool $isDurableCopyAllowed = true): ?ToolResultImage
    {
        $settings = $this->getSettings();
        $sha256 = $toolCallImageReference->sha256;
        if (!$settings->enabled || $sha256 === '' || !$this->isSourceStoreName($toolCallImageReference->sourceStore)) {
            return null;
        }
        $cacheKey = $this->buildCacheKey($sha256);
        $toolResultImage = $this->buildSourceImageFromStoredBase64($toolCallImageReference, $this->readFromCache($this->getProcessCache($settings), [$sha256])[$sha256] ?? null);
        if ($toolResultImage !== null) {
            return $toolResultImage;
        }
        $sharedCache = $this->getSharedCache($settings);
        $toolResultImage = $this->buildSourceImageFromStoredBase64($toolCallImageReference, $this->readFromCache($sharedCache, [$sha256])[$sha256] ?? null);
        if ($toolResultImage !== null) {
            if ($sharedCache !== null && $settings->sharedCacheExtendsTtlOnRead) {
                try {
                    $sharedCache->extendTtlOfKeys([$cacheKey], $settings->sharedCacheTtlSeconds);
                } catch (Throwable) {
                    // the entry keeps its TTL; the source still holds the picture
                }
            }
            $this->writeToProcessCache([$cacheKey => $toolResultImage->base64Data], $settings);
            return $toolResultImage;
        }
        $objectStorage = $isDurableCopyAllowed && $toolCallImageReference->isDurable ? $this->findObjectStorage($settings) : null;
        if ($objectStorage === null) {
            return null;
        }
        $objectKey = $settings->objectKeyPrefix . $this->buildSourcePictureStoreId($toolCallImageReference->sourceStore, $sha256);
        $objectContent = $this->readFromObjectStorage($objectStorage, [$objectKey])[$objectKey] ?? null;
        $toolResultImage = is_string($objectContent)
            ? $this->buildSourceImageFromStoredBase64($toolCallImageReference, static::convertStoredObjectToBase64($objectContent, $sha256))
            : null;
        if ($toolResultImage !== null) {
            $this->writeToSharedCache([$cacheKey => $toolResultImage->base64Data], $settings);
            $this->writeToProcessCache([$cacheKey => $toolResultImage->base64Data], $settings);
        }
        return $toolResultImage;
    }

    /** The id of a package store's picture in L3, below the object key prefix: `sources/<store>/<sha256>` */
    public function buildSourcePictureStoreId(string $storeName, string $sha256): string
    {
        return self::SOURCE_PICTURE_KEY_FOLDER . preg_replace('/[^A-Za-z0-9._-]/', '_', $storeName) . '/' . $sha256;
    }

    /** True for the name of a package's own picture store (neither inline nor the spool) */
    protected function isSourceStoreName(string $storeName): bool
    {
        return $storeName !== '' && $storeName !== ToolCallImageReference::SOURCE_STORE_INLINE && $storeName !== ToolCallImageReference::SOURCE_STORE_SPOOL;
    }

    /** A stored picture as the image of a package store's reference ({@see buildImageFromStoredBase64()}), naming that store */
    protected function buildSourceImageFromStoredBase64(ToolCallImageReference $toolCallImageReference, mixed $base64Data): ?ToolResultImage
    {
        $toolResultImage = $this->buildImageFromStoredBase64($toolCallImageReference, $base64Data);
        if ($toolResultImage !== null) {
            $toolResultImage->sourceStore = $toolCallImageReference->sourceStore;
        }
        return $toolResultImage;
    }

    /**
     * Brings a picture to the wire profile in place (bytes, type, size, sha256 of the wire form): a picture already
     * in a wire format (WebP, JPEG) within the limits keeps its bytes; any other is decoded through the pinned decoder,
     * scaled to the long edge and the megapixels, stripped and encoded. False when the decoder refuses it or the
     * result is no picture of the wire format; the picture then stays as it was.
     */
    public function normalizeToWireProfile(ToolResultImage $toolResultImage, AgentToolCallImageStoreSettings $settings): bool
    {
        $originalBytes = base64_decode($toolResultImage->base64Data, true);
        if ($originalBytes === false || $originalBytes === '') {
            return false;
        }
        try {
            $rasterImageInfo = RasterImageDecoder::probe($originalBytes);
        } catch (Throwable) {
            return false; // not a raster picture of the allow-list: it stays as the tool returned it
        }
        if ($rasterImageInfo->width <= 0 || $rasterImageInfo->height <= 0
            || $rasterImageInfo->width * $rasterImageInfo->height > ToolResultImage::MAX_PIXELS
        ) {
            return false;
        }
        $scaleFactor = min(
            1.0,
            $settings->wireMaxEdgePixels / max($rasterImageInfo->width, $rasterImageInfo->height),
            sqrt($settings->getWireMaxPixels() / ($rasterImageInfo->width * $rasterImageInfo->height))
        );
        $isWireFormat = in_array($rasterImageInfo->mimeType, ['image/webp', 'image/jpeg'], true);
        if (!$settings->wireProfileEnabled || ($scaleFactor >= 1.0 && $isWireFormat)) {
            $this->applyWireBytes($toolResultImage, $originalBytes, $rasterImageInfo->mimeType, $rasterImageInfo->width, $rasterImageInfo->height);
            return true;
        }
        try {
            $imagick = RasterImageDecoder::readBlob($originalBytes);
        } catch (Throwable) {
            return false;
        }
        try {
            if ($imagick->getNumberImages() > 1) {
                $imagick->setIteratorIndex(0);
            }
            if (method_exists($imagick, 'autoOrientImage')) {
                $imagick->autoOrientImage(); // imagick >= 3.6 (older builds keep the stored orientation)
            }
            if ($scaleFactor < 1.0) {
                $imagick->resizeImage(
                    max(1, (int)floor($rasterImageInfo->width * $scaleFactor)),
                    max(1, (int)floor($rasterImageInfo->height * $scaleFactor)),
                    Imagick::FILTER_LANCZOS,
                    1
                );
            }
            $imagick->stripImage();
            if ($settings->wireFormat === AgentToolCallImageStoreSettings::WIRE_FORMAT_JPEG) {
                $imagick->setImageBackgroundColor('white');
                $flattenedImagick = $imagick->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                $imagick->clear();
                $imagick = $flattenedImagick;
                RasterImageDecoder::setOutputFormat($imagick, RasterImageDecoder::FORMAT_JPEG);
            } else {
                RasterImageDecoder::setOutputFormat($imagick, RasterImageDecoder::FORMAT_WEBP);
            }
            $imagick->setImageCompressionQuality($settings->wireQuality);
            $wireBytes = $imagick->getImageBlob();
            $wireWidth = $imagick->getImageWidth();
            $wireHeight = $imagick->getImageHeight();
        } finally {
            $imagick->clear();
        }
        if ($wireBytes === '' || strlen($wireBytes) > ToolResultImage::MAX_DECODED_BYTES
            || RasterImageDecoder::detectMimeType($wireBytes) !== $settings->getWireMimeType()
        ) {
            return false;
        }
        $this->applyWireBytes($toolResultImage, $wireBytes, $settings->getWireMimeType(), $wireWidth, $wireHeight);
        return true;
    }

    /** Whether a cache keeps its entries in this process only (APCu): another worker cannot read them. */
    public function isProcessLocalCache(Cache $cache): bool
    {
        return $cache instanceof Apc;
    }

    /**
     * The scope of a conversation's pictures in L3: its agent space, else its account, else the conversation; null
     * for a conversation that is not stored.
     */
    public function buildScopeKey(AIConversation $conversation): ?string
    {
        if (isset($conversation->agentSpaceId) && $conversation->agentSpaceId !== null) {
            return (string)$conversation->agentSpaceId;
        }
        if (isset($conversation->accountId) && $conversation->accountId !== null) {
            return 'account-' . $conversation->accountId;
        }
        return isset($conversation->id) ? 'conversation-' . $conversation->id : null;
    }

    public function buildCacheKey(string $sha256): string
    {
        return self::CACHE_KEY_PREFIX . $sha256;
    }

    protected function applyWireBytes(ToolResultImage $toolResultImage, string $wireBytes, string $mimeType, int $width, int $height): void
    {
        $toolResultImage->base64Data = base64_encode($wireBytes);
        $toolResultImage->mimeType = $mimeType;
        $toolResultImage->width = $width;
        $toolResultImage->height = $height;
        $toolResultImage->byteSize = strlen($wireBytes);
        $toolResultImage->sha256 = hash('sha256', $wireBytes);
    }

    /**
     * A stored picture as the image of its reference, without decoding it again: the sha256 of the bytes and the type
     * of their header must match the reference.
     */
    protected function buildImageFromStoredBase64(ToolCallImageReference $toolCallImageReference, mixed $base64Data): ?ToolResultImage
    {
        if (!is_string($base64Data) || $base64Data === '') {
            return null;
        }
        $storedBytes = base64_decode($base64Data, true);
        if ($storedBytes === false || hash('sha256', $storedBytes) !== $toolCallImageReference->sha256) {
            return null;
        }
        $headerMimeType = RasterImageDecoder::detectMimeType(substr($storedBytes, 0, RasterImageDecoder::HEADER_BYTES));
        if ($headerMimeType === null || $headerMimeType !== $toolCallImageReference->mimeType
            || !in_array($headerMimeType, ToolResultImage::ALLOWED_MIME_TYPES, true)
        ) {
            return null;
        }
        $toolResultImage = new ToolResultImage();
        $toolResultImage->base64Data = $base64Data;
        $toolResultImage->mimeType = $headerMimeType;
        $toolResultImage->width = $toolCallImageReference->width;
        $toolResultImage->height = $toolCallImageReference->height;
        $toolResultImage->byteSize = strlen($storedBytes);
        $toolResultImage->sha256 = $toolCallImageReference->sha256;
        $toolResultImage->sourceStore = ToolCallImageReference::SOURCE_STORE_SPOOL;
        $toolResultImage->sourceImageId = $toolCallImageReference->sourceImageId;
        $toolResultImage->isDurable = $toolCallImageReference->isDurable;
        return $toolResultImage;
    }

    /**
     * The base64 text of an object of L3: the raw picture (since 0.5.0) when its sha256 matches, else the object as it
     * is — a base64 text written before 0.5.0, which {@see buildImageFromStoredBase64()} checks against the reference.
     */
    public static function convertStoredObjectToBase64(string $objectContent, string $sha256): string
    {
        return hash('sha256', $objectContent) === $sha256 ? base64_encode($objectContent) : trim($objectContent);
    }

    /**
     * Erases the stored pictures behind tool call references (a purged conversation): the objects of L3 and the
     * entries of L2 and L1. A reference that names no store of this service is skipped; a picture stays readable
     * where another conversation of the same agent space showed the same bytes (one object per space and sha256) — it
     * is then a miss there and its placeholder stands in. Never throws; a failed delete is logged.
     *
     * @param ToolCallImageReference[] $toolCallImageReferences
     * @return int the objects deleted from L3
     */
    public function eraseStoredPicturesOfReferences(array $toolCallImageReferences): int
    {
        $settings = $this->getSettings();
        $storeImageIds = [];
        $cacheKeys = [];
        foreach ($toolCallImageReferences as $toolCallImageReference) {
            if (!$toolCallImageReference instanceof ToolCallImageReference || $toolCallImageReference->sourceImageId === '') {
                continue;
            }
            if ($this->isSourceStoreName($toolCallImageReference->sourceStore)) {
                // a picture a package store rendered from its source and kept here (writeSourcePicture)
                if ($toolCallImageReference->isDurable && $toolCallImageReference->sha256 !== '') {
                    $storeImageIds[$this->buildSourcePictureStoreId($toolCallImageReference->sourceStore, $toolCallImageReference->sha256)] = true;
                    $cacheKeys[$this->buildCacheKey($toolCallImageReference->sha256)] = true;
                }
                continue;
            }
            if ($toolCallImageReference->sourceStore !== ToolCallImageReference::SOURCE_STORE_SPOOL) {
                continue;
            }
            $storeImageIds[$toolCallImageReference->sourceImageId] = true;
            if ($toolCallImageReference->sha256 !== '') {
                $cacheKeys[$this->buildCacheKey($toolCallImageReference->sha256)] = true;
            }
        }
        if ($storeImageIds === []) {
            return 0;
        }
        foreach ([$this->getProcessCache($settings), $this->getSharedCache($settings)] as $cache) {
            if ($cache === null) {
                continue;
            }
            foreach (array_keys($cacheKeys) as $cacheKey) {
                try {
                    $cache->delete($cacheKey);
                } catch (Throwable) {
                    // a cache entry ends with its TTL anyway
                }
            }
        }
        $objectStorage = $this->findObjectStorage($settings);
        if ($objectStorage === null) {
            return 0;
        }
        $deletedObjects = 0;
        foreach (array_keys($storeImageIds) as $storeImageId) {
            try {
                $objectStorage->deleteObject($settings->objectKeyPrefix . $storeImageId);
                $deletedObjects++;
            } catch (Throwable $objectStoreFailure) {
                $this->logStoreFailure($objectStoreFailure, 'delete', (string)$storeImageId);
            }
        }
        return $deletedObjects;
    }

    /**
     * The values of a cache by sha256 (one batch read); empty without a cache or when the read fails.
     *
     * @param string[] $sha256s
     * @return array<string, mixed>
     */
    protected function readFromCache(?Cache $cache, array $sha256s): array
    {
        if ($cache === null || $sha256s === []) {
            return [];
        }
        $sha256sByCacheKey = [];
        foreach ($sha256s as $sha256) {
            $sha256sByCacheKey[$this->buildCacheKey((string)$sha256)] = (string)$sha256;
        }
        try {
            $valuesByCacheKey = $cache->getMultiple(array_keys($sha256sByCacheKey));
        } catch (Throwable) {
            return [];
        }
        $valuesBySha256 = [];
        foreach ($valuesByCacheKey as $cacheKey => $value) {
            if (isset($sha256sByCacheKey[$cacheKey])) {
                $valuesBySha256[$sha256sByCacheKey[$cacheKey]] = $value;
            }
        }
        return $valuesBySha256;
    }

    /**
     * Several objects of L3 at once (parallel when the store offers it), object key => the object's content (raw
     * bytes, or base64 text written before 0.5.0) or null; a failed read is a miss of every key and an issues-log entry.
     *
     * @param string[] $objectKeys
     * @return array<string, string|null>
     */
    protected function readFromObjectStorage(BasicObjectStorage $objectStorage, array $objectKeys): array
    {
        try {
            if ($objectStorage instanceof ParallelReadableObjectStorage) {
                return $objectStorage->getObjectContents($objectKeys);
            }
            $contentsByObjectKey = [];
            foreach ($objectKeys as $objectKey) {
                $contentsByObjectKey[$objectKey] = $objectStorage->getObjectContent($objectKey);
            }
            return $contentsByObjectKey;
        } catch (Throwable $objectStoreFailure) {
            $this->logStoreFailure($objectStoreFailure, 'read', implode(', ', $objectKeys));
            return [];
        }
    }

    /**
     * Writes base64 texts into L1, within the bytes this process may write per TTL window; false when nothing was
     * written (L1 off, full, or failing).
     *
     * @param array<string, string> $base64TextsByCacheKey
     */
    protected function writeToProcessCache(array $base64TextsByCacheKey, AgentToolCallImageStoreSettings $settings): bool
    {
        $processCache = $base64TextsByCacheKey !== [] ? $this->getProcessCache($settings) : null;
        if ($processCache === null) {
            return false;
        }
        if (microtime(true) - static::$processCacheWindowStartedAt > $settings->processCacheTtlSeconds) {
            static::$processCacheWindowStartedAt = microtime(true);
            static::$processCacheBytesInWindow = 0;
        }
        $admittedBase64TextsByCacheKey = [];
        foreach ($base64TextsByCacheKey as $cacheKey => $base64Data) {
            if (static::$processCacheBytesInWindow + strlen($base64Data) > $settings->processCacheMaxBytes) {
                continue;
            }
            static::$processCacheBytesInWindow += strlen($base64Data);
            $admittedBase64TextsByCacheKey[$cacheKey] = $base64Data;
        }
        if ($admittedBase64TextsByCacheKey === []) {
            return false;
        }
        try {
            $processCache->setMultiple($admittedBase64TextsByCacheKey, $settings->processCacheTtlSeconds);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Writes base64 texts into L2 in one pipeline; false when nothing was written (L2 off, process-local, failing).
     *
     * @param array<string, string> $base64TextsByCacheKey
     */
    protected function writeToSharedCache(array $base64TextsByCacheKey, AgentToolCallImageStoreSettings $settings): bool
    {
        $sharedCache = $base64TextsByCacheKey !== [] ? $this->getSharedCache($settings) : null;
        if ($sharedCache === null) {
            return false;
        }
        try {
            $sharedCache->setMultiple($base64TextsByCacheKey, $settings->sharedCacheTtlSeconds);
            return true;
        } catch (Throwable $sharedCacheFailure) {
            $this->logStoreFailure($sharedCacheFailure, 'shared cache write', implode(', ', array_keys($base64TextsByCacheKey)));
            return false;
        }
    }

    /** L1: the process cache group (Core's APCu group by default); null when off or not available. */
    protected function getProcessCache(AgentToolCallImageStoreSettings $settings): ?Cache
    {
        if (!$settings->processCacheEnabled) {
            return null;
        }
        try {
            return $this->resolveCacheGroup($settings->processCacheGroup ?? Cache::CACHE_GROUP_APC);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * L2: the shared cache group; null when off or not available, and null (reported once per process) when the
     * group is local to the process — L1 already is that cache.
     */
    protected function getSharedCache(AgentToolCallImageStoreSettings $settings): ?Cache
    {
        if (!$settings->sharedCacheEnabled) {
            return null;
        }
        try {
            $sharedCache = $this->resolveCacheGroup($settings->sharedCacheGroup);
        } catch (Throwable) {
            return null;
        }
        if ($this->isProcessLocalCache($sharedCache)) {
            $this->reportWarningOnce(
                self::WARNING_SHARED_CACHE_PROCESS_LOCAL,
                'The shared cache of the tool picture store (AI.toolCallImageStore sharedCache.cacheGroup) is local to the process ('
                . get_class($sharedCache) . '): another worker, the next turn and a resume cannot read its pictures. '
                . "Set sharedCache.cacheGroup to a shared group (e.g. 'redis') or configure the object store."
            );
            return null;
        }
        return $sharedCache;
    }

    /** L3: the configured object store; null when off, and null (reported once per process) when it is not configured. */
    protected function findObjectStorage(AgentToolCallImageStoreSettings $settings): ?BasicObjectStorage
    {
        if (!$settings->objectStoreEnabled) {
            return null;
        }
        try {
            $objectStorage = $this->resolveObjectStorage($settings->objectStorageName);
        } catch (Throwable $objectStoreFailure) {
            $this->logStoreFailure($objectStoreFailure, 'open', $settings->objectStorageName);
            return null;
        }
        if ($objectStorage === null) {
            $this->reportWarningOnce(
                self::WARNING_OBJECT_STORE_MISSING,
                "The object store '{$settings->objectStorageName}' of the tool picture store is not configured: pictures live only as long as "
                . 'the caches keep them (OBJECT_STORAGE_' . strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '_', $settings->objectStorageName))
                . '_*, or objectStore.enabled false).'
            );
        }
        return $objectStorage;
    }

    /** The Core cache of a group (null = Core's default group). */
    protected function resolveCacheGroup(?string $cacheGroup): ?Cache
    {
        return Cache::instance($cacheGroup);
    }

    /**
     * The Core object store of a name at the basic level (put, get, delete, exists — every driver offers it, a narrow
     * one of the application too); null when it is not configured.
     */
    protected function resolveObjectStorage(string $objectStorageName): ?BasicObjectStorage
    {
        /** @var ObjectStorageService $objectStorageService */
        $objectStorageService = DDDService::instance()->getService(ObjectStorageService::class);
        return $objectStorageService->getBasicObjectStorage($objectStorageName);
    }

    protected function logStoreFailure(Throwable $storeFailure, string $operation, string $subject): void
    {
        try {
            /** @var IssuesLogService $issuesLogService */
            $issuesLogService = DDDService::instance()->getService(IssuesLogService::class);
            $issuesLogService->logThrowable(
                new RuntimeException("The tool picture store could not {$operation} {$subject}: " . $storeFailure->getMessage(), 0, $storeFailure),
                LogLevel::WARNING
            );
        } catch (Throwable) {
            error_log("AgentToolCallImageStoreService: {$operation} {$subject} failed: " . $storeFailure->getMessage());
        }
    }

    /** Writes a warning into the issues log, once per process and kind. */
    protected function reportWarningOnce(string $warningKind, string $warningText): void
    {
        if (in_array($warningKind, static::$reportedWarnings, true)) {
            return;
        }
        static::$reportedWarnings[] = $warningKind;
        try {
            /** @var IssuesLogService $issuesLogService */
            $issuesLogService = DDDService::instance()->getService(IssuesLogService::class);
            $issuesLogService->logThrowable(new RuntimeException($warningText), LogLevel::WARNING, ['warningKind' => $warningKind]);
        } catch (Throwable) {
            error_log('AgentToolCallImageStoreService: ' . $warningText);
        }
    }
}
