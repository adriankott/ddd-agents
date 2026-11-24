<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\ClientTools;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The screen a reported UI state shows, as the application's screen catalog describes it
 * ({@see \DDD\Domain\AI\Services\ClientTools\AgentUIScreenCatalogService::describeScreen()}): the area's key and label
 * and the record on screen with the server's description of it, read under the caller's rights. Only these values
 * reach the state note; the client's words never do.
 */
class AgentUIScreen extends ValueObject
{
    /** @var string The key of the area in the catalog (what `openScreen` takes), e.g. `orders` */
    public string $areaKey = '';

    /** @var string|null The label of the area, e.g. `Orders` */
    public ?string $areaLabel = null;

    /** @var string|null The kind of the record on screen, e.g. `order` */
    public ?string $recordKind = null;

    /** @var string|null The id of the record on screen */
    public ?string $recordId = null;

    /** @var string|null The server's one-line description of the record, e.g. `"Spring delivery", shipped` */
    public ?string $recordDescription = null;

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->areaKey . '#' . ($this->recordId ?? ''));
    }
}
