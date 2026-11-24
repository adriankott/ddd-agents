<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallImageReference;
use DDD\Domain\AI\Entities\MCPPackages\ToolResult;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultImage;
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\ObjectStorage\ObjectStorage;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\ObjectStorageService;
use DDD\Infrastructure\Services\Service;
use DDD\Infrastructure\Untrusted\RasterImageDecoder;

/**
 * The durable spool of tool-result pictures that have no store of their own (a browser viewport, a workspace image).
 *
 * Without it such a picture lives only in the worker invocation that captured it: the next invocation finds the
 * reference, not the bytes, and the result's wire entry changes (the dropped marker replaces the picture). That one
 * change rewrites the history from that result on: the prompt cache is written again from there, and on the Messages
 * API the thinking after it is dropped. With the spool the call's pictures are written when it completes, its
 * references name {@see ToolCallImageReference::SOURCE_STORE_SPOOL}, and every later model call re-reads the SAME base64
 * text, so the frozen tool result stays byte-identical until it leaves on purpose (stub, clearing, the picture cap,
 * a compaction).
 *
 * Where the bytes go (`AI.toolCallImageSpool`, `config/app/AI/toolCallImageSpool.php`): the configured Core object store,
 * else the application cache with a TTL. A failed or impossible write leaves the picture inline (the single-turn
 * behaviour before); a failed read is a dropped picture. A spooled picture is checked again on every read: its hash
 * against the reference, its type from the magic bytes against the allow-list.
 */
class AgentToolCallImageSpoolService extends Service
{
    public const string CONFIG_KEY = 'AI.toolCallImageSpool';

    public const int DEFAULT_CACHE_TTL_SECONDS = 86400;

    public const int DEFAULT_MAX_CACHED_BYTES_PER_PICTURE = 2_097_152;

    public const string DEFAULT_OBJECT_KEY_PREFIX = 'agent-tool-images/';

    public const string CACHE_KEY_PREFIX = 'agentToolCallImageSpool.';

    /**
     * Writes the inline pictures of a completed call's result to the spool and points them at it (their references
     * are built from them afterwards). An error result, a result without pictures, a picture that already names a
     * store, a conversation without an id or a disabled spool change nothing; a failed write leaves that picture inline.
     */
    public function spoolInlineToolResultImages(ToolResult $toolResult, AIConversation $conversation): void
    {
        if ($toolResult->isError || $toolResult->images === [] || !$this->isSpoolEnabled()) {
            return;
        }
        $conversationId = isset($conversation->id) ? (string)$conversation->id : '';
        if ($conversationId === '') {
            return;
        }
        foreach ($toolResult->images as $toolResultImage) {
            if ($toolResultImage->sourceStore !== ToolCallImageReference::SOURCE_STORE_INLINE || $toolResultImage->sha256 === '') {
                continue;
            }
            $spoolImageId = $conversationId . '/' . $toolResultImage->sha256;
            try {
                $written = $this->writeSpooledPicture($spoolImageId, $toolResultImage);
            } catch (\Throwable) {
                $written = false; // a picture must never fail the tool call
            }
            if ($written) {
                $toolResultImage->sourceStore = ToolCallImageReference::SOURCE_STORE_SPOOL;
                $toolResultImage->sourceImageId = $spoolImageId;
            }
        }
    }

