<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Compaction\CompactionCharacterization;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;

/**
 * The two message reads of the compaction consistency report — every message in order and the raw tail after an
 * index — answered from the rows the test gives instead of the database, every call recorded with its arguments.
 * Without a given tail the tail answer is the rows with an index above the asked one (a database that agrees with
 * itself); a test gives `$tailMessages` to model a tail read that disagrees.
 */
class CompactionConsistencyMessagesServiceDouble extends AIConversationMessagesService
{
    use CompactionCharacterizationFixtures;

    /** @var AIConversationMessage[] the rows the whole-conversation read answers */
    public array $allMessages = [];

    /** @var AIConversationMessage[]|null the rows the tail read answers; null = every row of {@see $allMessages} past the asked index */
    public ?array $tailMessages = null;

    /** @var array<int, array{method: string, arguments: array<string, mixed>}> every call, in order */
    public array $calls = [];

    /** @return string[] the names of the methods called, in order */
    public function calledMethodNames(): array
    {
        return array_map(static fn (array $call): string => $call['method'], $this->calls);
    }

    public function findAllMessagesForConversationInOrder(
        AIConversation $aiConversation,
        ?int $offset = null,
        ?int $limit = null,
        bool $useEntityRegistrCache = true
    ): ?AIConversationMessages {
        $this->calls[] = ['method' => 'findAllMessagesForConversationInOrder', 'arguments' => ['offset' => $offset, 'limit' => $limit, 'useEntityRegistrCache' => $useEntityRegistrCache]];
        return self::fixtureMessageSet($this->allMessages);
    }

    public function findRawTailMessagesAfterIndex(
        AIConversation $aiConversation,
        int $afterIndex,
        bool $useEntityRegistrCache = true
    ): ?AIConversationMessages {
        $this->calls[] = ['method' => 'findRawTailMessagesAfterIndex', 'arguments' => ['afterIndex' => $afterIndex, 'useEntityRegistrCache' => $useEntityRegistrCache]];
        if ($this->tailMessages !== null) {
            return self::fixtureMessageSet($this->tailMessages);
        }
        $tailMessages = [];
        foreach ($this->allMessages as $message) {
            if (($message->indexInConversation ?? PHP_INT_MIN) > $afterIndex) {
                $tailMessages[] = $message;
            }
        }
        return self::fixtureMessageSet($tailMessages);
    }
}
