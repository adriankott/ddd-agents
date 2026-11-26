<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Goals;

use DDD\Domain\AI\Entities\Conversations\Goals\AgentGoal;
use DDD\Domain\AI\Entities\Conversations\Goals\AgentGoals;
use DDD\Domain\AI\Repo\DB\Conversations\Goals\DBAgentGoalModel;
use DDD\Domain\AI\Services\Conversations\Goals\AgentGoalsService;
use DDD\Domain\AI\Services\Conversations\Goals\AgentGoalStoreService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Infrastructure\Base\DateTime\Date;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * The package's goal store on MariaDB and PostgreSQL: the store finds its table, a read holds the account level and
 * the one space (never another space) in priority order, the generated dedup key keeps one current row per scope and
 * statement (a closed duplicate stays as history), a corrected restatement closes the old row before the new one is
 * written and then points it at its successor, a reworded goal supersedes the old one, a status transition is stored,
 * and invalidating a scope closes only its current rows.
 */
class AgentGoalStoreDatabaseTest extends DatabaseTestCase
{
    public const int ACCOUNT_ID = 702;

    public const int AGENT_SPACE_ID = 41;

    public const int OTHER_AGENT_SPACE_ID = 42;

    protected ?Account $accountBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        AgentGoalStoreService::forgetGoalTableCheck();
        $this->accountBefore = AuthService::instance()->getAccount();
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::ACCOUNT_ID, 'email' => 'goals@example.test']);
        foreach ([self::AGENT_SPACE_ID, self::OTHER_AGENT_SPACE_ID] as $agentSpaceId) {
            $this->insertAgentSpaceRow(['id' => $agentSpaceId, 'accountId' => self::ACCOUNT_ID]);
        }
        $account = Account::newInstance();
        $account->id = self::ACCOUNT_ID;
        AuthService::instance()->setAccount($account);
    }

    protected function tearDown(): void
    {
        AuthService::instance()->setAccount($this->accountBefore);
        AgentGoalStoreService::forgetGoalTableCheck();
        parent::tearDown();
    }

    public function testTheStoreFindsItsTable(): void
    {
        self::assertTrue($this->getAgentGoalStoreService()->isGoalStoreAvailable());
    }

    public function testAReadHoldsTheAccountLevelAndTheOneSpaceInPriorityOrder(): void
    {
        $this->persistGoal('Answer in short sentences', null);
        $this->persistGoal('Weekend catering is booked out', self::AGENT_SPACE_ID, priority: 3);
        $this->persistGoal('A second shop by next spring', self::AGENT_SPACE_ID, priority: 1);
        $this->persistGoal('The other shop sells more bread', self::OTHER_AGENT_SPACE_ID);
        $proposedGoal = $this->persistGoal('Every review gets an answer within two days', self::AGENT_SPACE_ID, isStatedByOwner: false);
        (new AgentGoalsService())->transitionGoalStatus($proposedGoal, AgentGoal::STATUS_DROPPED, 'the owner answers reviews himself');

        $agentGoalStoreService = $this->getAgentGoalStoreService();
        self::assertSame(
            ['Weekend catering is booked out', 'A second shop by next spring', 'Answer in short sentences', 'Every review gets an answer within two days'],
            $this->collectStatements($agentGoalStoreService->findCurrentGoalsForScope(self::ACCOUNT_ID, self::AGENT_SPACE_ID))
        );
        self::assertSame(
            ['Weekend catering is booked out', 'A second shop by next spring', 'Answer in short sentences'],
            $this->collectStatements($agentGoalStoreService->findCurrentGoalsForScope(self::ACCOUNT_ID, self::AGENT_SPACE_ID, true)),
            'held only: the dropped goal is left out'
        );
        self::assertSame(['Answer in short sentences'], $this->collectStatements($agentGoalStoreService->findCurrentGoalsForScope(self::ACCOUNT_ID)));
        self::assertSame(1, $agentGoalStoreService->findCurrentGoalsForScope(self::ACCOUNT_ID, self::AGENT_SPACE_ID, false, 1)->count());
    }

    public function testOneCurrentRowPerScopeAndStatementAndAClosedDuplicateStaysAsHistory(): void
    {
        $storedGoal = $this->persistGoal('Weekend catering is booked out', self::AGENT_SPACE_ID);
        self::assertSame((int)$storedGoal->id, (int)$this->persistGoal(' weekend  catering is booked OUT', self::AGENT_SPACE_ID)->id, 'an exact restatement writes nothing');
        $this->persistGoal('Weekend catering is booked out', self::OTHER_AGENT_SPACE_ID);

        try {
            $this->insertDuplicateRow('Weekend catering is booked out', 's:' . self::AGENT_SPACE_ID, null);
            self::fail('a second current row of the same scope and statement must be refused');
        } catch (UniqueConstraintViolationException) {
        }
        $this->insertDuplicateRow('Weekend catering is booked out', 's:' . self::AGENT_SPACE_ID, '2026-01-01 00:00:00');
        self::assertSame(3, $this->countGoalRows());
    }

    public function testACorrectedRestatementClosesTheOldRowFirstAndPointsItAtItsSuccessor(): void
    {
        $storedGoal = $this->persistGoal('The summer menu is online', self::AGENT_SPACE_ID);

        $correctedGoal = $this->persistGoal('The summer menu is online', self::AGENT_SPACE_ID, AgentGoal::HORIZON_SHORT_TERM, new Date('2027-05-31'));

        self::assertNotSame((int)$storedGoal->id, (int)$correctedGoal->id);
        $storedOldRow = $this->findStoredRow(DBAgentGoalModel::getTableName(), (int)$storedGoal->id);
        self::assertNotNull($storedOldRow['validTo']);
        self::assertSame((int)$correctedGoal->id, (int)$storedOldRow['supersededByGoalId']);
        $currentGoals = $this->getAgentGoalStoreService()->findCurrentGoalsForScope(self::ACCOUNT_ID, self::AGENT_SPACE_ID);
        self::assertSame(1, $currentGoals->count());
        self::assertSame(AgentGoal::HORIZON_SHORT_TERM, $currentGoals->first()->horizonLevel);
        self::assertSame('2027-05-31', $currentGoals->first()->targetDate?->format(Date::DATE));
    }

    public function testARewordedGoalSupersedesTheOldOneAndATransitionIsStored(): void
    {
        $storedGoal = $this->persistGoal('Weekend catering is booked out through the summer', self::AGENT_SPACE_ID);

        $rewordedGoal = $this->persistGoal('Weekend catering is booked out through the summer!', self::AGENT_SPACE_ID);
        (new AgentGoalsService())->transitionGoalStatus($rewordedGoal, AgentGoal::STATUS_ACHIEVED, 'every weekend of July is booked');

        $storedOldRow = $this->findStoredRow(DBAgentGoalModel::getTableName(), (int)$storedGoal->id);
        self::assertSame((int)$rewordedGoal->id, (int)$storedOldRow['supersededByGoalId']);
        $storedNewRow = $this->findStoredRow(DBAgentGoalModel::getTableName(), (int)$rewordedGoal->id);
        self::assertSame(AgentGoal::STATUS_ACHIEVED, $storedNewRow['status']);
        self::assertSame('every weekend of July is booked', $storedNewRow['statusNote']);
        self::assertNotNull($storedNewRow['achievedAt']);
    }

    public function testInvalidatingAScopeClosesOnlyItsCurrentRows(): void
    {
        $this->persistGoal('Weekend catering is booked out', self::AGENT_SPACE_ID);
        $this->persistGoal('A second shop by next spring', self::AGENT_SPACE_ID);
        $this->persistGoal('Answer in short sentences', null);
        $this->persistGoal('The other shop sells more bread', self::OTHER_AGENT_SPACE_ID);

        self::assertSame(2, $this->getAgentGoalStoreService()->invalidateCurrentGoalsOfScope(self::ACCOUNT_ID, self::AGENT_SPACE_ID));
        self::assertSame(0, $this->getAgentGoalStoreService()->invalidateCurrentGoalsOfScope(self::ACCOUNT_ID, self::AGENT_SPACE_ID), 'closed rows stay closed');
        self::assertSame(['Answer in short sentences'], $this->collectStatements($this->getAgentGoalStoreService()->findCurrentGoalsForScope(self::ACCOUNT_ID, self::AGENT_SPACE_ID)));
        self::assertSame(2, $this->getAgentGoalStoreService()->findCurrentGoalsForScope(self::ACCOUNT_ID, self::OTHER_AGENT_SPACE_ID)->count(), 'the other space keeps its goal beside the account level');
        self::assertSame(4, $this->countGoalRows(), 'closed rows stay as history');
    }

    protected function persistGoal(string $statement, ?int $agentSpaceId, string $horizonLevel = AgentGoal::HORIZON_LONG_TERM, ?Date $targetDate = null, int $priority = 0, bool $isStatedByOwner = true): AgentGoal
    {
        $agentGoal = AgentGoal::buildStated($statement, $horizonLevel, null, null, $targetDate, $priority, null);
        $agentGoal->anchorToScope(self::ACCOUNT_ID, $agentSpaceId);
        $agentGoal->markHeldByOwnerOrAgent($isStatedByOwner);
        return (new AgentGoalsService())->persistGoal($agentGoal);
    }

    protected function insertDuplicateRow(string $statement, string $scopeKey, ?string $invalidatedAt): void
    {
        $this->insertRow(DBAgentGoalModel::getTableName(), [
            'accountId' => self::ACCOUNT_ID,
            'agentSpaceId' => self::AGENT_SPACE_ID,
            'holder' => AgentGoal::HOLDER_OWNER,
            'horizonLevel' => AgentGoal::HORIZON_LONG_TERM,
            'status' => AgentGoal::STATUS_ACTIVE,
            'provenance' => AgentGoal::PROVENANCE_USER_STATED,
            'statement' => $statement,
            'statementHash' => AgentGoal::hashForStatement($statement),
            'scopeKey' => $scopeKey,
            'validTo' => $invalidatedAt,
            'invalidatedAt' => $invalidatedAt,
        ]);
    }

    protected function countGoalRows(): int
    {
        return (int)$this->getDatabaseConnection()->fetchOne('SELECT COUNT(*) FROM ' . $this->getSqlDialect()->quote(DBAgentGoalModel::getTableName()));
    }

    /** @return string[] the statements of the goals, in the order of the set */
    protected function collectStatements(AgentGoals $agentGoals): array
    {
        $statements = [];
        foreach ($agentGoals->getElements() as $agentGoal) {
            $statements[] = $agentGoal->statement;
        }
        return $statements;
    }

    protected function getAgentGoalStoreService(): AgentGoalStoreService
    {
        return DDDService::instance()->getService(AgentGoalStoreService::class);
    }
}
