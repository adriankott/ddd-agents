<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Drives;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * One file or folder of a drive as the agent and a file card see it: facts only, never content and never a content URL.
 * The one link it may carry is {@see $openLink}: where the host's own screen shows the file.
 */
class AgentDriveFile extends ValueObject
{
    /** @var string The store that holds it */
    public string $storeName = '';

    /** @var string The store's reference, e.g. `drive/812`: stable across renames and moves */
    public string $reference = '';

    /** @var string The workspace path, e.g. `drivespaces/library/Brand/Logo.svg` */
    public string $path = '';

    public string $name = '';

    public bool $isFolder = false;

    /** @var int|null Size in bytes (files) */
    public ?int $sizeBytes = null;

    public ?string $contentType = null;

    public ?DateTime $modifiedAt = null;

    /** @var int|null The number of the current version */
    public ?int $versionNumber = null;

    /** @var int|null Entries of a folder */
    public ?int $childCount = null;

    /** @var string|null A short description or the version comment */
    public ?string $description = null;

    /** @var string[] Tags */
    public array $tags = [];

    /** @var string|null A state the agent must know, e.g. `pending scan`, `blocked`, `uncommitted` */
    public ?string $state = null;

    /**
     * @var string|null Where the host's admin screen shows this file or folder (a path of the host, e.g.
     *      `/admin/drive?space=12&path=%2FTemplates%2Fletter.typ&file=812`), set by the store when the host configured
     *      a link; null otherwise. Never a content URL and never another origin: {@see isSafeOpenLink()}
     */
    public ?string $openLink = null;

    /**
     * Sets the open link when it is a path of the host (`/…`, not `//…`) or an absolute http(s) URL; anything else
     * (`javascript:`, `data:`, a protocol-relative URL) leaves it null.
     * @param string|null $openLink
     * @return void
     */
    public function setOpenLinkIfSafe(?string $openLink): void
    {
        $this->openLink = $openLink !== null && static::isSafeOpenLink($openLink) ? $openLink : null;
    }

    /**
     * @param string $openLink
     * @return bool true for a host path (`/…`) or an absolute http(s) URL without control characters
     */
    public static function isSafeOpenLink(string $openLink): bool
    {
        if ($openLink === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $openLink) === 1) {
            return false;
        }
        if (str_starts_with($openLink, '/')) {
            return !str_starts_with($openLink, '//');
        }
        return preg_match('#^https?://[^/?\#]+#i', $openLink) === 1;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->storeName . ':' . $this->reference);
    }

    /** One line for the model: path, kind or size, date, version, state and reference */
    public function buildLlmLine(): string
    {
        $lineParts = [$this->path . ($this->isFolder ? '/' : '')];
        if ($this->isFolder) {
            $lineParts[] = $this->childCount !== null ? $this->childCount . ' entries' : 'folder';
        } elseif ($this->sizeBytes !== null) {
            $lineParts[] = static::formatSize($this->sizeBytes);
        }
        if ($this->modifiedAt !== null) {
            $lineParts[] = $this->modifiedAt->format('Y-m-d H:i');
        }
        if ($this->versionNumber !== null && !$this->isFolder) {
            $lineParts[] = 'v' . $this->versionNumber;
        }
        if ($this->state !== null && $this->state !== '') {
            $lineParts[] = $this->state;
        }
        if ($this->tags !== []) {
            $lineParts[] = '#' . implode(' #', $this->tags);
        }
        $lineParts[] = $this->reference;
        $line = implode(' · ', $lineParts);
        return $this->description !== null && $this->description !== '' ? $line . ' — ' . $this->description : $line;
    }

    public static function formatSize(int $sizeBytes): string
    {
        if ($sizeBytes < 1024) {
            return $sizeBytes . ' B';
        }
        if ($sizeBytes < 1024 * 1024) {
            return round($sizeBytes / 1024, 1) . ' KB';
        }
        if ($sizeBytes < 1024 * 1024 * 1024) {
            return round($sizeBytes / (1024 * 1024), 1) . ' MB';
        }
        return round($sizeBytes / (1024 * 1024 * 1024), 2) . ' GB';
    }
}
