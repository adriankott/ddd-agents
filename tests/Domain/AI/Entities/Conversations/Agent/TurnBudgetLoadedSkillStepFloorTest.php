<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AIConversationTurnBudget;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Entities\Skills\AISkills;
use PHPUnit\Framework\TestCase;

/**
 * A loaded skill's `turnMinimumSteps` raises the step budget of the turn like a tool-declared floor: under the planned
 * turn's ceiling, with the planned turn's default cost cap, and never over explicit run settings. Without a floor the
 * budget stays the plain default.
 */
class TurnBudgetLoadedSkillStepFloorTest extends TestCase
{
    protected function buildConversation(?RunSettings $runSettings = null): AIConversation
    {
        $conversation = new AIConversation();
        $conversation->messages = new AIConversationMessages();
        if ($runSettings !== null) {
            $conversation->runSettings = $runSettings;
        }
        return $conversation;
    }

    public function testWithoutAFloorTheTurnKeepsThePlainDefault(): void
    {
        $turnBudget = AIConversationTurnBudget::buildForConversation($this->buildConversation(), false);
        self::assertSame(AIConversationTurnBudget::MAX_STEPS, $turnBudget->totalMaxSteps);
        self::assertNull($turnBudget->maxTurnCostUsd);
    }

    public function testALoadedSkillsFloorRaisesTheBudgetWithTheDefaultCostCap(): void
    {
        $turnBudget = AIConversationTurnBudget::buildForConversation($this->buildConversation(), false, 30);
        self::assertSame(30, $turnBudget->totalMaxSteps);
        self::assertSame(AIConversationTurnBudget::PLANNED_TURN_DEFAULT_COST_CAP_USD, $turnBudget->maxTurnCostUsd);
    }

    public function testTheFloorIsCappedByThePlannedTurnCeiling(): void
    {
        $turnBudget = AIConversationTurnBudget::buildForConversation($this->buildConversation(), false, 500);
        self::assertSame(AIConversationTurnBudget::PLANNED_TURN_MAX_STEPS_CEILING, $turnBudget->totalMaxSteps);
    }

    public function testAFloorBelowTheDefaultChangesNothingButTheCostCap(): void
    {
        $turnBudget = AIConversationTurnBudget::buildForConversation($this->buildConversation(), false, 5);
        self::assertSame(AIConversationTurnBudget::MAX_STEPS, $turnBudget->totalMaxSteps);
    }

    public function testExplicitRunSettingsWinOverTheFloor(): void
    {
        $runSettings = RunSettings::newInstance();
        $runSettings->maxStepsPerTurn = 8;
        $turnBudget = AIConversationTurnBudget::buildForConversation($this->buildConversation($runSettings), false, 30);
        self::assertSame(8, $turnBudget->totalMaxSteps);
    }

    public function testTheHigherOfToolAndSkillFloorsCounts(): void
    {
        self::assertNull(AIConversationTurnBudget::combineStepFloors(null, null));
        self::assertSame(30, AIConversationTurnBudget::combineStepFloors(null, 30));
        self::assertSame(40, AIConversationTurnBudget::combineStepFloors(40, 30));
        self::assertSame(30, AIConversationTurnBudget::combineStepFloors(20, 30));
        self::assertSame(20, AIConversationTurnBudget::combineStepFloors(20, null));
    }

    public function testTheFrontmatterValueIsAPositiveWholeNumber(): void
    {
        self::assertSame(30, AISkill::readTurnMinimumStepsOfFrontmatter(30));
        self::assertSame(30, AISkill::readTurnMinimumStepsOfFrontmatter('30'));
        self::assertNull(AISkill::readTurnMinimumStepsOfFrontmatter(0));
        self::assertNull(AISkill::readTurnMinimumStepsOfFrontmatter(-4));
        self::assertNull(AISkill::readTurnMinimumStepsOfFrontmatter('many'));
        self::assertNull(AISkill::readTurnMinimumStepsOfFrontmatter(2.5));
        self::assertNull(AISkill::readTurnMinimumStepsOfFrontmatter(null));
    }

    public function testTheHighestFloorOfTheLoadedSkillsCounts(): void
    {
        $aISkills = new AISkills();
        self::assertNull($aISkills->findHighestTurnMinimumSteps());
        foreach (['Demo.Plain' => null, 'Demo.Deck' => 30, 'Demo.Report' => 24] as $skillName => $turnMinimumSteps) {
            $aISkill = new AISkill();
            $aISkill->name = $skillName;
            $aISkill->turnMinimumSteps = $turnMinimumSteps;
            $aISkills->add($aISkill);
        }
        self::assertSame(30, $aISkills->findHighestTurnMinimumSteps());
    }
}
