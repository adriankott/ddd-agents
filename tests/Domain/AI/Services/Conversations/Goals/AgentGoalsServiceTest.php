<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Goals;

use DDD\Domain\AI\Entities\Conversations\Goals\AgentGoal;
use DDD\Domain\AI\Repo\DB\Conversations\Goals\DBAgentGoalModel;
use DDD\Domain\AI\Services\Conversations\Goals\AgentGoalsService;
use DDD\Domain\AI\Services\Conversations\Goals\AgentGoalStoreService;
use DDD\Domain\AI\Services\Eval\AIConversationEvalPurgeService;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Infrastructure\Base\DateTime\Date;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Libs\Config;
use DDD\Tests\Agents\Support\IntegrationTestCase;

/**
 * The one write path of a goal and its transitions, over the goal port replaced by a host's store
 * ({@see HostBackedAgentGoalStoreService}, put in the container's place of {@see AgentGoalStoreService}): the service
 * reaches the host's rows only through the port. An exact restatement writes nothing unless it corrects the goal (then
 * the old row is closed BEFORE the new one is written, both carry the same dedup key); a near-duplicate writes the new
 * row first and then closes the old; the parent rules and the transition refusals hold; a host may compare statements
 * by its own test.
 */
class AgentGoalsServiceTest extends IntegrationTestCase
{
    public const int ACCOUNT_ID = 7;

    public const int AGENT_SPACE_ID = 12;

    protected HostBackedAgentGoalStoreService $hostBackedAgentGoalStoreService;

    protected function setUp(): void
    {
        parent::setUp();
        if (Config::getEnv('LAZYLOAD_DEFAULT_REPO_TYPE') === null) {
            LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
        }
        $this->hostBackedAgentGoalStoreService = new HostBackedAgentGoalStoreService();
        $this->replaceService(AgentGoalStoreService::class, $this->hostBackedAgentGoalStoreService);
    }

    public function testANewGoalIsWrittenWithTheStatusItsProvenanceGives(): void
    {
        $ownerGoal = (new AgentGoalsService())->persistGoal($this->buildGoal('Weekend catering is booked out', true));
        $proposedGoal = (new AgentGoalsService())->persistGoal($this->buildGoal('Every review gets an answer within two days', false));

        self::assertSame([AgentGoal::STATUS_ACTIVE, AgentGoal::STATUS_PROPOSED], [$ownerGoal->status, $proposedGoal->status]);
        self::assertSame('s:' . self::AGENT_SPACE_ID, $ownerGoal->scopeKey);
        self::assertSame(['writeGoal:Weekend catering is booked out', 'writeGoal:Every review gets an answer within two days'], $this->hostBackedAgentGoalStoreService->findWriteCalls());
    }

    public function testAnExactRestatementWritesNothing(): void
    {
        $storedGoal = $this->hostBackedAgentGoalStoreService->addHostRow($this->buildGoal('Weekend catering is booked out', true));

        $restatedGoal = (new AgentGoalsService())->persistGoal($this->buildGoal('  weekend catering is BOOKED out ', true));

        self::assertSame($storedGoal, $restatedGoal);
        self::assertSame([], $this->hostBackedAgentGoalStoreService->findWriteCalls());
    }

    public function testARestatementThatCorrectsTheHorizonClosesTheOldRowBeforeTheNewOneIsWritten(): void
    {
        $storedGoal = $this->hostBackedAgentGoalStoreService->addHostRow($this->buildGoal('Weekend catering is booked out', true));

        $correctedGoal = (new AgentGoalsService())->persistGoal($this->buildGoal('Weekend catering is booked out', true, AgentGoal::HORIZON_SHORT_TERM, new Date('2027-08-31')));

        self::assertNotSame((int)$storedGoal->id, (int)$correctedGoal->id);
        self::assertSame([
            'closeValidityWindow:' . $storedGoal->id . '>none',
            'writeGoal:Weekend catering is booked out',
            'recordSuccessorOfClosedGoal:' . $storedGoal->id . '>' . $correctedGoal->id,
        ], $this->hostBackedAgentGoalStoreService->findWriteCalls());
        self::assertFalse($storedGoal->isCurrentlyValid());
        self::assertSame((int)$correctedGoal->id, $storedGoal->supersededByGoalId);
    }

    public function testADroppedGoalStatedAgainIsANewVersion(): void
    {
        $droppedGoal = $this->buildGoal('Weekend catering is booked out', true);
        $droppedGoal->status = AgentGoal::STATUS_DROPPED;
        $this->hostBackedAgentGoalStoreService->addHostRow($droppedGoal);

        $restatedGoal = (new AgentGoalsService())->persistGoal($this->buildGoal('Weekend catering is booked out', true));

        self::assertSame(AgentGoal::STATUS_ACTIVE, $restatedGoal->status);
        self::assertFalse($droppedGoal->isCurrentlyValid());
    }

