<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\Conversations\Decisions;

use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentStallRecoveryDecision;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;

/**
 * An answer without an `answers` object and with a non-transport status keeps why it is unusable
 * (`http <status>: <message>`, the provider's message cleaned and cut); a transport status and a usable answer keep none.
 */
class ArgusAgentDecisionUnusableAnswerReasonTest extends UnitTestCase
{
    public function testAnErrorBodyOfTheDirectRouteKeepsItsStatusAndMessage(): void
    {
        $decision = $this->handle((object)['error' => (object)['message' => 'example-model is not a valid model ID', 'code' => 400]], 400);

        self::assertFalse($decision->decided);
        self::assertFalse($decision->transportFailed);
        self::assertSame('http 400: example-model is not a valid model ID', $decision->unusableAnswerReason);
    }

    public function testTheMessageIsUntrustedTextCleanedAndCut(): void
    {
        $decision = $this->handle((object)['error' => (object)['message' => "line one\nline two\t\x07" . str_repeat('y', 400)]], 402);

        self::assertStringStartsWith('http 402: line one line two ', (string)$decision->unusableAnswerReason);
        self::assertSame(
            mb_strlen('http 402: ') + ArgusAgentStallRecoveryDecision::UNUSABLE_ANSWER_MESSAGE_MAX_LENGTH,
            mb_strlen((string)$decision->unusableAnswerReason)
        );
        self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', (string)$decision->unusableAnswerReason);
    }

    public function testAResponseWithoutMessageNamesTheMissingAnswers(): void
    {
        $decision = $this->handle((object)['model' => 'example/decision-1'], 200);

        self::assertSame('http 200: no answers object', $decision->unusableAnswerReason);
    }

    public function testATransportStatusKeepsNoUnusableAnswerReason(): void
    {
        $decision = $this->handle((object)['error' => (object)['message' => 'overloaded']], 503);

        self::assertTrue($decision->transportFailed);
        self::assertNull($decision->unusableAnswerReason);
    }

    public function testAUsableAnswerKeepsNoUnusableAnswerReason(): void
    {
        $decision = $this->handle((object)['model' => 'example/decision-1', 'answers' => (object)['next_move' => (object)['choice' => 'none', 'confidence' => 0.9]]], 200);

        self::assertNull($decision->unusableAnswerReason);
    }

    protected function handle(object $responseBody, int $httpStatusCode): ArgusAgentStallRecoveryDecision
    {
        $decision = new ArgusAgentStallRecoveryDecision();
        $decision->offeredMoves = ['none'];
        /** @var ArgusApiOperation $apiOperation */
        $apiOperation = (new ReflectionClass(ArgusApiOperation::class))->newInstanceWithoutConstructor();
        $apiOperation->responseStatusCode = $httpStatusCode;
        $decision->handleLoadResponseInternal($responseBody, $apiOperation);
        return $decision;
    }
}
