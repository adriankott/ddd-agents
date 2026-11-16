<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Entities\Evals\AgentEvalJudgeVerdict;
use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Prompts\AIPrompt;
use DDD\Domain\AI\Services\Eval\AgentEvalJudgedRunService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\IntegrationTestCase;

/**
 * The judge of a judged run owns its retries: an empty or unparseable answer is asked once more before it counts as
 * the unparseable FAIL, a parseable answer is never asked twice, a verdict cut off by the output limit is judged once
 * more, and a second cut-off verdict is an evaluation error — never the salvaged verdict. A judge call the provider
 * refused is asked once more and then is an evaluation error with the provider's message — never the unparseable FAIL. Every judge call is booked
 * under the judge's usage scope; the default models are the service's instance methods.
 */
class AgentEvalJudgedRunServiceJudgeVerdictTest extends IntegrationTestCase
{
    protected const string CUT_OFF_PASS_ANSWER = '{"verdict":"PASS","scorePercent":91,"goalMet":true,"criteria":[{"name":"the entries are list';

    /**
     * @param string[] $judgeAnswers
     */
    protected function buildJudgeWith(array $judgeAnswers): AgentEvalJudgedRunServiceScriptedModelsDouble
    {
        $agentEvalJudgedRunService = new AgentEvalJudgedRunServiceScriptedModelsDouble();
        $agentEvalJudgedRunService->scriptedJudgeAnswers = $judgeAnswers;
        return $agentEvalJudgedRunService;
    }

    protected function buildScope(): AgentEvalScope
    {
        $ownerAccount = new Account();
        $ownerAccount->id = 5;
        return AgentEvalScope::forAgentSpace(null, $ownerAccount);
    }

    protected function judge(AgentEvalJudgedRunServiceScriptedModelsDouble $agentEvalJudgedRunService): AgentEvalJudgeVerdict
    {
        $verdictPrompt = AIPrompt::newInstance();
        $verdictPrompt->promptText = 'the verdict prompt';
        return $agentEvalJudgedRunService->judgeVerdict($this->buildScope(), 'judge-model', $verdictPrompt);
    }

    public function testAnEmptyAnswerIsAskedOnceMore(): void
    {
        $agentEvalJudgedRunService = $this->buildJudgeWith(['', '{"verdict":"PASS","scorePercent":90,"reasons":"ok"}']);

        $agentEvalJudgeVerdict = $this->judge($agentEvalJudgedRunService);

        self::assertSame('PASS', $agentEvalJudgeVerdict->getVerdict());
        self::assertCount(2, $agentEvalJudgedRunService->modelCalls);
    }

    public function testAParseableAnswerIsNeverAskedTwiceAndASecondEmptyAnswerStaysTheParseFail(): void
    {
        $parseableJudge = $this->buildJudgeWith(['{"verdict":"FAIL","reasons":"a part is missing"}', 'never asked']);
        self::assertSame('FAIL', $this->judge($parseableJudge)->getVerdict());
        self::assertCount(1, $parseableJudge->modelCalls);

        $emptyJudge = $this->buildJudgeWith(['', 'still nothing']);
        $agentEvalJudgeVerdict = $this->judge($emptyJudge);
        self::assertSame('FAIL', $agentEvalJudgeVerdict->getVerdict());
        self::assertSame('Judge output not parseable', $agentEvalJudgeVerdict->getReasons());
        self::assertTrue($agentEvalJudgeVerdict->isUnparseable());
        self::assertCount(2, $emptyJudge->modelCalls);
    }

    public function testACutOffVerdictIsJudgedOnceMoreAndTheCompleteOneCounts(): void
    {
        $agentEvalJudgedRunService = $this->buildJudgeWith([self::CUT_OFF_PASS_ANSWER, '{"verdict":"FAIL","scorePercent":40,"reasons":"no list"}']);

        $agentEvalJudgeVerdict = $this->judge($agentEvalJudgedRunService);

        self::assertSame(['verdict' => 'FAIL', 'scorePercent' => 40, 'reasons' => 'no list'], $agentEvalJudgeVerdict->toArray());
        self::assertCount(2, $agentEvalJudgedRunService->modelCalls);
    }

    public function testASecondCutOffVerdictIsAnEvaluationErrorNeverTheSalvagedPass(): void
    {
        $agentEvalJudgedRunService = $this->buildJudgeWith([self::CUT_OFF_PASS_ANSWER, self::CUT_OFF_PASS_ANSWER, 'never asked']);

        $agentEvalJudgeVerdict = $this->judge($agentEvalJudgedRunService);

        self::assertSame(AgentEvalJudgeVerdict::VERDICT_ERROR, $agentEvalJudgeVerdict->getVerdict());
        self::assertFalse($agentEvalJudgeVerdict->isPass());
        self::assertSame('Judge output truncated twice — evaluation error, not a verdict.', $agentEvalJudgeVerdict->getReasons());
        self::assertTrue($agentEvalJudgeVerdict->isTruncated(), 'the truncation flag stays');
        self::assertSame(91, $agentEvalJudgeVerdict->getScorePercent(), 'every other salvaged key stays where it is');
        self::assertCount(2, $agentEvalJudgedRunService->modelCalls);
    }