    public function testANearDuplicateIsWrittenFirstAndThenSupersedesTheOldRow(): void
    {
        $storedGoal = $this->hostBackedAgentGoalStoreService->addHostRow($this->buildGoal('Weekend catering is booked out through the summer', true));

        $rewordedGoal = (new AgentGoalsService())->persistGoal($this->buildGoal('Weekend catering is booked out through the summer!', true));

        self::assertSame([
            'writeGoal:Weekend catering is booked out through the summer!',
            'closeValidityWindow:' . $storedGoal->id . '>' . $rewordedGoal->id,
        ], $this->hostBackedAgentGoalStoreService->findWriteCalls());
        self::assertSame((int)$rewordedGoal->id, $storedGoal->supersededByGoalId);
    }

    public function testOnlyTheGoalsOfExactlyTheSameScopeAreCompared(): void
    {
        $accountGoal = $this->buildGoal('Weekend catering is booked out', true);
        $accountGoal->anchorToScope(self::ACCOUNT_ID, null);
        $this->hostBackedAgentGoalStoreService->addHostRow($accountGoal);

        $spaceGoal = (new AgentGoalsService())->persistGoal($this->buildGoal('Weekend catering is booked out', true));

        self::assertNotSame((int)$accountGoal->id, (int)$spaceGoal->id);
        self::assertTrue($accountGoal->isCurrentlyValid(), 'a goal of the account level is not replaced by a goal of a space');
    }

    public function testTheHostMayCompareStatementsByItsOwnTest(): void
    {
        $storedGoal = $this->hostBackedAgentGoalStoreService->addHostRow($this->buildGoal('Weekend catering is booked out', true));

        $rewordedGoal = (new AgentGoalsServiceComparingByMeaningDouble())->persistGoal($this->buildGoal('Saturdays and Sundays are full of catering orders', true));

        self::assertFalse($storedGoal->isCurrentlyValid());
        self::assertSame((int)$rewordedGoal->id, $storedGoal->supersededByGoalId);
    }

    public function testTheParentRules(): void
    {
        $ownerGoal = $this->hostBackedAgentGoalStoreService->addHostRow($this->buildGoal('Weekend catering is booked out', true));
        $agentGoal = $this->buildGoal('Post the catering offer every Friday', false);
        $agentGoal->parentGoalId = (int)$ownerGoal->id;
        $agentGoal = (new AgentGoalsService())->persistGoal($agentGoal);
        self::assertSame((int)$ownerGoal->id, $agentGoal->parentGoalId);

        $this->assertRefused('An owner goal never hangs under a goal the agent holds.', $this->buildChildGoal('A catering menu card', true, (int)$agentGoal->id));
        $this->assertRefused('Parent goal not found — take its current #<id> from getGoals.', $this->buildChildGoal('A catering menu card', false, 999));

        $otherSpaceGoal = $this->buildGoal('Another shop\'s goal', true);
        $otherSpaceGoal->anchorToScope(self::ACCOUNT_ID, 13);
        $this->hostBackedAgentGoalStoreService->addHostRow($otherSpaceGoal);
        $this->assertRefused('Parent goal not found — take its current #<id> from getGoals.', $this->buildChildGoal('A catering menu card', false, (int)$otherSpaceGoal->id));

        $thirdLevelGoal = (new AgentGoalsService())->persistGoal($this->buildChildGoal('Draft the Friday post on Thursday', false, (int)$agentGoal->id));
        $this->assertRefused('A goal tree has at most 3 levels.', $this->buildChildGoal('Write the post text', false, (int)$thirdLevelGoal->id));
    }

