<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations\Reads;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\RunReports\AIConversationRunReportTaskPlanSnapshot;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Services\Conversations\RunReports\AIConversationRunReportService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;

/**
 * The plan-snapshot read of {@see AIConversationRunReportService} against the database, on both engines: the snapshots
 * the messages of one conversation stamped, one per message index, decoded from their JSON column; a message without a
 * snapshot, a truncated message and a message of another conversation are left out.
 */
class AIConversationRunReportServiceDatabaseTest extends DatabaseTestCase
{
    protected const int CONVERSATION_ID = 700;

    protected function setUp(): void
    {
        parent::setUp();
        DDDService::instance()->deactivateEntityRightsRestrictions();
    }

    protected function onTearDown(): void
    {
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
    }

    public function testThePlanSnapshotsAreReadByMessageIndex(): void
    {
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => self::CONVERSATION_ID]);
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => 701]);
        $firstSnapshot = ['nodes' => [['title' => 'Read the figures', 'status' => 'DONE', 'children' => []]], 'tailTokens' => 12];
        $secondSnapshot = ['nodes' => [['title' => 'Answer', 'status' => 'IN_PROGRESS', 'children' => [['title' => 'Draft', 'status' => 'OPEN']]]]];
        $this->insertMessage(self::CONVERSATION_ID, 0, []);
        $this->insertMessage(self::CONVERSATION_ID, 1, ['taskPlanSnapshot' => json_encode($firstSnapshot)]);
        $this->insertMessage(self::CONVERSATION_ID, 2, ['taskPlanSnapshot' => json_encode($secondSnapshot)]);
        $this->insertMessage(self::CONVERSATION_ID, 3, ['taskPlanSnapshot' => json_encode(['nodes' => []]), 'truncatedAt' => '2026-11-03 11:00:00']);
        $this->insertMessage(701, 1, ['taskPlanSnapshot' => json_encode(['nodes' => [['title' => 'Elsewhere']]])]);
        /** @var AIConversationRunReportService $runReportService */
        $runReportService = DDDService::instance()->getService(AIConversationRunReportService::class);
        $conversation = AIConversation::newInstance();
        $conversation->id = self::CONVERSATION_ID;

        $aIConversationRunReportTaskPlanSnapshots = $runReportService->findTaskPlanSnapshotsOfConversation($conversation);

        $messageIndexes = array_map(static fn (AIConversationRunReportTaskPlanSnapshot $aIConversationRunReportTaskPlanSnapshot): int => $aIConversationRunReportTaskPlanSnapshot->messageIndex, $aIConversationRunReportTaskPlanSnapshots->getElements());
        sort($messageIndexes);
        self::assertSame([1, 2], $messageIndexes);
        self::assertEquals($firstSnapshot, $aIConversationRunReportTaskPlanSnapshots->findByMessageIndex(1)?->toArray());
        self::assertEquals($secondSnapshot, $aIConversationRunReportTaskPlanSnapshots->findByMessageIndex(2)?->toArray());
    }

    /** @param array<string, mixed> $columnValues */
    protected function insertMessage(int $conversationId, int $indexInConversation, array $columnValues): void
    {
        $this->insertRow(DBAIConversationMessageModel::getTableName(), $columnValues + [
            'aiConversationId' => $conversationId,
            'indexInConversation' => $indexInConversation,
            'role' => AIConversationMessage::ROLE_ASSISTANT,
            'messageType' => AIConversationMessage::MESSAGE_TEXT,
            'message' => '',
            'created' => '2026-11-03 10:00:00',
            'updated' => '2026-11-03 10:00:00',
        ]);
    }
}
