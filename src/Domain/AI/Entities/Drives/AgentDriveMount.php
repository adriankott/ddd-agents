<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Drives;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One file space a conversation's agent reaches through a drive store: the agent space's own drive (paths without a
 * prefix) or a further space below `drivespaces/<mountName>/`. The mode decides what a write tool does: READ_ONLY
 * refuses, READ_WRITE writes, WRITE_WITH_CONSENT asks the owner first, naming the space and who sees it.
 */
class AgentDriveMount extends ValueObject
{
    public const string MODE_READ_ONLY = 'READ_ONLY';
    public const string MODE_READ_WRITE = 'READ_WRITE';
    public const string MODE_WRITE_WITH_CONSENT = 'WRITE_WITH_CONSENT';
    public const array MODES = [self::MODE_READ_ONLY, self::MODE_READ_WRITE, self::MODE_WRITE_WITH_CONSENT];

    /** @var string The folder below which mounted spaces appear in the workspace */
    public const string MOUNTED_SPACES_FOLDER = 'drivespaces';

    /** @var string The store that answers for this mount ({@see \DDD\Domain\AI\Services\Drives\AgentDriveStore::getStoreName()}) */
    public string $storeName = '';

    /** @var string The folder name below `drivespaces/` (e.g. `library`, `world-31`); '' for the own drive */
    public string $mountName = '';

    /** @var string The space's id in its store, as the store understands it */
    public string $spaceKey = '';

    /** @var string The space's name for people, e.g. `World "Sommer 2026"` */
    public string $title = '';

    /** @var string One of {@see self::MODES} */
    public string $mode = self::MODE_READ_ONLY;

    /** @var bool True for the agent space's own drive: its paths carry no `drivespaces/` prefix */
    public bool $isOwnDrive = false;

    /** @var bool True when people outside the staff see the space's files; a write then always needs consent */
    public bool $isOutwardVisible = false;

    /** @var string|null Who sees the space, for the consent question and the state line (e.g. "partner users of World 31") */
    public ?string $audienceDescription = null;

    /** @var int|null Files in the space, when the store keeps a counter */
    public ?int $fileCount = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->storeName . ':' . ($this->isOwnDrive ? '' : $this->mountName));
    }

    /** The workspace path prefix of the mount: '' for the own drive, `drivespaces/<mountName>/` otherwise */
    public function getPathPrefix(): string
    {
        return $this->isOwnDrive ? '' : self::MOUNTED_SPACES_FOLDER . '/' . $this->mountName . '/';
    }

    /** The mode a write tool applies: an outward-visible space is never written without consent */
    public function getEffectiveWriteMode(): string
    {
        if ($this->mode === self::MODE_READ_WRITE && $this->isOutwardVisible) {
            return self::MODE_WRITE_WITH_CONSENT;
        }
        return in_array($this->mode, self::MODES, true) ? $this->mode : self::MODE_READ_ONLY;
    }

    /** One line for the model's state note, e.g. `mount drivespaces/library · internal · rw · 2,310 files` */
    public function buildStateLine(): string
    {
        $modeText = match ($this->getEffectiveWriteMode()) {
            self::MODE_READ_WRITE => 'rw',
            self::MODE_WRITE_WITH_CONSENT => 'write with consent',
            default => 'read-only',
        };
        $lineParts = [
            $this->isOwnDrive ? 'drive ' . $this->title : 'mount ' . rtrim($this->getPathPrefix(), '/'),
            $this->isOutwardVisible ? ($this->audienceDescription ?? 'outward-visible') : 'internal',
            $modeText,
        ];
        if ($this->fileCount !== null) {
            $lineParts[] = number_format($this->fileCount) . ' files';
        }
        return implode(' · ', $lineParts);
    }
}
