<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Evals\AgentEvalNotApplicableReason;
use DDD\Domain\AI\Entities\Evals\AgentEvalNotApplicableReasons;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The unmet requirements of a case are held by their requirement name: one reason per name, in the order the hooks
 * gave them, found by the name; the name never reaches the stored row.
 */
class AgentEvalNotApplicableReasonsTest extends UnitTestCase
{
    public function testAReasonIsFoundByItsRequirementNameInTheOrderGiven(): void
    {
        $limitReason = AgentEvalNotApplicableReason::forUnmetRequirement('dailyLimit', 'limitReached', 'the space has used up its 5 new entries of the day');
        $heldReason = AgentEvalNotApplicableReason::forUnmetRequirement('calendarEdit', 'calendarHeldByOpenEdit', 'the calendar is held by the open edit #2370');
        $unmetRequirementReasons = AgentEvalNotApplicableReasons::newInstance();
        self::assertSame(['reason' => 'limitReached', 'detail' => 'the space has used up its 5 new entries of the day'], $limitReason->toArray(), 'the row keeps its two keys');

        $unmetRequirementReasons->add($limitReason, $heldReason);

        self::assertSame([$limitReason, $heldReason], $unmetRequirementReasons->getElements());
        self::assertSame($heldReason, $unmetRequirementReasons->getByRequirementName('calendarEdit'));
        self::assertSame('dailyLimit', $unmetRequirementReasons->getByRequirementName('dailyLimit')?->getRequirementName());
        self::assertNull($unmetRequirementReasons->getByRequirementName('websiteRebuild'));
    }

    public function testARequirementNameHoldsOneReasonAndTheFirstStays(): void
    {
        $firstReason = AgentEvalNotApplicableReason::forUnmetRequirement('dailyLimit', 'limitReached', 'first');
        $secondReason = AgentEvalNotApplicableReason::forUnmetRequirement('dailyLimit', 'limitReached', 'second');
        $unmetRequirementReasons = AgentEvalNotApplicableReasons::newInstance();

        $unmetRequirementReasons->add($firstReason, $secondReason);

        self::assertSame([$firstReason], $unmetRequirementReasons->getElements());
        self::assertSame('first', $unmetRequirementReasons->getByRequirementName('dailyLimit')?->getDetail());
    }

    public function testReasonsOfNoRequirementKeepTheKeyOfTheirOwnObject(): void
    {
        $firstReason = AgentEvalNotApplicableReason::withReasonAndDetail(AgentEvalNotApplicableReason::REASON_TOOL_NOT_AVAILABLE, 'same');
        $secondReason = AgentEvalNotApplicableReason::withReasonAndDetail(AgentEvalNotApplicableReason::REASON_TOOL_NOT_AVAILABLE, 'same');
        $notApplicableReasons = AgentEvalNotApplicableReasons::newInstance();
        self::assertSame('', $firstReason->getRequirementName());

        $notApplicableReasons->add($firstReason, $secondReason);

        self::assertSame(2, $notApplicableReasons->count());
    }
}
