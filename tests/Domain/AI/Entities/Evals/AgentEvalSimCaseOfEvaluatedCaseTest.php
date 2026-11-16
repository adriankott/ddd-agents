<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalJudgedCaseTexts;
use DDD\Domain\AI\Entities\Evals\AgentEvalJudgeVerdict;
use DDD\Domain\AI\Entities\Evals\AgentEvalSimCase;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * One fixture serves both flavors: the judged case of a tools case takes the prompt as the opening turn, the case's
 * own judge texts or the default texts, and carries the case's run keys in the judged run's form; every other key of
 * the case rides along behind them. A verdict the judge could not give is an evaluation error of the row.
 */
class AgentEvalSimCaseOfEvaluatedCaseTest extends UnitTestCase
{
    public function testTheJudgedCaseTakesTheDefaultTextsAndThePassThroughKeys(): void
    {
        $agentEvalCase = AgentEvalCase::fromArray([
            'id' => 'catalog.list',
            'prompt' => 'Which items do I have?',
            'expect' => ['tool' => ['listItems', 'getItems']],
            'agentSpaceId' => 7,
            'maxTurns' => 4,
            'maxStepsPerTurn' => 9,
            'runSettings' => ['maxStepsPerTurn' => 1],
            'compactionSettings' => ['triggerTokens' => 1000],
            'reportChannel' => 'EMAIL',
            'scheduledRun' => true,
            'purposeName' => 'Catalog.Review',
            'followUpPrompts' => ['and now?', 2],
            'followUpIdleMinutes' => ['5'],
            'blockedTools' => ['deleteItem'],
            'storeFixture' => ['open' => true],
        ]);

        $agentEvalSimCase = AgentEvalSimCase::fromEvaluatedCase($agentEvalCase, AgentEvalJudgedCaseTexts::forCase($agentEvalCase));

        $simCaseFields = $agentEvalSimCase->toArray();
        self::assertSame(
            ['goal', 'openingPrompt', 'userFacts', 'successRubric', 'maxTurns', 'runSettings', 'compactionSettings', 'reportChannel', 'scheduledRun', 'purposeName', 'followUpPrompts', 'followUpIdleMinutes', 'id', 'prompt', 'expect', 'agentSpaceId', 'maxStepsPerTurn', 'blockedTools', 'storeFixture'],
            array_keys($simCaseFields)
        );
        self::assertSame('The owner wants this answered or done: Which items do I have?', $agentEvalSimCase->getGoal());
        self::assertSame('Which items do I have?', $agentEvalSimCase->getOpeningPrompt());
        self::assertStringContainsString('owner of exactly this one workspace', $agentEvalSimCase->getOwnerFacts());
        self::assertStringContainsString('(intended tool context: listItems|getItems)', $agentEvalSimCase->getSuccessRubric());
        self::assertSame(['maxStepsPerTurn' => 9], $simCaseFields['runSettings'], 'the flat step limit wins over the case\'s own block');
        self::assertSame(4, $agentEvalSimCase->resolveMaxTurns(10));
        self::assertSame(['and now?', '2'], $agentEvalSimCase->getFollowUpPrompts());
        self::assertSame([5], $agentEvalSimCase->getFollowUpIdleMinutes());
        self::assertSame('Catalog.Review', $agentEvalSimCase->getPurposeName());
        self::assertTrue($agentEvalSimCase->isScheduledRun());
        self::assertSame(['open' => true], $agentEvalSimCase->getApplicationValue('storeFixture'), 'an application\'s own key rides along');
    }

    public function testTheCasesOwnTextsWinAndAbsentKeysStayAbsent(): void
    {
        $agentEvalCase = AgentEvalCase::fromArray(['id' => 'c', 'prompt' => 'p', 'goal' => 'own goal', 'userFacts' => 'own facts', 'successRubric' => 'own rubric', 'followUpPrompts' => [], 'scheduledRun' => false]);

        $simCaseFields = AgentEvalSimCase::fromEvaluatedCase($agentEvalCase, AgentEvalJudgedCaseTexts::forCase($agentEvalCase))->toArray();

        self::assertSame('own goal', $simCaseFields['goal']);
        self::assertSame('own facts', $simCaseFields['userFacts']);
        self::assertSame('own rubric', $simCaseFields['successRubric']);
        self::assertSame(['goal', 'openingPrompt', 'userFacts', 'successRubric', 'id', 'prompt', 'followUpPrompts', 'scheduledRun'], array_keys($simCaseFields));
        self::assertStringContainsString('(intended tool context: ?)', AgentEvalJudgedCaseTexts::forCase($agentEvalCase)->successRubric, 'a case without an expected tool');
    }

    public function testAnApplicationWritesItsOwnDefaultTexts(): void
    {
        $agentEvalCase = AgentEvalCase::fromArray(['id' => 'c', 'prompt' => 'Hello']);

        $simCaseFields = AgentEvalSimCase::fromEvaluatedCase($agentEvalCase, AgentEvalJudgedCaseTextsOfAnApplication::forCase($agentEvalCase))->toArray();

        self::assertSame('Wants: Hello', $simCaseFields['goal']);
        self::assertSame('facts of the application', $simCaseFields['userFacts']);
    }

    public function testAVerdictWithoutAVerdictIsAnEvaluationError(): void
    {
        $errorVerdict = AgentEvalJudgeVerdict::fromArray(['verdict' => 'FAIL', 'reasons' => 'x']);
        $errorVerdict->markAsEvaluationError('the answer was cut off twice');

        self::assertSame('judge evaluation error: the answer was cut off twice', $errorVerdict->findEvaluationError());
        self::assertSame('judge evaluation error: ', AgentEvalJudgeVerdict::fromArray(['verdict' => 'ERROR'])->findEvaluationError());
        self::assertNull(AgentEvalJudgeVerdict::fromArray(['verdict' => 'FAIL', 'reasons' => 'x'])->findEvaluationError());
        self::assertNull(AgentEvalJudgeVerdict::fromArray(['verdict' => 'PASS'])->findEvaluationError());
        self::assertNull(AgentEvalJudgeVerdict::fromArray([])->findEvaluationError());
    }
}
