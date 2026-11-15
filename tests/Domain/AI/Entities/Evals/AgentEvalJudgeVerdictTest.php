<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Evals\AgentEvalJudgeVerdict;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The judge's verdict read from its raw answer: the decoded object kept exactly (keys, order, value types), fences
 * removed, the first balanced object found behind trailing prose, the leading scalars of a cut-off answer salvaged and
 * marked as truncated, and an empty or unreadable answer turned into the unparseable FAIL. An evaluation error keeps
 * every other key in its place.
 */
class AgentEvalJudgeVerdictTest extends UnitTestCase
{
    public function testAParseableAnswerIsKeptExactly(): void
    {
        $judgeOutput = '{"verdict":"PASS","scorePercent":90,"criteria":[{"name":"answer","status":"met","note":"ok"}],'
            . '"goalMet":true,"grounded":true,"toolAppropriate":true,"clarificationOk":true,"ungroundedClaims":[],"reasons":"ok"}';

        $agentEvalJudgeVerdict = AgentEvalJudgeVerdict::fromJudgeOutput($judgeOutput);

        self::assertSame(json_decode($judgeOutput, true), $agentEvalJudgeVerdict->toArray());
        self::assertSame(
            ['verdict', 'scorePercent', 'criteria', 'goalMet', 'grounded', 'toolAppropriate', 'clarificationOk', 'ungroundedClaims', 'reasons'],
            array_keys($agentEvalJudgeVerdict->toArray())
        );
        self::assertTrue($agentEvalJudgeVerdict->isPass());
        self::assertSame(90, $agentEvalJudgeVerdict->getScorePercent());
        self::assertSame('ok', $agentEvalJudgeVerdict->getReasons());
        self::assertFalse($agentEvalJudgeVerdict->isTruncated());
        self::assertFalse($agentEvalJudgeVerdict->isUnparseable());
    }

    public function testAKeyOfTheTreeVerdictIsKeptLikeAnyOther(): void
    {
        $agentEvalJudgeVerdict = AgentEvalJudgeVerdict::fromJudgeOutput(
            '{"verdict":"FAIL","treeGrew":true,"rollUpsFormed":false,"recencyStaircase":true,"noGapsOrOverlaps":true,"factSurvived":false,"reasons":"no roll-up"}'
        );

        self::assertSame(
            ['verdict' => 'FAIL', 'treeGrew' => true, 'rollUpsFormed' => false, 'recencyStaircase' => true, 'noGapsOrOverlaps' => true, 'factSurvived' => false, 'reasons' => 'no roll-up'],
            $agentEvalJudgeVerdict->toArray()
        );
        self::assertFalse($agentEvalJudgeVerdict->isPass());
        self::assertTrue($agentEvalJudgeVerdict->getValue('treeGrew'));
    }

    public function testMarkdownFencesAndLeadingProseAreNoise(): void
    {
        $agentEvalJudgeVerdict = AgentEvalJudgeVerdict::fromJudgeOutput("Here is my verdict:\n```json\n{\"verdict\":\"FAIL\",\"reasons\":\"a part is missing\"}\n```");

        self::assertSame(['verdict' => 'FAIL', 'reasons' => 'a part is missing'], $agentEvalJudgeVerdict->toArray());
    }

    public function testTheFirstBalancedObjectIsReadBehindTrailingProse(): void
    {
        $agentEvalJudgeVerdict = AgentEvalJudgeVerdict::fromJudgeOutput('{"verdict":"FAIL","reasons":"a } inside and an \" escaped quote"} Note: see {the trace}.');

        self::assertSame(['verdict' => 'FAIL', 'reasons' => 'a } inside and an " escaped quote'], $agentEvalJudgeVerdict->toArray());
    }

    public function testTheBalancedScanSkipsBracesInsideStrings(): void
    {
        $text = 'x {"a":"{{","b":{"c":"}"}} tail }';

        self::assertSame('{"a":"{{","b":{"c":"}"}}', AgentEvalJudgeVerdict::findFirstBalancedObjectText($text, 2));
        self::assertNull(AgentEvalJudgeVerdict::findFirstBalancedObjectText('{"open":"never closed"', 0));
    }

