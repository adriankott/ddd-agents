<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\Common\Exceptions;

use DDD\Infrastructure\Exceptions\NotFoundException;

/**
 * A reading tool of the admin agent was given an id that reads no record (it does not exist, or the operator's read
 * rights do not open it). A client error of the framework (404): the model reads the sentence as written and knows
 * the one next move, the find tool that turns a name into ids.
 *
 * @phpstan-consistent-constructor
 */
class AdminToolRecordNotFoundException extends NotFoundException
{
    /** @var string The sentence; pinned verbatim by AdminToolTextsTest */
    public const string RECORD_NOT_FOUND = 'No %s with id %s. Find it with %s.';

    /**
     * @param string $entityLabel e.g. "order"
     * @param int|string|null $entityId
     * @param string $findToolName e.g. "findOrders"
     * @return static
     */
    public static function forRecord(string $entityLabel, int|string|null $entityId, string $findToolName): static
    {
        return new static(sprintf(static::RECORD_NOT_FOUND, $entityLabel, $entityId ?? 'none', $findToolName));
    }
}
