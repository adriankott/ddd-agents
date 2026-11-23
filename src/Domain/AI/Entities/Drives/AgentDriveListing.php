<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Drives;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One page of a folder listing: the entries and the cursor of the next page (null on the last page).
 */
class AgentDriveListing extends ValueObject
{
    public ?AgentDriveMount $agentDriveMount = null;

    /** @var string The listed folder's workspace path */
    public string $path = '';

    public ?AgentDriveFiles $agentDriveFiles = null;

    /** @var string|null Pass it back to read the next page */
    public ?string $nextCursor = null;

    /** @var int|null Entries of the folder in total, when the store keeps a counter */
    public ?int $totalCount = null;
}