    public function testTheScalarsOfACutOffAnswerAreSalvagedAndMarkedAsTruncated(): void
    {
        $judgeOutput = '{"verdict":"PASS","scorePercent":81,"goalMet":true,"grounded":false,"reasons":"a \"quoted\" word","criteria":[{"name":"answer","status":"me';

        $agentEvalJudgeVerdict = AgentEvalJudgeVerdict::fromJudgeOutput($judgeOutput);

        self::assertSame([
            'verdict' => 'PASS',
            'judgeOutputTruncated' => true,
            'reasons' => 'a "quoted" word',
            'raw' => $judgeOutput,
            'scorePercent' => 81,
            'goalMet' => true,
            'grounded' => false,
        ], $agentEvalJudgeVerdict->toArray());
        self::assertTrue($agentEvalJudgeVerdict->isTruncated());
        self::assertFalse($agentEvalJudgeVerdict->isUnparseable());
    }

    public function testACutOffAnswerWithoutReasonsKeepsTheSalvageSentence(): void
    {
        $agentEvalJudgeVerdict = AgentEvalJudgeVerdict::fromJudgeOutput('{"verdict":"FAIL","scorePercent":40,"criteria":[');

        self::assertSame([
            'verdict' => 'FAIL',
            'judgeOutputTruncated' => true,
            'reasons' => 'Judge JSON truncated/unparseable — verdict scalars salvaged from the head.',
            'raw' => '{"verdict":"FAIL","scorePercent":40,"criteria":[',
            'scorePercent' => 40,
        ], $agentEvalJudgeVerdict->toArray());
    }

    public function testAnEmptyAnswerIsTheUnparseableFail(): void
    {
        $agentEvalJudgeVerdict = AgentEvalJudgeVerdict::fromJudgeOutput('');

        self::assertSame(
            ['verdict' => 'FAIL', 'goalMet' => false, 'grounded' => false, 'reasons' => 'Judge output not parseable', 'judgeOutputUnparseable' => true, 'raw' => ''],
            $agentEvalJudgeVerdict->toArray()
        );
        self::assertTrue($agentEvalJudgeVerdict->isUnparseable());
        self::assertFalse($agentEvalJudgeVerdict->isTruncated());
    }

    public function testAnUnreadableAnswerKeepsTheHeadOfItsText(): void
    {
        $judgeOutput = 'still nothing ' . str_repeat('x', 600);

        $verdictFields = AgentEvalJudgeVerdict::fromJudgeOutput($judgeOutput)->toArray();

        self::assertSame('Judge output not parseable', $verdictFields['reasons']);
        self::assertSame(mb_substr($judgeOutput, 0, 500), $verdictFields['raw']);
    }

    public function testAnEvaluationErrorKeepsEveryOtherKeyInItsPlace(): void
    {
        $agentEvalJudgeVerdict = AgentEvalJudgeVerdict::fromJudgeOutput('{"verdict":"PASS","scorePercent":81,"criteria":[');

        $agentEvalJudgeVerdict->markAsEvaluationError('Judge output truncated twice — evaluation error, not a verdict.');

        self::assertSame([
            'verdict' => 'ERROR',
            'judgeOutputTruncated' => true,
            'reasons' => 'Judge output truncated twice — evaluation error, not a verdict.',
            'raw' => '{"verdict":"PASS","scorePercent":81,"criteria":[',
            'scorePercent' => 81,
        ], $agentEvalJudgeVerdict->toArray());
        self::assertSame(AgentEvalJudgeVerdict::VERDICT_ERROR, $agentEvalJudgeVerdict->getVerdict());
        self::assertFalse($agentEvalJudgeVerdict->isPass());
    }

    public function testAVerdictBuiltFromAnArrayIsKeptAsItIs(): void
    {
        $verdictFields = ['reasons' => 'first', 'verdict' => 'PASS', 'extra' => ['nested' => 1]];

        self::assertSame($verdictFields, AgentEvalJudgeVerdict::fromArray($verdictFields)->toArray());
        self::assertNull(AgentEvalJudgeVerdict::fromArray([])->getVerdict());
    }
}
