<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\CallTasks;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;

/** A conversation whose tail read answers the messages the test put there, and records how it was asked. */
class TailScriptedAIConversation extends AIConversation
{
    public ?AIConversationMessages $scriptedTailMessages = null;

    /** @var array<int, array{afterIndex: int, useEntityRegistrCache: bool}> */
    public array $tailReads = [];

    public function findRawTailMessagesInOrder(int $afterIndex, bool $useEntityRegistrCache = true): ?AIConversationMessages
    {
        $this->tailReads[] = ['afterIndex' => $afterIndex, 'useEntityRegistrCache' => $useEntityRegistrCache];

        return $this->scriptedTailMessages;
    }
}
