<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations\Search;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\Texts\Embeddings\TextEmbedding;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessages;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\Embeddings\DBAIConversationMessageEmbeddingModel;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Entities\MathEntities\Vector;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;

/**
 * The search across an account's conversations answers from the owner's dialogue only: the vector and the keyword
 * query leave out, inside the query, an eval run (unless it holds seeded history), a conversation of a left-out
 * purpose (a customer-hidden purpose, the schedule run) and a scheduled run — each also when only the root
 * conversation is such a run. On both engines.
 */
class SearchAcrossConversationsLeavesOutRunsWithoutTheOwnerDatabaseTest extends DatabaseTestCase
{
    protected const int ACCOUNT_ID = 10;

    protected const int OWNER_DIALOGUE_CONVERSATION_ID = 1;
    protected const int EVAL_RUN_CONVERSATION_ID = 2;
    protected const int EVAL_SEEDED_HISTORY_CONVERSATION_ID = 3;
    protected const int SCHEDULE_RUN_PURPOSE_CONVERSATION_ID = 4;
    protected const int HIDDEN_PURPOSE_CONVERSATION_ID = 5;
    protected const int SCHEDULED_CONVERSATION_ID = 6;
    protected const int CHILD_OF_SCHEDULED_CONVERSATION_ID = 7;
    protected const int CHILD_OF_OWNER_DIALOGUE_CONVERSATION_ID = 8;
    protected const int ADMIN_TASK_CONVERSATION_ID = 9;

    /** The conversations whose messages the search answers from. */
    protected const array SEARCHED_CONVERSATION_IDS = [
        self::OWNER_DIALOGUE_CONVERSATION_ID,
        self::EVAL_SEEDED_HISTORY_CONVERSATION_ID,
        self::CHILD_OF_OWNER_DIALOGUE_CONVERSATION_ID,
        self::ADMIN_TASK_CONVERSATION_ID,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::ACCOUNT_ID, 'email' => 'owner@example.test']);
        $this->insertConversation(self::OWNER_DIALOGUE_CONVERSATION_ID);
        $this->insertConversation(self::EVAL_RUN_CONVERSATION_ID, ['runSettings' => '{"evalRun": true}']);
        $this->insertConversation(self::EVAL_SEEDED_HISTORY_CONVERSATION_ID, ['runSettings' => '{"evalRun": true, "evalSeededHistory": true}']);
        $this->insertConversation(self::SCHEDULE_RUN_PURPOSE_CONVERSATION_ID, ['purposeName' => AIConversationPurpose::SCHEDULE_RUN]);
        $this->insertConversation(self::HIDDEN_PURPOSE_CONVERSATION_ID, ['purposeName' => AIConversationPurpose::BACKGROUND_MECHANICAL_OPERATION]);
        $this->insertConversation(self::SCHEDULED_CONVERSATION_ID, ['initiatorType' => AIConversation::INITIATOR_TYPE_SCHEDULE]);
        $this->insertConversation(self::CHILD_OF_SCHEDULED_CONVERSATION_ID, ['rootConversationId' => self::SCHEDULED_CONVERSATION_ID]);
        $this->insertConversation(self::CHILD_OF_OWNER_DIALOGUE_CONVERSATION_ID, ['rootConversationId' => self::OWNER_DIALOGUE_CONVERSATION_ID]);
        $this->insertConversation(self::ADMIN_TASK_CONVERSATION_ID, ['initiatorType' => AIConversation::INITIATOR_TYPE_ADMIN_TASK]);
        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $conversationId) {
            $this->insertMessageWithVector($conversationId * 100, $conversationId);
        }
    }

    protected function onTearDown(): void
    {
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
    }

    public function testTheVectorSearchAnswersFromTheOwnersDialogueOnly(): void
    {
        $foundMessages = (new DBAIConversationMessages())->findSimilarAcrossConversationsByEmbedding(
            $this->buildAccount(),
            new TextEmbedding($this->buildVectorValues()),
            50
        );

        self::assertSame($this->getExpectedMessageIds(), $this->getSortedMessageIds($foundMessages));
    }

    public function testTheKeywordSearchAnswersFromTheOwnersDialogueOnly(): void
    {
        $foundMessages = (new DBAIConversationMessages())->findByKeywordAcrossConversations($this->buildAccount(), 'bicycle', 50);

        self::assertSame($this->getExpectedMessageIds(), $this->getSortedMessageIds($foundMessages));
    }

    protected function buildAccount(): Account
    {
        $account = new Account();
        $account->id = self::ACCOUNT_ID;
        return $account;
    }

    /** @return int[] */
    protected function getExpectedMessageIds(): array
    {
        $expectedMessageIds = array_map(static fn (int $conversationId): int => $conversationId * 100, self::SEARCHED_CONVERSATION_IDS);
        sort($expectedMessageIds);
        return $expectedMessageIds;
    }

    /** @param array<string, int|string> $columns */
    protected function insertConversation(int $conversationId, array $columns = []): void
    {
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => $conversationId, 'accountId' => self::ACCOUNT_ID] + $columns);
    }

    /** @return float[] a vector of the stored dimension along the first axis */
    protected function buildVectorValues(): array
    {
        return array_replace(array_fill(0, Vector::DIMENSION_OPENAI_EMBEDDING_SMALL, 0.0), [1.0]);
    }

    protected function insertMessageWithVector(int $messageId, int $conversationId): void
    {
        $this->insertRow(DBAIConversationMessageModel::getTableName(), [
            'id' => $messageId,
            'aiConversationId' => $conversationId,
            'indexInConversation' => 0,
            'role' => AIConversationMessage::ROLE_USER,
            'authorType' => AIConversationMessage::AUTHOR_USER,
            'messageType' => AIConversationMessage::MESSAGE_TEXT,
            'message' => 'my bicycle tour number ' . $messageId,
        ]);
        $this->insertRow(DBAIConversationMessageEmbeddingModel::getTableName(), ['aiConversationMessageId' => $messageId]);
        EntityManagerFactory::getInstance()->createQuery(
            'UPDATE ' . DBAIConversationMessageEmbeddingModel::class . ' embeddingRow SET embeddingRow.embedding = VEC_FROM_TEXT(:vectorText)'
            . ' WHERE embeddingRow.aiConversationMessageId = :messageId'
        )->setParameters([
            'vectorText' => json_encode($this->buildVectorValues(), JSON_THROW_ON_ERROR),
            'messageId' => $messageId,
        ])->execute();
    }

    /** @return int[] The ids of the found messages, ascending */
    protected function getSortedMessageIds(?AIConversationMessages $foundMessages): array
    {
        $messageIds = [];
        foreach ($foundMessages?->getElements() ?? [] as $foundMessage) {
            $messageIds[] = (int)$foundMessage->id;
        }
        sort($messageIds);
        return $messageIds;
    }
}
