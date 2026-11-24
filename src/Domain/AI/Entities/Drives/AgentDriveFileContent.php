<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Drives;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The bytes of one drive file as its store read them through its own backend ({@see
 * \DDD\Domain\AI\Services\Drives\AgentDriveFileContentReader}): never a URL, always under the caller's rights and only
 * of a released version. The bytes are foreign: they are read as text or rendered, never executed.
 *
 * @phpstan-consistent-constructor
 */
class AgentDriveFileContent extends ValueObject
{
    /** @var string The file name (the last path segment) */
    public string $fileName = '';

    /** @var string|null The type the store keeps for the version (from its bytes); a hint, the reader checks the bytes */
    public ?string $contentType = null;

    /** @var int The size of the bytes */
    public int $sizeBytes = 0;

    /** @var string The sha256 of the bytes */
    public string $sha256 = '';

    /** @var string|null The reference of the file (`drive/<id>`) */
    public ?string $reference = null;

    /** @var string|null The file's path in the workspace (`drivespaces/<space>/…` or a path of the own drive) */
    public ?string $workspacePath = null;

    /** @var string The bytes — never serialized */
    protected string $bytes = '';

    /** The content of a file from its bytes; size and sha256 are taken from them. */
    public static function fromBytes(string $bytes, string $fileName, ?string $contentType = null): static
    {
        $agentDriveFileContent = new static();
        $agentDriveFileContent->bytes = $bytes;
        $agentDriveFileContent->fileName = $fileName;
        $agentDriveFileContent->contentType = $contentType;
        $agentDriveFileContent->sizeBytes = strlen($bytes);
        $agentDriveFileContent->sha256 = hash('sha256', $bytes);
        return $agentDriveFileContent;
    }

    public function getBytes(): string
    {
        return $this->bytes;
    }

    /** The name the model reads for the file: its workspace path, else its reference, else its name. */
    public function getDisplayName(): string
    {
        return $this->workspacePath ?? $this->reference ?? $this->fileName;
    }
}
