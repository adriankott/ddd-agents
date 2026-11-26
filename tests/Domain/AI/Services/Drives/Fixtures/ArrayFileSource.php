<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Drives\Fixtures;

use DDD\Domain\Common\Entities\Files\Sources\FileAudience;
use DDD\Domain\Common\Entities\Files\Sources\FileContent;
use DDD\Domain\Common\Entities\Files\Sources\FileFacts;
use DDD\Domain\Common\Entities\Files\Sources\FileFactsSet;
use DDD\Domain\Common\Entities\Files\Sources\FileFamily;
use DDD\Domain\Common\Entities\Files\Sources\FileReference;
use DDD\Domain\Common\Services\Files\FileSource;
use DDD\Infrastructure\Exceptions\NotFoundException;

/** A file source over bytes the test sets; an id it does not hold is a file the caller may not read */
class ArrayFileSource implements FileSource
{
    /** @var FileFacts[] */
    public array $fileFactsById = [];

    /** @var string[] */
    public array $contentById = [];

    /** @var int How often bytes were read */
    public int $readCount = 0;

    public function __construct(public string $sourceName)
    {
    }

    public function addFile(string $id, string $name, string $contentType, string $content, string $audience = FileAudience::INTERNAL, bool $isContentUntrusted = false): FileFacts
    {
        $fileFacts = new FileFacts();
        $fileFacts->fileReference = new FileReference($this->sourceName, $id);
        $fileFacts->name = $name;
        $fileFacts->contentType = $contentType;
        $fileFacts->family = FileFamily::fromContentType($contentType, $name);
        $fileFacts->sizeBytes = strlen($content);
        $fileFacts->audience = $audience;
        $fileFacts->isContentUntrusted = $isContentUntrusted;
        $fileFacts->containerLabel = 'Media library';
        $fileFacts->setOpenLinkIfSafe('/admin/media/' . $id);
        $this->fileFactsById[$id] = $fileFacts;
        $this->contentById[$id] = $content;
        return $fileFacts;
    }

    public function getSourceName(): string
    {
        return $this->sourceName;
    }

    public function findFilesFacts(array $ids): FileFactsSet
    {
        $fileFactsSet = new FileFactsSet();
        foreach ($ids as $id) {
            $fileFacts = $this->fileFactsById[$id] ?? null;
            if ($fileFacts !== null) {
                $fileFactsSet->add($fileFacts);
            }
        }
        return $fileFactsSet;
    }

    public function readFileContent(string $id, int $maxBytes): FileContent
    {
        if (!isset($this->fileFactsById[$id])) {
            throw new NotFoundException($this->sourceName . '/' . $id . ' was not found.');
        }
        $this->readCount++;
        return FileContent::fromFactsAndContent($this->fileFactsById[$id], $this->contentById[$id]);
    }
}
