<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Memories\Curation;

use DDD\Domain\AI\Entities\Conversations\Memories\Curation\AgentMemoryCurationModelCall;
use DDD\Domain\AI\Entities\Conversations\Memories\Curation\AgentMemoryCurationModelCalls;
use DDD\Domain\AI\Entities\Conversations\Memories\Curation\AgentMemoryCurationResult;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The record of one model call of the curation and its set: only the request body is taken (the headers carry the
 * egress authentication), two attempts with identical content are both kept, and a merge of two results keeps the
 * calls of both sides in call order.
 */
class AgentMemoryCurationModelCallTest extends UnitTestCase
{
    public function testOnlyTheBodyOfARequestPayloadIsTakenNeverItsHeaders(): void
    {
        $agentMemoryCurationModelCall = new AgentMemoryCurationModelCall();
        $agentMemoryCurationModelCall->takeRequestBodyOfPayload([
            'body' => ['model' => 'test/model', 'messages' => [['role' => 'user', 'content' => 'hello']]],
            'headers' => ['Authorization' => 'Bearer secret-egress-key'],
        ]);

        self::assertSame(['model' => 'test/model', 'messages' => [['role' => 'user', 'content' => 'hello']]], $agentMemoryCurationModelCall->requestBody);
        self::assertStringNotContainsString('secret-egress-key', (string)json_encode($agentMemoryCurationModelCall->toObject(false)));

        $agentMemoryCurationModelCall->takeRequestBodyOfPayload(['headers' => ['Authorization' => 'Bearer secret-egress-key']]);
        self::assertNull($agentMemoryCurationModelCall->requestBody, 'a payload without a body records no body, never the headers');
    }

    public function testTheRawResponseIsKeptDecoded(): void
    {
        $agentMemoryCurationModelCall = new AgentMemoryCurationModelCall();
        $agentMemoryCurationModelCall->takeRawResponse((object)['choices' => [(object)['message' => (object)['content' => '{}']]], 'usage' => (object)['prompt_tokens' => 12]]);

        self::assertSame('{}', $agentMemoryCurationModelCall->rawResponse['choices'][0]['message']['content'] ?? null);
        self::assertSame(12, $agentMemoryCurationModelCall->rawResponse['usage']['prompt_tokens'] ?? null);

        $agentMemoryCurationModelCall->takeRawResponse(null);
        self::assertNull($agentMemoryCurationModelCall->rawResponse);
    }

    public function testTwoAttemptsWithIdenticalContentAreBothKept(): void
    {
        $agentMemoryCurationModelCalls = new AgentMemoryCurationModelCalls();
        $agentMemoryCurationModelCall = $this->buildModelCall(AgentMemoryCurationModelCall::STAGE_EXTRACTION, 1);
        $agentMemoryCurationModelCalls->add($agentMemoryCurationModelCall);
        $agentMemoryCurationModelCall = $this->buildModelCall(AgentMemoryCurationModelCall::STAGE_EXTRACTION, 1);
        $agentMemoryCurationModelCalls->add($agentMemoryCurationModelCall);

        self::assertSame(2, $agentMemoryCurationModelCalls->count());
    }

    public function testAMergeKeepsTheCallsOfBothSidesInCallOrder(): void
    {
        $reconciliationResult = new AgentMemoryCurationResult();
        $agentMemoryCurationModelCall = $this->buildModelCall(AgentMemoryCurationModelCall::STAGE_RECONCILIATION, 1);
        $reconciliationResult->modelCalls->add($agentMemoryCurationModelCall);
        $agentMemoryCurationModelCall = $this->buildModelCall(AgentMemoryCurationModelCall::STAGE_RECONCILIATION, 2);
        $reconciliationResult->modelCalls->add($agentMemoryCurationModelCall);
        $resolutionResult = new AgentMemoryCurationResult();
        $agentMemoryCurationModelCall = $this->buildModelCall(AgentMemoryCurationModelCall::STAGE_CONFLICT_RESOLUTION, 1);
        $resolutionResult->modelCalls->add($agentMemoryCurationModelCall);
        $mergedResult = new AgentMemoryCurationResult();

        $mergedResult->mergeResult($reconciliationResult);
        $mergedResult->mergeResult($resolutionResult);

        $mergedCalls = $mergedResult->modelCalls->getElements();
        self::assertCount(3, $mergedCalls);
        self::assertSame(
            [AgentMemoryCurationModelCall::STAGE_RECONCILIATION, AgentMemoryCurationModelCall::STAGE_RECONCILIATION, AgentMemoryCurationModelCall::STAGE_CONFLICT_RESOLUTION],
            [$mergedCalls[0]->stage, $mergedCalls[1]->stage, $mergedCalls[2]->stage]
        );
        self::assertSame([1, 2, 1], [$mergedCalls[0]->attempt, $mergedCalls[1]->attempt, $mergedCalls[2]->attempt]);
    }

    public function testTheTokensAreSummedPerStage(): void
    {
        $agentMemoryCurationModelCalls = new AgentMemoryCurationModelCalls();
        $agentMemoryCurationModelCall = $this->buildModelCall(AgentMemoryCurationModelCall::STAGE_EXTRACTION, 1, 400, 40);
        $agentMemoryCurationModelCalls->add($agentMemoryCurationModelCall);
        $agentMemoryCurationModelCall = $this->buildModelCall(AgentMemoryCurationModelCall::STAGE_RECONCILIATION, 1, 900, 90);
        $agentMemoryCurationModelCalls->add($agentMemoryCurationModelCall);
        $agentMemoryCurationModelCall = $this->buildModelCall(AgentMemoryCurationModelCall::STAGE_RECONCILIATION, 2, null, null);
        $agentMemoryCurationModelCalls->add($agentMemoryCurationModelCall);

        $reconciliationCalls = $agentMemoryCurationModelCalls->getModelCallsOfStage(AgentMemoryCurationModelCall::STAGE_RECONCILIATION);
        self::assertSame(2, $reconciliationCalls->count());
        self::assertSame(900, $reconciliationCalls->sumInputTokens(), 'a call without a reported count adds nothing');
        self::assertSame(90, $reconciliationCalls->sumOutputTokens());
        self::assertSame(1300, $agentMemoryCurationModelCalls->sumInputTokens());
    }

    protected function buildModelCall(string $stage, int $attempt, ?int $inputTokens = 100, ?int $outputTokens = 10): AgentMemoryCurationModelCall
    {
        $agentMemoryCurationModelCall = new AgentMemoryCurationModelCall();
        $agentMemoryCurationModelCall->stage = $stage;
        $agentMemoryCurationModelCall->attempt = $attempt;
        $agentMemoryCurationModelCall->aiModelName = 'test/model';
        $agentMemoryCurationModelCall->requestBody = ['model' => 'test/model'];
        $agentMemoryCurationModelCall->rawResultText = '{"operations":[]}';
        $agentMemoryCurationModelCall->inputTokens = $inputTokens;
        $agentMemoryCurationModelCall->outputTokens = $outputTokens;
        return $agentMemoryCurationModelCall;
    }
}