    public function testACutOffVerdictFollowedByAnEmptyAnswerIsAskedAgainBeforeItCounts(): void
    {
        $agentEvalJudgedRunService = $this->buildJudgeWith([self::CUT_OFF_PASS_ANSWER, '', '{"verdict":"PASS","scorePercent":95,"reasons":"ok"}']);

        $agentEvalJudgeVerdict = $this->judge($agentEvalJudgedRunService);

        self::assertSame('PASS', $agentEvalJudgeVerdict->getVerdict());
        self::assertFalse($agentEvalJudgeVerdict->isTruncated());
        self::assertCount(3, $agentEvalJudgedRunService->modelCalls);
    }

    public function testAFailedJudgeCallIsAnEvaluationErrorThatKeepsTheProvidersMessage(): void
    {
        $agentEvalJudgedRunService = $this->buildJudgeWith(['never read']);
        $agentEvalJudgedRunService->judgeCallFailureReason = 'http 404 from the upstream call: No endpoints found';

        $agentEvalJudgeVerdict = $this->judge($agentEvalJudgedRunService);

        self::assertSame(AgentEvalJudgeVerdict::VERDICT_ERROR, $agentEvalJudgeVerdict->getVerdict());
        self::assertFalse($agentEvalJudgeVerdict->isPass());
        self::assertSame('judge call failed: judge-model: http 404 from the upstream call: No endpoints found', $agentEvalJudgeVerdict->getReasons());
        self::assertTrue($agentEvalJudgeVerdict->isJudgeCallFailed());
        self::assertFalse($agentEvalJudgeVerdict->isUnparseable(), 'no answer was read: not the unparseable FAIL');
        self::assertSame('judge evaluation error: judge call failed: judge-model: http 404 from the upstream call: No endpoints found', $agentEvalJudgeVerdict->findEvaluationError());
        self::assertSame(['verdict', 'goalMet', 'grounded', 'reasons', AgentEvalJudgeVerdict::KEY_JUDGE_CALL_FAILED], array_keys($agentEvalJudgeVerdict->toArray()), 'the row keeps the shape of a verdict');
        self::assertCount(2, $agentEvalJudgedRunService->modelCalls, 'a failed call is asked once more');
    }

    public function testAJudgeCallThatFailsOnceIsAskedAgainAndTheAnswerCounts(): void
    {
        $agentEvalJudgedRunService = $this->buildJudgeWith(['{"verdict":"PASS","scorePercent":90,"reasons":"ok"}']);
        $agentEvalJudgedRunService->judgeCallFailureReason = 'http 503 from the upstream call: overloaded';
        $agentEvalJudgedRunService->remainingJudgeCallFailures = 1;

        $agentEvalJudgeVerdict = $this->judge($agentEvalJudgedRunService);

        self::assertSame('PASS', $agentEvalJudgeVerdict->getVerdict());
        self::assertFalse($agentEvalJudgeVerdict->isJudgeCallFailed());
        self::assertCount(2, $agentEvalJudgedRunService->modelCalls);
    }

    public function testEveryJudgeCallIsBookedUnderTheJudgesUsageScopeWithTheFilledPrompt(): void
    {
        $agentEvalJudgedRunService = $this->buildJudgeWith(['', '{"verdict":"PASS"}']);

        $this->judge($agentEvalJudgedRunService);

        self::assertSame([AgentAIUsageScope::AI_AGENT_JUDGE_EVALUATION, AgentAIUsageScope::AI_AGENT_JUDGE_EVALUATION], $agentEvalJudgedRunService->getUsageScopesOfModelCalls());
        self::assertSame(['judge-model', 'judge-model'], array_column($agentEvalJudgedRunService->modelCalls, 'aiModelName'));
        self::assertSame('the verdict prompt', $agentEvalJudgedRunService->modelCalls[0]['promptText']);
    }

    public function testTheDefaultModelsAreTheServicesOwnAndItIsResolvedThroughTheContainer(): void
    {
        $agentEvalJudgedRunService = DDDService::instance()->getService(AgentEvalJudgedRunService::class);

        self::assertInstanceOf(AgentEvalJudgedRunService::class, $agentEvalJudgedRunService);
        self::assertSame(AIModel::MODEL_ANTHROPIC_CLAUDE_OPUS_5_5, $agentEvalJudgedRunService->getDefaultJudgeModelName());
        self::assertSame(AIModel::MODEL_ANTHROPIC_CLAUDE_SONNET_5, $agentEvalJudgedRunService->getDefaultDriverModelName());
    }
}
