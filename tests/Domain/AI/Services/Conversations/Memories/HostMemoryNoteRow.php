<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Memories;

use DDD\Domain\Base\Entities\ValueObject;

/** A memory row of a host's own entity, as a host store keeps it (not the package's table). */
class HostMemoryNoteRow extends ValueObject
{
    public int $noteId = 0;

    public int $ownerAccountId = 0;

    public ?int $workspaceId = null;

    public bool $isStandingRule = false;

    public string $noteText = '';

    public function __construct(int $noteId = 0, int $ownerAccountId = 0, ?int $workspaceId = null, bool $isStandingRule = false, string $noteText = '')
    {
        $this->noteId = $noteId;
        $this->ownerAccountId = $ownerAccountId;
        $this->workspaceId = $workspaceId;
        $this->isStandingRule = $isStandingRule;
        $this->noteText = $noteText;
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)$this->noteId);
    }
}
