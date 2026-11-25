<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\ClientTools;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A picture of the user's screen as the client captured it (`getCurrentScreen` with `format` picture), after the
 * server checked it as untrusted client bytes: the type from the magic bytes, the size measured by Core's pinned
 * decoder, within {@see self::MAX_PIXELS} and {@see self::MAX_BYTES}. The tool hands it to the tool picture store.
 */
class AgentScreenPicture extends ValueObject
{
    /** @var int The most pixels a screen picture may have (the largest size preset) */
    public const int MAX_PIXELS = 4_000_000;

    /** @var int The most bytes a screen picture may have (the largest size preset's byte budget) */
    public const int MAX_BYTES = 614_400;

    /** @var string[] The types a client may send */
    public const array ALLOWED_MIME_TYPES = ['image/webp', 'image/jpeg', 'image/png'];

    /** @var string The bytes, base64 */
    public string $base64Data;

    /** @var string The type the magic bytes name */
    public string $mimeType;

    public int $width = 0;

    public int $height = 0;

    public int $byteSize = 0;

    /**
     * The form the shared cache keeps between the answer route and the waiting tool.
     *
     * @return array<string, string|int>
     */
    public function toCacheEntry(): array
    {
        return ['base64Data' => $this->base64Data, 'mimeType' => $this->mimeType, 'width' => $this->width, 'height' => $this->height, 'byteSize' => $this->byteSize];
    }

    public static function fromCacheEntry(mixed $cacheEntry): ?static
    {
        if (!is_array($cacheEntry) || !is_string($cacheEntry['base64Data'] ?? null) || !is_string($cacheEntry['mimeType'] ?? null)) {
            return null;
        }
        $agentScreenPicture = new static();
        $agentScreenPicture->base64Data = $cacheEntry['base64Data'];
        $agentScreenPicture->mimeType = $cacheEntry['mimeType'];
        $agentScreenPicture->width = (int)($cacheEntry['width'] ?? 0);
        $agentScreenPicture->height = (int)($cacheEntry['height'] ?? 0);
        $agentScreenPicture->byteSize = (int)($cacheEntry['byteSize'] ?? 0);
        return $agentScreenPicture;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic(hash('sha256', $this->base64Data));
    }
}
