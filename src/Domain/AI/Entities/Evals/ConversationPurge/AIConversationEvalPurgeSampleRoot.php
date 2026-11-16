<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\ConversationPurge;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One purged tree the report of the evaluation purge keeps as a sample ({@see AIConversationEvalPurgeReport::$sampleRootConversations}):
 * its root, the root's account, the number of conversations of the tree and the evaluation marker that selected it
 * (null when the run selected every tree of the account).
 *
 * @phpstan-consistent-constructor
 */
class AIConversationEvalPurgeSampleRoot extends ValueObject
{
    /** @var string[] The headers of the sample table of the console output */
    public const array TABLE_HEADERS = ['sample root conversation', 'account', 'conversations in tree', 'eval marker'];

    /** @var string The table cell of a value that is not known */
    public const string EMPTY_TABLE_CELL = '-';

    public int $rootConversationId = 0;

    public ?int $accountId = null;

    /** @var int The conversations of the tree (root, delegation children, supervisor tandems) */
    public int $conversations = 0;

    /** @var string|null The evaluation marker that selected the root */
    public ?string $marker = null;

    public static function forPurgedTree(AIConversationEvalTree $tree, ?string $evalMarker): static
    {
        $aIConversationEvalPurgeSampleRoot = static::newInstance();
        $aIConversationEvalPurgeSampleRoot->rootConversationId = $tree->rootConversationId;
        $aIConversationEvalPurgeSampleRoot->accountId = $tree->rootAccountId;
        $aIConversationEvalPurgeSampleRoot->conversations = count($tree->conversationIds);
        $aIConversationEvalPurgeSampleRoot->marker = $evalMarker;
        return $aIConversationEvalPurgeSampleRoot;
    }

    /** @return string[] the row of the sample table: root, account, conversations, marker; `-` for an unknown value */
    public function buildTableCells(): array
    {
        return [
            (string)$this->rootConversationId,
            $this->accountId === null ? static::EMPTY_TABLE_CELL : (string)$this->accountId,
            (string)$this->conversations,
            $this->marker ?? static::EMPTY_TABLE_CELL,
        ];
    }
}
