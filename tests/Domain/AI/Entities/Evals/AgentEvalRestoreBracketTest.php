<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Evals\AgentEvalHarnessToolCall;
use DDD\Domain\AI\Entities\Evals\AgentEvalHarnessToolCalls;
use DDD\Domain\AI\Entities\Evals\AgentEvalRestoreBracket;
use DDD\Domain\AI\Entities\Evals\AgentEvalRestorePlan;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * What an opened restore bracket holds: the plan, the provenance, the tool packages, the arguments the platform fills
 * and the start time as given; nothing captured and no removal recorded until the opening records them, each bracket
 * with its own set of removals.
 */
class AgentEvalRestoreBracketTest extends UnitTestCase
{
    public function testABracketAboutToBeOpenedHoldsWhatItWasGivenAndNothingElse(): void
    {
        $agentEvalRestorePlan = AgentEvalRestorePlan::fromRestoreBlock(['readTool' => 'getEntry', 'capture' => ['title' => 'title'], 'writeTool' => 'updateEntry']);
        /** @var AgentToolCallProvenance $harnessToolCallProvenance */
        $harnessToolCallProvenance = self::instantiateWithoutConstructor(AgentToolCallProvenance::class);
        $harnessCallsStartedAt = new DateTime();

        $agentEvalRestoreBracket = AgentEvalRestoreBracket::forRestorePlan(
            $agentEvalRestorePlan,
            $harnessToolCallProvenance,
            ['First\\EntriesMCPPackage'],
            ['agentSpaceId' => 7],
            $harnessCallsStartedAt
        );

        self::assertSame($agentEvalRestorePlan, $agentEvalRestoreBracket->agentEvalRestorePlan);
        self::assertSame($harnessToolCallProvenance, $agentEvalRestoreBracket->harnessToolCallProvenance);
        self::assertSame(['First\\EntriesMCPPackage'], $agentEvalRestoreBracket->packageClasses);
        self::assertSame(['agentSpaceId' => 7], $agentEvalRestoreBracket->argumentsFilledByThePlatform);
        self::assertSame($harnessCallsStartedAt, $agentEvalRestoreBracket->harnessCallsStartedAt);
        self::assertSame([], $agentEvalRestoreBracket->capturedValues);
        self::assertInstanceOf(AgentEvalHarnessToolCalls::class, $agentEvalRestoreBracket->undoCalls);
        self::assertTrue($agentEvalRestoreBracket->undoCalls->isEmpty());
    }

    public function testEachBracketKeepsItsOwnRemovalsAndCapturedValues(): void
    {
        /** @var AgentToolCallProvenance $harnessToolCallProvenance */
        $harnessToolCallProvenance = self::instantiateWithoutConstructor(AgentToolCallProvenance::class);
        $firstBracket = AgentEvalRestoreBracket::forRestorePlan(AgentEvalRestorePlan::fromRestoreBlock([]), $harnessToolCallProvenance, [], [], new DateTime());
        $secondBracket = AgentEvalRestoreBracket::forRestorePlan(AgentEvalRestorePlan::fromRestoreBlock([]), $harnessToolCallProvenance, [], [], new DateTime());

        $undoCall = AgentEvalHarnessToolCall::forTool(AgentEvalHarnessToolCall::STEP_UNDO, 'deleteEntry', ['entryId' => 5]);
        $firstBracket->undoCalls->add($undoCall);
        $firstBracket->capturedValues = ['title' => 'Spring offer'];

        self::assertSame(1, $firstBracket->undoCalls->count());
        self::assertTrue($secondBracket->undoCalls->isEmpty());
        self::assertSame([], $secondBracket->capturedValues);
    }
}
