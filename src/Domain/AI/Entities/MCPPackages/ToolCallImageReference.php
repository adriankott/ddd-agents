<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The PERSISTED, bytes-free pointer to an image a tool call produced — stored on the tool call's JSON column.
 * A separate type from {@see ToolResultImage} ON PURPOSE: one VO holding both bytes and the
 * reference is how bytes reach the database by accident. This is the only thing that lets a resumed worker, a
 * retry, or the admin view know what image was sent — the bytes are re-resolved per model call, never stored.
 */
class ToolCallImageReference extends ValueObject
{
    /** The bytes exist only in the process that captured them — never re-resolvable (a picture the spool could not take, or the spool is off). */
    public const string SOURCE_STORE_INLINE = 'inline';

    /**
     * The package's picture store of a picture that has no store of its own: the bytes (in the wire form) were written
     * when the call completed ({@see \DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallImageStoreService}),
     * the id is `<scope>/<sha256>` (0.3.7–0.3.11: `<conversation id>/<sha256>`), and every later model call re-reads
     * the same bytes through L1, L2 and L3.
     */
    public const string SOURCE_STORE_SPOOL = 'spool';

    /** @var int The length of the short image id ({@see getShortImageId()}) */
    public const int SHORT_IMAGE_ID_LENGTH = 12;

    /** @var string Where the bytes can be re-resolved: {@see SOURCE_STORE_INLINE}, or the name a {@see ToolCallImageStore} package declares. */
    public string $sourceStore = self::SOURCE_STORE_INLINE;

    /** @var string The id within {@see $sourceStore} (falls back to the sha256 for an inline image). */
    public string $sourceImageId = '';

    public string $mimeType = '';

    public int $width = 0;

    public int $height = 0;

    public int $byteSize = 0;

    /** @var string sha256 of the decoded bytes — the identity shown in the admin placeholder. */
    public string $sha256 = '';

    /** @var bool Whether the object store (L3) acknowledged the bytes: the picture outlives the caches' TTL */
    public bool $isDurable = false;

    /** @var string|null When the image was captured (ISO), for the trace. */
    public ?string $capturedAt = null;

    /** Whether a {@see ToolCallImageStore} can re-resolve this image's bytes (anything but the inline spool). */
    public function isReResolvable(): bool
    {
        return $this->sourceStore !== '' && $this->sourceStore !== self::SOURCE_STORE_INLINE && $this->sourceImageId !== '';
    }

    /**
     * The human-readable placeholder the admin forensic reconstruction renders at the image's wire position
     * — `[image <sha12>, WxH, N KB — bytes not retained]`. Bytes are never stored, so the view
     * shows what was sent from the persisted reference instead of re-resolving today's viewport. Pure.
     */
    public function toDisplayPlaceholder(): string
    {
        $shaPrefix = $this->sha256 !== '' ? substr($this->sha256, 0, 12) : 'unknown';
        $kiloBytes = (int)round($this->byteSize / 1024);
        return '[image ' . $shaPrefix . ', ' . $this->width . '×' . $this->height . ', ' . $kiloBytes . ' KB — bytes not retained]';
    }

    /** The first characters of the sha256 a placeholder names and the re-view tool accepts. */
    public function getShortImageId(): string
    {
        return substr($this->sha256, 0, self::SHORT_IMAGE_ID_LENGTH);
    }

    /**
     * The line that stands in a tool result for a picture that left the context at a turn change: what it was and how
     * to see it again (the re-view tool of the skill `Agent.Images`).
     */
    public function toRetiredFromContextPlaceholder(): string
    {
        return '[image ' . $this->getShortImageId() . ', ' . $this->width . '×' . $this->height
            . ' — no longer attached; to see it again call viewEarlierToolImage with imageId "' . $this->getShortImageId() . '" (skill Agent.Images)]';
    }
}
