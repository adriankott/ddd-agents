<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Images;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The settings of the store of tool-result pictures (`AI.toolCallImageStore`, `config/app/AI/toolCallImageStore.php`):
 * the three tiers, the wire profile a picture is normalized to once at capture, and the retention of pictures in the
 * context. Every tier is optional: L1 alone is the picture of one process, L1 + L2 the picture of the shared cache's
 * TTL, L3 the durable picture.
 *
 * - L1 `processCache`: a cache group local to the process (APCu), bounded by bytes written per TTL window.
 * - L2 `sharedCache`: a cache group the workers share (Redis, Valkey), each entry with a TTL, extended on a read.
 * - L3 `objectStore`: a Core object store; the key is `<keyPrefix><scope>/<sha256>`, the lifecycle is the host's.
 *
 * Read by {@see \DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallImageStoreService::getSettings()}; the legacy
 * key `AI.toolCallImageSpool` (0.3.7–0.3.11) is mapped onto these settings ({@see applyLegacySpoolSettings()}).
 *
 * @phpstan-consistent-constructor
 */
class AgentToolCallImageStoreSettings extends ValueObject
{
    public const string WIRE_FORMAT_WEBP = 'webp';

    public const string WIRE_FORMAT_JPEG = 'jpeg';

    /** @var string[] The formats a picture may be normalized to */
    public const array WIRE_FORMATS = [self::WIRE_FORMAT_WEBP, self::WIRE_FORMAT_JPEG];

    /** @var bool Whether pictures without a store of their own are stored at all (off = inline, one invocation) */
    public bool $enabled = true;

    /** @var bool Whether L1, the process cache, is used */
    public bool $processCacheEnabled = true;

    /** @var string|null The cache group of L1; null = Core's APCu group */
    public ?string $processCacheGroup = null;

    /** @var int The bytes this process writes into L1 per TTL window at most */
    public int $processCacheMaxBytes = 67_108_864;

    /** @var int The TTL of an L1 entry in seconds */
    public int $processCacheTtlSeconds = 600;

    /** @var bool Whether L2, the shared cache, is used */
    public bool $sharedCacheEnabled = true;

    /** @var string|null The cache group of L2; null = Core's default group (APCu unless the host changes it) */
    public ?string $sharedCacheGroup = null;

    /** @var int The TTL of an L2 entry in seconds */
    public int $sharedCacheTtlSeconds = 1800;

    /** @var bool Whether a read that finds a picture in L2 gives it the full TTL again */
    public bool $sharedCacheExtendsTtlOnRead = true;

    /** @var bool Whether L3, the object store, is used */
    public bool $objectStoreEnabled = true;

    /** @var string The name of the Core object store of L3 */
    public string $objectStorageName = 'agentToolImages';

    /** @var string The key prefix of the pictures in L3 */
    public string $objectKeyPrefix = 'agent-tool-images/';

    /** @var bool Whether a captured picture is normalized to the wire profile (off = stored as the tool returned it) */
    public bool $wireProfileEnabled = true;

    /** @var int The longest edge of a normalized picture in pixels */
    public int $wireMaxEdgePixels = 2048;

    /** @var float The most megapixels of a normalized picture */
    public float $wireMaxMegapixels = 3.75;

    /** @var string The format of a normalized picture ({@see WIRE_FORMATS}) */
    public string $wireFormat = self::WIRE_FORMAT_WEBP;

    /** @var int The encoder quality of a normalized picture (1–100) */
    public int $wireQuality = 85;

    /** @var bool Whether older pictures leave the context at a turn change (off = the picture cap decides alone) */
    public bool $retentionEnabled = true;

    /** @var int The picture-bearing results of earlier turns that keep their pictures (the newest ones) */
    public int $retentionNewestImageResults = 4;

    /** @var int The most pictures on one model call while the retention is on (the oldest results leave first) */
    public int $retentionMaxPicturesOnWire = 20;

