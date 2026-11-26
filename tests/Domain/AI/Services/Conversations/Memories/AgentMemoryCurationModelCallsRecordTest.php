<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Memories;

use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemories;
use DDD\Domain\AI\Entities\Conversations\Memories\Curation\AgentMemoryCurationModelCall;
use DDD\Domain\AI\Entities\Conversations\Memories\Curation\AgentMemoryCurationResult;
use DDD\Domain\AI\Entities\Conversations\Memories\Curation\AgentMemoryOperation;
use DDD\Domain\AI\Entities\Conversations\Memories\Curation\AgentMemoryOperations;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Infrastructure\Libs\Config;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The record of the curation's model calls, without a model ({@see AgentMemoryCurationModelCallsServiceDouble}): one
 * record per attempt with the stage, the model, the request body (never its headers), the answer and the tokens; both
 * attempts of a repeated call; the calls of the two reconcile branches in call order.
 */
class AgentMemoryCurationModelCallsRecordTest extends UnitTestCase
{
    protected const string ONE_CANDIDATE = '{"operations":[{"operation":"ADD","type":"FACT","subject":"BUSINESS","content":"has 4 refrigerated counters"}]}';

    protected const string ONE_NOOP = '{"operations":[{"operation":"NOOP","content":"has 4 refrigerated counters"}]}';

    protected function setUp(): void
    {
        parent::setUp();
        if (Config::getEnv('LAZYLOAD_DEFAULT_REPO_TYPE') === null) {
            LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
        }
    }

    public function testTheRecordOfOneCallKeepsStageModelBodyAnswerAndTokens(): void
    {
        $modelCallsServiceDouble = new AgentMemoryCurationModelCallsServiceDouble();
        $modelCallsServiceDouble->scriptedAnswers = [self::ONE_CANDIDATE];

        $extractionResult = $this->extractCandidates($modelCallsServiceDouble);

        self::assertSame(1, $extractionResult->modelCalls->count());
        $agentMemoryCurationModelCall = $extractionResult->modelCalls->first();
        self::assertSame(AgentMemoryCurationModelCall::STAGE_EXTRACTION, $agentMemoryCurationModelCall->stage);
        self::assertSame(AgentMemoryCurationModelCallsServiceDouble::MODEL_NAME, $agentMemoryCurationModelCall->aiModelName);
        self::assertSame(AgentMemoryCurationModelCallsServiceDouble::MODEL_NAME, $agentMemoryCurationModelCall->requestBody['model'] ?? null);
        self::assertSame('EXTRACTION request 0', $agentMemoryCurationModelCall->requestBody['messages'][0]['content'] ?? null);
        self::assertSame(self::ONE_CANDIDATE, $agentMemoryCurationModelCall->rawResultText);
        self::assertSame(self::ONE_CANDIDATE, $agentMemoryCurationModelCall->rawResponse['choices'][0]['message']['content'] ?? null);
        self::assertSame(100, $agentMemoryCurationModelCall->inputTokens);
        self::assertSame(10, $agentMemoryCurationModelCall->outputTokens);
        self::assertSame(1, $agentMemoryCurationModelCall->attempt);
        self::assertFalse($agentMemoryCurationModelCall->isRepeatedAttempt());
        self::assertSame(1, $extractionResult->operations->count(), 'recording changes nothing of the parsed answer');
    }

    public function testBothAttemptsOfARepeatedCallAreRecordedAlsoTheOneThrownAway(): void
    {
        $modelCallsServiceDouble = new AgentMemoryCurationModelCallsServiceDouble();
        $modelCallsServiceDouble->scriptedAnswers = ['I could not decide.', self::ONE_CANDIDATE];

        $extractionResult = $this->extractCandidates($modelCallsServiceDouble);

        self::assertTrue($extractionResult->schemaValid);
        self::assertSame(1, $extractionResult->operations->count());
        $modelCalls = $extractionResult->modelCalls->getElements();
        self::assertCount(2, $modelCalls);
        self::assertSame([1, 2], [$modelCalls[0]->attempt, $modelCalls[1]->attempt]);
        self::assertSame('I could not decide.', $modelCalls[0]->rawResultText, 'the thrown-away answer is kept');
        self::assertSame(self::ONE_CANDIDATE, $modelCalls[1]->rawResultText);
        self::assertTrue($modelCalls[1]->isRepeatedAttempt());
        self::assertSame(300, $extractionResult->modelCalls->sumInputTokens());
        self::assertSame(30, $extractionResult->modelCalls->sumOutputTokens());
    }