    /**
     * The spooled picture behind a reference, with the base64 text exactly as it was captured; null when the reference
     * is not a spool reference, the bytes are gone, or they no longer match the reference (hash, magic type).
     */
    public function resolveSpooledToolCallImage(ToolCallImageReference $toolCallImageReference): ?ToolResultImage
    {
        if ($toolCallImageReference->sourceStore !== ToolCallImageReference::SOURCE_STORE_SPOOL || $toolCallImageReference->sourceImageId === '') {
            return null;
        }
        try {
            $base64Data = $this->readSpooledPicture($toolCallImageReference->sourceImageId);
        } catch (\Throwable) {
            return null;
        }
        if ($base64Data === null || $base64Data === '') {
            return null;
        }
        $decodedBytes = base64_decode($base64Data, true);
        if ($decodedBytes === false || hash('sha256', $decodedBytes) !== $toolCallImageReference->sha256) {
            return null;
        }
        if (RasterImageDecoder::detectMimeType($decodedBytes) !== $toolCallImageReference->mimeType) {
            return null;
        }
        $decodedImage = ToolResultImage::decodeMcpImageContentPart([
            'type' => 'image',
            'data' => $base64Data,
            'mimeType' => $toolCallImageReference->mimeType,
            'sourceStore' => ToolCallImageReference::SOURCE_STORE_SPOOL,
            'sourceImageId' => $toolCallImageReference->sourceImageId,
        ]);
        return $decodedImage instanceof ToolResultImage ? $decodedImage : null;
    }

    public function isSpoolEnabled(): bool
    {
        return ($this->getSpoolSetting('enabled') ?? true) !== false;
    }

    /** Writes the base64 text of a picture under its spool id; false when no store takes it. */
    protected function writeSpooledPicture(string $spoolImageId, ToolResultImage $toolResultImage): bool
    {
        $objectStorage = $this->findSpoolObjectStorage();
        if ($objectStorage !== null) {
            $objectStorage->putObject($this->buildObjectKey($spoolImageId), $toolResultImage->base64Data, 'text/plain');
            return true;
        }
        if ($toolResultImage->byteSize > $this->getMaxCachedBytesPerPicture()) {
            return false;
        }
        Cache::instance()->set(self::CACHE_KEY_PREFIX . $spoolImageId, $toolResultImage->base64Data, $this->getCacheTtlSeconds());
        return true;
    }

    /** The base64 text stored under a spool id, null when it is gone. */
    protected function readSpooledPicture(string $spoolImageId): ?string
    {
        $objectStorage = $this->findSpoolObjectStorage();
        if ($objectStorage !== null) {
            return $objectStorage->getObjectContent($this->buildObjectKey($spoolImageId));
        }
        $cachedValue = Cache::instance()->get(self::CACHE_KEY_PREFIX . $spoolImageId);
        return is_string($cachedValue) ? $cachedValue : null;
    }

    protected function findSpoolObjectStorage(): ?ObjectStorage
    {
        $storeName = $this->getSpoolSetting('objectStorage');
        if (!is_string($storeName) || $storeName === '') {
            return null;
        }
        /** @var ObjectStorageService $objectStorageService */
        $objectStorageService = DDDService::instance()->getService(ObjectStorageService::class);
        return $objectStorageService->getObjectStorage($storeName);
    }

    protected function buildObjectKey(string $spoolImageId): string
    {
        $objectKeyPrefix = $this->getSpoolSetting('objectKeyPrefix');
        return (is_string($objectKeyPrefix) ? $objectKeyPrefix : self::DEFAULT_OBJECT_KEY_PREFIX) . $spoolImageId;
    }

    protected function getCacheTtlSeconds(): int
    {
        $cacheTtlSeconds = $this->getSpoolSetting('cacheTtlSeconds');
        return is_int($cacheTtlSeconds) && $cacheTtlSeconds > 0 ? $cacheTtlSeconds : self::DEFAULT_CACHE_TTL_SECONDS;
    }

    protected function getMaxCachedBytesPerPicture(): int
    {
        $maxCachedBytesPerPicture = $this->getSpoolSetting('maxCachedBytesPerPicture');
        return is_int($maxCachedBytesPerPicture) && $maxCachedBytesPerPicture > 0 ? $maxCachedBytesPerPicture : self::DEFAULT_MAX_CACHED_BYTES_PER_PICTURE;
    }

    protected function getSpoolSetting(string $settingName): mixed
    {
        $spoolSettings = Config::get(self::CONFIG_KEY);
        return is_array($spoolSettings) ? ($spoolSettings[$settingName] ?? null) : null;
    }
}