    public function testATransitionRefusesWhatTheGoalCannotReach(): void
    {
        $vision = $this->buildGoal('Be the bakery the street talks about', true, AgentGoal::HORIZON_VISION);
        $this->hostBackedAgentGoalStoreService->addHostRow($vision);
        $ownerGoal = $this->hostBackedAgentGoalStoreService->addHostRow($this->buildGoal('Weekend catering is booked out', true));
        $agentGoal = $this->buildGoal('Post the catering offer every Friday', false);
        $agentGoal->status = AgentGoal::STATUS_PROPOSED;
        $this->hostBackedAgentGoalStoreService->addHostRow($agentGoal);
        $agentGoalsService = new AgentGoalsService();

        $this->assertTransitionRefused('A VISION is never achieved — it orients. Mark the goals beneath it achieved instead.', $vision, AgentGoal::STATUS_ACHIEVED);
        $this->assertTransitionRefused('Only a goal the agent holds can be BLOCKED — an owner goal is corrected (addGoal) or dropped.', $ownerGoal, AgentGoal::STATUS_BLOCKED, 'no time');
        $this->assertTransitionRefused('BLOCKED needs a statusNote naming the blocker.', $agentGoal, AgentGoal::STATUS_BLOCKED, ' ');
        $this->assertTransitionRefused('status is one of PROPOSED, ACTIVE, ACHIEVED, DROPPED, PAUSED, BLOCKED.', $agentGoal, 'DONE');

        $confirmedGoal = $agentGoalsService->transitionGoalStatus($agentGoal, AgentGoal::STATUS_ACTIVE);
        self::assertSame(AgentGoal::PROVENANCE_CONFIRMED, $confirmedGoal->provenance);
        $blockedGoal = $agentGoalsService->transitionGoalStatus($agentGoal, AgentGoal::STATUS_BLOCKED, 'The page connection lapsed', true);
        self::assertTrue($blockedGoal->requiresUserAction);

        $ownerGoal->supersedeBy($vision);
        $this->assertTransitionRefused('This goal was replaced by a newer version — take the current #<id> from getGoals.', $ownerGoal, AgentGoal::STATUS_DROPPED);
    }

    public function testTheCurrentGoalsAndTheEvaluationPurgeAreAnsweredByThePort(): void
    {
        $this->hostBackedAgentGoalStoreService->addHostRow($this->buildGoal('Weekend catering is booked out', true));
        $agentGoalsService = new AgentGoalsService();

        self::assertSame(1, $agentGoalsService->findCurrentGoalsForScope(self::ACCOUNT_ID, self::AGENT_SPACE_ID)->count());
        self::assertSame(0, $agentGoalsService->findCurrentGoalsForScope(self::ACCOUNT_ID)->count(), 'the account level holds no goal of a space');
        self::assertTrue($agentGoalsService->isGoalStoreAvailable());
        self::assertSame(1, $agentGoalsService->invalidateCurrentGoalsOfScope(self::ACCOUNT_ID, self::AGENT_SPACE_ID));
        self::assertArrayNotHasKey(DBAgentGoalModel::getTableName() . '.sourceMessageId', (new AIConversationEvalPurgeService())->getColumnsNotPurged());
        self::assertContains('getColumnsNotPurgedByEvalPurge', $this->hostBackedAgentGoalStoreService->portCalls);
    }

    public function testThePackageStoreNamesItsColumnForTheEvaluationPurge(): void
    {
        $goalTableName = (string)DBAgentGoalModel::getTableName();

        self::assertSame('AgentGoals', $goalTableName);
        self::assertArrayHasKey($goalTableName . '.sourceMessageId', (new AgentGoalStoreService())->getColumnsNotPurgedByEvalPurge());
    }

    protected function buildGoal(string $statement, bool $isStatedByOwner, string $horizonLevel = AgentGoal::HORIZON_LONG_TERM, ?Date $targetDate = null): AgentGoal
    {
        $agentGoal = AgentGoal::buildStated($statement, $horizonLevel, null, null, $targetDate, 0, null);
        $agentGoal->anchorToScope(self::ACCOUNT_ID, self::AGENT_SPACE_ID);
        $agentGoal->markHeldByOwnerOrAgent($isStatedByOwner);
        return $agentGoal;
    }

    protected function buildChildGoal(string $statement, bool $isStatedByOwner, int $parentGoalId): AgentGoal
    {
        $agentGoal = $this->buildGoal($statement, $isStatedByOwner);
        $agentGoal->parentGoalId = $parentGoalId;
        return $agentGoal;
    }

    protected function assertRefused(string $expectedMessage, AgentGoal $agentGoal): void
    {
        try {
            (new AgentGoalsService())->persistGoal($agentGoal);
            self::fail('refused: ' . $expectedMessage);
        } catch (BadRequestException $badRequestException) {
            self::assertSame($expectedMessage, $badRequestException->getMessage());
        }
    }

    protected function assertTransitionRefused(string $expectedMessage, AgentGoal $agentGoal, string $newStatus, ?string $statusNote = null): void
    {
        try {
            (new AgentGoalsService())->transitionGoalStatus($agentGoal, $newStatus, $statusNote);
            self::fail('refused: ' . $expectedMessage);
        } catch (BadRequestException $badRequestException) {
            self::assertSame($expectedMessage, $badRequestException->getMessage());
        }
    }
}