    public function testTwoAnswersOfTheWrongShapeKeepBothAttempts(): void
    {
        $modelCallsServiceDouble = new AgentMemoryCurationModelCallsServiceDouble();
        $modelCallsServiceDouble->scriptedAnswers = ['{"operations":"none"}', '{"operations":"none"}'];

        $extractionResult = $this->extractCandidates($modelCallsServiceDouble);

        self::assertFalse($extractionResult->schemaValid);
        self::assertSame(2, $extractionResult->modelCalls->count(), 'two attempts with identical content are both kept');
    }

    public function testTheCallsOfTheTwoReconcileBranchesAreMergedInCallOrder(): void
    {
        $modelCallsServiceDouble = new AgentMemoryCurationModelCallsServiceDouble();
        // the default branch answers in the wrong shape once, the resolution branch at once
        $modelCallsServiceDouble->scriptedAnswers = ['', self::ONE_NOOP, self::ONE_NOOP];
        $candidates = new AgentMemoryOperations();
        $countersCandidate = $this->buildCandidate('has 4 refrigerated counters', false);
        $openingCandidate = $this->buildCandidate('opens at 8', true);
        $candidates->add($countersCandidate, $openingCandidate);

        $reconciliationResult = $modelCallsServiceDouble->reconcileCandidatesRoutingByResolution(
            $candidates,
            AgentMemories::newInstance(),
            'the account 1 (account level)',
            'en'
        );

        $modelCalls = $reconciliationResult->modelCalls->getElements();
        self::assertSame(
            [
                AgentMemoryCurationModelCall::STAGE_RECONCILIATION,
                AgentMemoryCurationModelCall::STAGE_RECONCILIATION,
                AgentMemoryCurationModelCall::STAGE_CONFLICT_RESOLUTION,
            ],
            [$modelCalls[0]->stage, $modelCalls[1]->stage, $modelCalls[2]->stage]
        );
        self::assertSame([1, 2, 1], [$modelCalls[0]->attempt, $modelCalls[1]->attempt, $modelCalls[2]->attempt]);
        self::assertSame($modelCallsServiceDouble->loadedStages, [$modelCalls[0]->stage, $modelCalls[1]->stage, $modelCalls[2]->stage]);
        self::assertSame(2, $reconciliationResult->operations->count());
        self::assertSame(
            1,
            $reconciliationResult->modelCalls->getModelCallsOfStage(AgentMemoryCurationModelCall::STAGE_CONFLICT_RESOLUTION)->count()
        );
    }

    public function testTheHeadersOfTheRequestAreNeverCopied(): void
    {
        $modelCallsServiceDouble = new AgentMemoryCurationModelCallsServiceDouble();
        $modelCallsServiceDouble->scriptedAnswers = ['not json', self::ONE_CANDIDATE];

        $extractionResult = $this->extractCandidates($modelCallsServiceDouble);

        foreach ($extractionResult->modelCalls->getElements() as $agentMemoryCurationModelCall) {
            self::assertArrayNotHasKey('headers', $agentMemoryCurationModelCall->requestBody ?? []);
            self::assertStringNotContainsString(
                AgentMemoryCurationModelCallsServiceDouble::EGRESS_KEY,
                (string)json_encode($agentMemoryCurationModelCall->toObject(false))
            );
        }
        self::assertStringNotContainsString(
            AgentMemoryCurationModelCallsServiceDouble::EGRESS_KEY,
            (string)json_encode($extractionResult->toObject(false))
        );
    }

    protected function extractCandidates(AgentMemoryCurationModelCallsServiceDouble $modelCallsServiceDouble): AgentMemoryCurationResult
    {
        return $modelCallsServiceDouble->extractCandidates(AIConversationMessages::newInstance(), null, 'en', 'opening hours');
    }

    protected function buildCandidate(string $content, bool $resolvesConflict): AgentMemoryOperation
    {
        $agentMemoryOperation = new AgentMemoryOperation();
        $agentMemoryOperation->operation = AgentMemoryOperation::OPERATION_ADD;
        $agentMemoryOperation->content = $content;
        $agentMemoryOperation->resolvesConflict = $resolvesConflict;
        return $agentMemoryOperation;
    }
}