    /**
     * The settings of a config node (`AI.toolCallImageStore`); a missing key keeps its default.
     *
     * @param mixed $configNode
     * @return static
     */
    public static function fromConfigNode(mixed $configNode): static
    {
        $settings = new static();
        $configNode = is_array($configNode) ? $configNode : [];
        $settings->enabled = static::readBool($configNode, 'enabled', $settings->enabled);
        $processCache = is_array($configNode['processCache'] ?? null) ? $configNode['processCache'] : [];
        $settings->processCacheEnabled = static::readBool($processCache, 'enabled', $settings->processCacheEnabled);
        $settings->processCacheGroup = static::readOptionalString($processCache, 'cacheGroup');
        $settings->processCacheMaxBytes = static::readPositiveInt($processCache, 'maxBytes', $settings->processCacheMaxBytes);
        $settings->processCacheTtlSeconds = static::readPositiveInt($processCache, 'ttlSeconds', $settings->processCacheTtlSeconds);
        $sharedCache = is_array($configNode['sharedCache'] ?? null) ? $configNode['sharedCache'] : [];
        $settings->sharedCacheEnabled = static::readBool($sharedCache, 'enabled', $settings->sharedCacheEnabled);
        $settings->sharedCacheGroup = static::readOptionalString($sharedCache, 'cacheGroup');
        $settings->sharedCacheTtlSeconds = static::readPositiveInt($sharedCache, 'ttlSeconds', $settings->sharedCacheTtlSeconds);
        $settings->sharedCacheExtendsTtlOnRead = static::readBool($sharedCache, 'extendTtlOnRead', $settings->sharedCacheExtendsTtlOnRead);
        $objectStore = is_array($configNode['objectStore'] ?? null) ? $configNode['objectStore'] : [];
        $settings->objectStoreEnabled = static::readBool($objectStore, 'enabled', $settings->objectStoreEnabled);
        $settings->objectStorageName = static::readOptionalString($objectStore, 'objectStorage') ?? $settings->objectStorageName;
        $settings->objectKeyPrefix = is_string($objectStore['keyPrefix'] ?? null) ? $objectStore['keyPrefix'] : $settings->objectKeyPrefix;
        $wireProfile = is_array($configNode['wireProfile'] ?? null) ? $configNode['wireProfile'] : [];
        $settings->wireProfileEnabled = static::readBool($wireProfile, 'enabled', $settings->wireProfileEnabled);
        $settings->wireMaxEdgePixels = static::readPositiveInt($wireProfile, 'maxEdgePixels', $settings->wireMaxEdgePixels);
        $settings->wireMaxMegapixels = is_numeric($wireProfile['maxMegapixels'] ?? null) && (float)$wireProfile['maxMegapixels'] > 0
            ? (float)$wireProfile['maxMegapixels'] : $settings->wireMaxMegapixels;
        $wireFormat = strtolower((string)($wireProfile['format'] ?? $settings->wireFormat));
        $wireFormat = $wireFormat === 'jpg' ? self::WIRE_FORMAT_JPEG : $wireFormat;
        $settings->wireFormat = in_array($wireFormat, self::WIRE_FORMATS, true) ? $wireFormat : self::WIRE_FORMAT_WEBP;
        $settings->wireQuality = max(1, min(100, static::readPositiveInt($wireProfile, 'quality', $settings->wireQuality)));
        $retention = is_array($configNode['retention'] ?? null) ? $configNode['retention'] : [];
        $settings->retentionEnabled = static::readBool($retention, 'enabled', $settings->retentionEnabled);
        $settings->retentionNewestImageResults = is_int($retention['newestImageResults'] ?? null) && $retention['newestImageResults'] >= 0
            ? $retention['newestImageResults'] : $settings->retentionNewestImageResults;
        $settings->retentionMaxPicturesOnWire = static::readPositiveInt($retention, 'maxPicturesOnWire', $settings->retentionMaxPicturesOnWire);
        return $settings;
    }

    /**
     * The legacy spool node (`AI.toolCallImageSpool` of 0.3.7–0.3.11) onto these settings: `enabled`, the object store
     * and its key prefix, the cache group and its TTL (as L2).
     *
     * @param array<string, mixed> $legacySpoolNode
     * @return void
     */
    public function applyLegacySpoolSettings(array $legacySpoolNode): void
    {
        $this->enabled = static::readBool($legacySpoolNode, 'enabled', $this->enabled);
        $this->objectStorageName = static::readOptionalString($legacySpoolNode, 'objectStorage') ?? $this->objectStorageName;
        if (is_string($legacySpoolNode['objectKeyPrefix'] ?? null)) {
            $this->objectKeyPrefix = $legacySpoolNode['objectKeyPrefix'];
        }
        $this->sharedCacheGroup = static::readOptionalString($legacySpoolNode, 'cacheGroup') ?? $this->sharedCacheGroup;
        $this->sharedCacheTtlSeconds = static::readPositiveInt($legacySpoolNode, 'cacheTtlSeconds', $this->sharedCacheTtlSeconds);
    }

    /** The largest pixel count of a normalized picture. */
    public function getWireMaxPixels(): int
    {
        return (int)floor($this->wireMaxMegapixels * 1_000_000);
    }

    /** The MIME type of the wire format. */
    public function getWireMimeType(): string
    {
        return $this->wireFormat === self::WIRE_FORMAT_JPEG ? 'image/jpeg' : 'image/webp';
    }

    /** @param array<string, mixed> $node */
    protected static function readBool(array $node, string $key, bool $default): bool
    {
        return is_bool($node[$key] ?? null) ? $node[$key] : $default;
    }

    /** @param array<string, mixed> $node */
    protected static function readPositiveInt(array $node, string $key, int $default): int
    {
        return is_int($node[$key] ?? null) && $node[$key] > 0 ? $node[$key] : $default;
    }

    /** @param array<string, mixed> $node */
    protected static function readOptionalString(array $node, string $key): ?string
    {
        return is_string($node[$key] ?? null) && trim($node[$key]) !== '' ? trim($node[$key]) : null;
    }
}
