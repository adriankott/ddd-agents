<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Evals\AgentEvalArms;
use DDD\Domain\AI\Entities\Evals\AgentEvalJudgedRunOptions;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunOptions;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The options of a judged run: built by the inherited factory as an object of their own class, with the arms of the
 * caller, and the driver and judge models — the ones set, else the service's defaults.
 */
class AgentEvalJudgedRunOptionsTest extends UnitTestCase
{
    public function testTheInheritedFactoryBuildsJudgedRunOptionsWithTheCallersArms(): void
    {
        $agentEvalArms = AgentEvalArms::newInstance();

        $agentEvalJudgedRunOptions = AgentEvalJudgedRunOptions::forArms($agentEvalArms);

        self::assertInstanceOf(AgentEvalJudgedRunOptions::class, $agentEvalJudgedRunOptions);
        self::assertInstanceOf(AgentEvalRunOptions::class, $agentEvalJudgedRunOptions);
        self::assertSame($agentEvalArms, $agentEvalJudgedRunOptions->getAgentEvalArms());
        self::assertNull($agentEvalJudgedRunOptions->driverModelName);
        self::assertNull($agentEvalJudgedRunOptions->judgeModelName);
    }

    public function testTheModelsSetWinOverTheDefaults(): void
    {
        $agentEvalJudgedRunOptions = AgentEvalJudgedRunOptions::forArms();

        self::assertSame('default-driver', $agentEvalJudgedRunOptions->resolveDriverModelName('default-driver'));
        self::assertSame('default-judge', $agentEvalJudgedRunOptions->resolveJudgeModelName('default-judge'));

        $agentEvalJudgedRunOptions->driverModelName = 'chosen-driver';
        $agentEvalJudgedRunOptions->judgeModelName = 'chosen-judge';

        self::assertSame('chosen-driver', $agentEvalJudgedRunOptions->resolveDriverModelName('default-driver'));
        self::assertSame('chosen-judge', $agentEvalJudgedRunOptions->resolveJudgeModelName('default-judge'));
    }
}
