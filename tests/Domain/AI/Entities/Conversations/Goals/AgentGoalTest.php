<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Goals;

use DDD\Domain\AI\Entities\Conversations\Goals\AgentGoal;
use DDD\Domain\AI\Entities\Conversations\Goals\AgentGoals;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Infrastructure\Base\DateTime\Date;
use DDD\Infrastructure\Libs\Config;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The rules a goal carries itself: the initial status from the provenance, which status it may reach, which parent it
 * may have, when it is held, what a restatement corrects, the status transition, the cut and the stamps before a write,
 * the bitemporal closing and the line the model reads.
 */
class AgentGoalTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (Config::getEnv('LAZYLOAD_DEFAULT_REPO_TYPE') === null) {
            LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
        }
    }

    public function testTheInitialStatusFollowsTheProvenance(): void
    {
        $expectedInitialStatuses = [
            AgentGoal::PROVENANCE_USER_STATED => AgentGoal::STATUS_ACTIVE,
            AgentGoal::PROVENANCE_AGENT_DERIVED => AgentGoal::STATUS_PROPOSED,
            AgentGoal::PROVENANCE_CONFIRMED => AgentGoal::STATUS_ACTIVE,
            AgentGoal::PROVENANCE_PRODUCT_DEFAULT => AgentGoal::STATUS_ACTIVE,
            AgentGoal::PROVENANCE_LEARNED => AgentGoal::STATUS_PROPOSED,
        ];
        foreach ($expectedInitialStatuses as $provenance => $expectedInitialStatus) {
            $agentGoal = $this->buildGoal('Weekend catering is booked out');
            $agentGoal->provenance = $provenance;
            self::assertSame($expectedInitialStatus, $agentGoal->deriveInitialStatus(), $provenance);
        }
    }

    public function testStatedByTheOwnerOrDerivedByTheAgentSetsHolderAndProvenance(): void
    {
        $agentGoal = $this->buildGoal('A second shop in the old town');
        $agentGoal->markHeldByOwnerOrAgent(true);
        self::assertSame([AgentGoal::HOLDER_OWNER, AgentGoal::PROVENANCE_USER_STATED], [$agentGoal->holder, $agentGoal->provenance]);

        $agentGoal->markHeldByOwnerOrAgent(false);
        self::assertSame([AgentGoal::HOLDER_AGENT, AgentGoal::PROVENANCE_AGENT_DERIVED], [$agentGoal->holder, $agentGoal->provenance]);
    }

    public function testAVisionIsNeverAchievedAndOnlyAnAgentGoalIsBlocked(): void
    {
        $vision = $this->buildGoal('Be the bakery the street talks about', AgentGoal::HORIZON_VISION);
        self::assertFalse($vision->isStatusReachable(AgentGoal::STATUS_ACHIEVED));
        self::assertTrue($vision->isStatusReachable(AgentGoal::STATUS_DROPPED));

        $ownerGoal = $this->buildGoal('The summer menu is online', AgentGoal::HORIZON_SHORT_TERM);
        self::assertTrue($ownerGoal->isStatusReachable(AgentGoal::STATUS_ACHIEVED));
        self::assertFalse($ownerGoal->isStatusReachable(AgentGoal::STATUS_BLOCKED));

        $agentGoal = $this->buildGoal('Every review is answered within two days', AgentGoal::HORIZON_SHORT_TERM);
        $agentGoal->holder = AgentGoal::HOLDER_AGENT;
        self::assertTrue($agentGoal->isStatusReachable(AgentGoal::STATUS_BLOCKED));
        self::assertFalse($agentGoal->isStatusReachable('FINISHED'), 'an unknown status is never reachable');
    }

    public function testAnOwnerGoalNeverHangsUnderAnAgentGoal(): void
    {
        $ownerGoal = $this->buildGoal('Weekend catering is booked out');
        $agentGoal = $this->buildGoal('Post the catering offer every Friday');
        $agentGoal->holder = AgentGoal::HOLDER_AGENT;

        self::assertFalse($ownerGoal->mayHaveParent($agentGoal));
        self::assertTrue($agentGoal->mayHaveParent($ownerGoal));
        self::assertTrue($agentGoal->mayHaveParent($agentGoal));
        self::assertTrue($ownerGoal->mayHaveParent($ownerGoal));
    }

    public function testAGoalIsHeldWhileCurrentAndLive(): void
    {
        $agentGoal = $this->buildGoal('Weekend catering is booked out');
        foreach (AgentGoal::STATUSES as $status) {
            $agentGoal->status = $status;
            self::assertSame(in_array($status, [AgentGoal::STATUS_ACTIVE, AgentGoal::STATUS_PROPOSED, AgentGoal::STATUS_BLOCKED], true), $agentGoal->isHeld(), $status);
        }
        $agentGoal->status = AgentGoal::STATUS_ACTIVE;
        $successorGoal = $this->buildGoal('Weekend catering is booked out through the autumn');
        $successorGoal->id = 9;
        $agentGoal->supersedeBy($successorGoal);

        self::assertFalse($agentGoal->isCurrentlyValid());
        self::assertFalse($agentGoal->isHeld(), 'a closed version is never held');
        self::assertSame(9, $agentGoal->supersededByGoalId);
        self::assertNotNull($agentGoal->validTo);
    }

    public function testARestatementCorrectsOnlyTheHorizonTheTargetDayOrADroppedGoal(): void
    {
        $storedGoal = $this->buildGoal('The summer menu is online', AgentGoal::HORIZON_SHORT_TERM, new Date('2027-05-31'));

        self::assertFalse($storedGoal->isCorrectedByRestatement($this->buildGoal('the summer menu is online', AgentGoal::HORIZON_SHORT_TERM, new Date('2027-05-31'))));
        self::assertTrue($storedGoal->isCorrectedByRestatement($this->buildGoal('The summer menu is online', AgentGoal::HORIZON_LONG_TERM, new Date('2027-05-31'))));
        self::assertTrue($storedGoal->isCorrectedByRestatement($this->buildGoal('The summer menu is online', AgentGoal::HORIZON_SHORT_TERM, new Date('2027-06-30'))));
        self::assertTrue($storedGoal->isCorrectedByRestatement($this->buildGoal('The summer menu is online', AgentGoal::HORIZON_SHORT_TERM)), 'a removed target day');

        $storedGoal->status = AgentGoal::STATUS_DROPPED;
        self::assertTrue($storedGoal->isCorrectedByRestatement($this->buildGoal('The summer menu is online', AgentGoal::HORIZON_SHORT_TERM, new Date('2027-05-31'))));
    }

    public function testConfirmingAProposalOfTheAgentMakesItConfirmedAndTheBlockedFlagsHoldOnlyOnBlocked(): void
    {
        $agentGoal = $this->buildGoal('Every review is answered within two days', AgentGoal::HORIZON_SHORT_TERM);
        $agentGoal->markHeldByOwnerOrAgent(false);
        $agentGoal->status = $agentGoal->deriveInitialStatus();

        $agentGoal->applyStatusTransition(AgentGoal::STATUS_ACTIVE, null, true, true);
        self::assertSame([AgentGoal::STATUS_ACTIVE, AgentGoal::PROVENANCE_CONFIRMED], [$agentGoal->status, $agentGoal->provenance]);
        self::assertFalse($agentGoal->requiresUserAction);
        self::assertFalse($agentGoal->retryable);

        $agentGoal->applyStatusTransition(AgentGoal::STATUS_BLOCKED, '  The review connection lapsed  ', true, false);
        self::assertSame('The review connection lapsed', $agentGoal->statusNote);
        self::assertTrue($agentGoal->requiresUserAction);
        self::assertFalse($agentGoal->retryable);

        $agentGoal->applyStatusTransition(AgentGoal::STATUS_ACHIEVED, str_repeat('n', 400), true, true);
        self::assertNotNull($agentGoal->achievedAt);
        self::assertFalse($agentGoal->requiresUserAction);
        self::assertSame(AgentGoal::MAX_ONE_LINE_CHARS, mb_strlen($agentGoal->statusNote), 'a long note is cut to one line');
        self::assertStringEndsWith('…', $agentGoal->statusNote);
    }

    public function testPreparingForPersistenceCutsAndStampsScopeAndHash(): void
    {
        $agentGoal = $this->buildGoal(str_repeat('w', AgentGoal::MAX_STATEMENT_CHARS + 40));
        $agentGoal->successSignal = str_repeat('s', 300);
        $agentGoal->anchorToScope(7, null);
        $agentGoal->prepareForPersistence();

        self::assertSame(AgentGoal::MAX_STATEMENT_CHARS, mb_strlen($agentGoal->statement));
        self::assertSame(AgentGoal::MAX_ONE_LINE_CHARS, mb_strlen($agentGoal->successSignal));
        self::assertSame('a:7', $agentGoal->scopeKey);
        self::assertSame(AgentGoal::hashForStatement($agentGoal->statement), $agentGoal->statementHash);

        $agentGoal->anchorToScope(7, 12);
        $agentGoal->prepareForPersistence();
        self::assertSame('s:12', $agentGoal->scopeKey);
        self::assertSame(AgentGoal::hashForStatement('Weekend  catering is BOOKED out'), AgentGoal::hashForStatement(" weekend catering\nis booked out "));
    }

    public function testTheLineStartsWithTheIdAndNamesTheAxesTheScopeAndWhatIsSet(): void
    {
        $agentGoal = $this->buildGoal('The summer menu is online', AgentGoal::HORIZON_SHORT_TERM, new Date('2027-05-31'));
        $agentGoal->id = 41;
        $agentGoal->anchorToScope(7, null);
        $agentGoal->priority = 2;
        $agentGoal->parentGoalId = 40;
        $agentGoal->blueprintKey = 'menu.online';
        $agentGoal->statusNote = 'Waiting for the photos';
        self::assertSame(
            '- #41 [OWNER/SHORT_TERM/ACTIVE/account] "The summer menu is online" (USER_STATED; target 2027-05-31; priority 2; blueprint menu.online; ↳ serves #40; Waiting for the photos)',
            $agentGoal->toLlmMarkdown()
        );

        $agentGoals = AgentGoals::newInstance();
        self::assertSame(AgentGoals::NO_GOALS_LINE, $agentGoals->toLlmMarkdown());
        $agentGoals->add($agentGoal);
        self::assertSame($agentGoal->toLlmMarkdown(), $agentGoals->toLlmMarkdown());
    }

    public function testTheHeldGoalsOfASetLeaveOutTheFinishedOnes(): void
    {
        $activeGoal = $this->buildGoal('Weekend catering is booked out');
        $activeGoal->id = 1;
        $droppedGoal = $this->buildGoal('A food truck at the market');
        $droppedGoal->id = 2;
        $droppedGoal->status = AgentGoal::STATUS_DROPPED;
        $agentGoals = AgentGoals::newInstance();
        $agentGoals->add($activeGoal, $droppedGoal);

        $heldGoals = $agentGoals->getHeldGoals();
        self::assertSame(1, $heldGoals->count());
        self::assertSame(1, (int)$heldGoals->first()?->id);
    }

    protected function buildGoal(string $statement, string $horizonLevel = AgentGoal::HORIZON_LONG_TERM, ?Date $targetDate = null): AgentGoal
    {
        return AgentGoal::buildStated($statement, $horizonLevel, null, null, $targetDate, 0, null);
    }
}
