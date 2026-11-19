<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;

/** An application's message class with a column of its own, listed in its context load. */
class ApplicationMessageWithOwnColumnDouble extends AIConversationMessage
{
    /** @var string The application's own column */
    public const string APPLICATION_COLUMN = 'pageContextOfTheApplication';

    public const array CONTEXT_LOAD_COLUMNS = [...parent::CONTEXT_LOAD_COLUMNS, self::APPLICATION_COLUMN];
}
