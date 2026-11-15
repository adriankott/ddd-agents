<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations\Quality;

use DDD\Domain\AI\Entities\Conversations\Quality\AgentConversationScorecardSelection;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Services\Conversations\Quality\AgentConversationScorecardsService;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;

/**
 * The selection of a scorecard run ({@see AgentConversationScorecardsService::findConversationIdsForSelection()})
 * against the database, on both engines: root conversations created since the moment, oldest first and at most the
 * limit; eval runs left out unless included (JSON true only — a missing key, JSON null, false and an empty settings
 * column are no eval run); the purpose restriction with the regular chats as "no purpose"; the application's
 * internal-accounts filter applied unless included, and refused when it names a join.
 */
class AgentConversationScorecardsServiceDatabaseTest extends DatabaseTestCase
{
    protected const string SINCE = '2026-03-01';

    public function testTheSelectionReadsTheRootsSinceTheMomentOldestFirstWithoutEvalRunsUpToTheLimit(): void
    {
        $this->insertConversations();

        self::assertSame([101, 102, 103, 104, 107, 108], $this->findSelectedIds($this->buildSelection()), 'no delegation child, nothing before the moment, no eval run');
        self::assertSame([101, 102, 103], $this->findSelectedIds($this->buildSelection(limit: 3)), 'the limit counts the conversations the run works on');
        self::assertSame([108], $this->findSelectedIds(AgentConversationScorecardSelection::createdSince('2026-04-01 12:00:00', 10)), 'a moment with its time');
    }

    public function testWithEvalRunsIncludedTheEvalRunIsSelected(): void
    {
        $this->insertConversations();
        $selection = $this->buildSelection();
        $selection->includesEvalRuns = true;

        self::assertSame([101, 102, 103, 104, 105, 107, 108], $this->findSelectedIds($selection));
    }

    public function testThePurposeRestrictionSelectsItsPurposesAndTheRegularChats(): void
    {
        $this->insertConversations();

        $purposesAndRegularChats = $this->buildSelection();
        $purposesAndRegularChats->restrictToPurposes(['Intake.General', 'Report.Weekly'], true);
        self::assertSame([101, 102, 103, 104, 108], $this->findSelectedIds($purposesAndRegularChats));

        $purposeOnly = $this->buildSelection();
        $purposeOnly->restrictToPurposes(['Intake.General'], false);
        self::assertSame([103, 104], $this->findSelectedIds($purposeOnly));

        $regularChatsOnly = $this->buildSelection();
        $regularChatsOnly->restrictToPurposes([], true);
        self::assertSame([101, 102, 108], $this->findSelectedIds($regularChatsOnly));

        $nothingNamed = $this->buildSelection();
        $nothingNamed->restrictToPurposes([], false);
        self::assertFalse($nothingNamed->isRestrictedByPurpose);
        self::assertSame([101, 102, 103, 104, 107, 108], $this->findSelectedIds($nothingNamed), 'an empty --purpose restricts nothing');
    }

    public function testTheApplicationsInternalAccountsFilterAppliesUnlessTheInternalAccountsAreIncluded(): void
    {
        $this->insertConversations();
        $scorecardsServiceLeavingOutOneAccount = new ScorecardsServiceLeavingOutOneAccountDouble(internalAccountId: 9);

        self::assertSame([101, 103, 107, 108], $scorecardsServiceLeavingOutOneAccount->findConversationIdsForSelection($this->buildSelection()), 'the conversations of account 9 left out, the one without an account kept');

        $withInternalAccounts = $this->buildSelection();
        $withInternalAccounts->includesInternalAccounts = true;
        self::assertSame([101, 102, 103, 104, 107, 108], $scorecardsServiceLeavingOutOneAccount->findConversationIdsForSelection($withInternalAccounts));
    }

    public function testAnInternalAccountsFilterWithAJoinIsRefused(): void
    {
        $scorecardsServiceWithJoin = new ScorecardsServiceLeavingOutOneAccountDouble(internalAccountId: 9, filterNamesAJoin: true);

        $this->expectException(InternalErrorException::class);
        $scorecardsServiceWithJoin->findConversationIdsForSelection($this->buildSelection());
    }

    /**
     * 100 before the moment; 101, 102, 108 regular chats (102 of account 9, 108 without an account); 103, 104 of
     * `Intake.General` (104 of account 9, its eval marker false); 105 an eval run; 106 a delegation child; 107 of
     * `Other.Purpose` with JSON null as eval marker.
     */
    protected function insertConversations(): void
    {
        $this->insertRow(DBAccountModel::getTableName(), ['id' => 7, 'email' => 'first@example.test']);
        $this->insertRow(DBAccountModel::getTableName(), ['id' => 9, 'email' => 'second@example.test']);
        $tableName = DBAIConversationModel::getTableName();
        $this->insertRow($tableName, ['id' => 100, 'created' => '2026-02-28 23:59:59', 'accountId' => 7]);
        $this->insertRow($tableName, ['id' => 101, 'created' => '2026-03-01 00:00:00', 'accountId' => 7, 'runSettings' => '{}']);
        $this->insertRow($tableName, ['id' => 102, 'created' => '2026-03-02 10:00:00', 'accountId' => 9]);
        $this->insertRow($tableName, ['id' => 103, 'created' => '2026-03-03 10:00:00', 'accountId' => 7, 'purposeName' => 'Intake.General']);
        $this->insertRow($tableName, ['id' => 104, 'created' => '2026-03-04 10:00:00', 'accountId' => 9, 'purposeName' => 'Intake.General', 'runSettings' => '{"evalRun":false}']);
        $this->insertRow($tableName, ['id' => 105, 'created' => '2026-03-05 10:00:00', 'accountId' => 7, 'purposeName' => 'Intake.General', 'runSettings' => '{"evalRun":true}']);
        // the delegate row of 101 the child 106 was forked from
        $this->insertRow(DBAIConversationMessageModel::getTableName(), [
            'id' => 5000,
            'aiConversationId' => 101,
            'indexInConversation' => 0,
            'messageType' => AIConversationMessage::MESSAGE_TEXT,
            'role' => AIConversationMessage::ROLE_USER,
            'message' => 'delegate',
        ]);
        $this->insertRow($tableName, ['id' => 106, 'created' => '2026-03-06 10:00:00', 'accountId' => 7, 'delegatedFromMessageId' => 5000]);
        $this->insertRow($tableName, ['id' => 107, 'created' => '2026-03-07 10:00:00', 'accountId' => 7, 'purposeName' => 'Other.Purpose', 'runSettings' => '{"evalRun":null}']);
        $this->insertRow($tableName, ['id' => 108, 'created' => '2026-04-02 10:00:00']);
    }

    protected function buildSelection(int $limit = 50): AgentConversationScorecardSelection
    {
        return AgentConversationScorecardSelection::createdSince(self::SINCE, $limit);
    }

    /** @return int[] */
    protected function findSelectedIds(AgentConversationScorecardSelection $selection): array
    {
        /** @var AgentConversationScorecardsService $agentConversationScorecardsService */
        $agentConversationScorecardsService = DDDService::instance()->getService(AgentConversationScorecardsService::class);
        return $agentConversationScorecardsService->findConversationIdsForSelection($selection);
    }
}
