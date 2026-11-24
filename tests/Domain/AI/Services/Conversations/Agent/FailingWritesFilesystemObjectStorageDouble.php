<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent;

use DDD\Infrastructure\ObjectStorage\FilesystemObjectStorage;
use RuntimeException;

/** A filesystem object store whose writes fail while {@see $writesFail} is set (an unreachable bucket). */
class FailingWritesFilesystemObjectStorageDouble extends FilesystemObjectStorage
{
    public bool $writesFail = false;

    /** @var string[] The keys written */
    public array $writtenKeys = [];

    public function putObject(string $key, string $content, ?string $contentType = null): void
    {
        if ($this->writesFail) {
            throw new RuntimeException('object store unreachable');
        }
        $this->writtenKeys[] = $key;
        parent::putObject($key, $content, $contentType);
    }
}
