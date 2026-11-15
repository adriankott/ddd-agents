<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Domain\AI\Entities\Evals\AgentEvalHarnessToolCall;
use DDD\Domain\AI\Exceptions\Evals\AgentEvalPreconditionNotEstablishedException;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * What the harness derives from the result of its own calls around a case: the call that removes what a preparation
 * step created, the removal that left its record, the preparation step that failed or lacks the premise of the case,
 * the arguments the executor receives and the report line of each call.
 */
class AgentEvalHarnessToolCallTest extends UnitTestCase
{
    protected const string CREATED_NOTE_RESULT = '{"content":[{"type":"text","text":"[#7547178 · NOTE · DRAFT] · channels: none\nEval note"}],"isError":false}';

    /**
     * A preparation step with its recorded result.
     *
     * @param array<string, mixed> $stepBlock
     */
    protected function setupCallWithResult(array $stepBlock, string $resultContent, bool $resultIsError = false): AgentEvalHarnessToolCall
    {
        $setupCall = AgentEvalHarnessToolCall::fromStepBlock(AgentEvalHarnessToolCall::STEP_SETUP, $stepBlock);
        self::assertNotNull($setupCall);
        $setupCall->recordResult($resultContent, $resultIsError);
        return $setupCall;
    }

    /** A removal call (an undo) with its recorded result. */
    protected function removalWithResult(string $resultContent, bool $resultIsError): AgentEvalHarnessToolCall
    {
        $removalCall = AgentEvalHarnessToolCall::forTool(AgentEvalHarnessToolCall::STEP_UNDO, 'deleteNote', ['noteId' => 1], true);
        $removalCall->recordResult($resultContent, $resultIsError);
        return $removalCall;
    }

    public function testTheUndoCallOfASetupStepTakesItsArgumentsFromTheStepsResult(): void
    {
        $setupCall = $this->setupCallWithResult(
            [
                'tool' => 'createNote',
                'args' => ['content' => 'Eval note'],
                'undo' => ['tool' => 'deleteNote', 'argsFromResult' => ['noteId' => ['path' => 'note.id', 'regex' => '\[#(\d+)']]],
            ],
            self::CREATED_NOTE_RESULT
        );

        $undoCall = $setupCall->buildUndoCall();

        self::assertNotNull($undoCall);
        self::assertSame(['tool' => 'deleteNote', 'args' => ['noteId' => '7547178']], $undoCall->toArray());
        self::assertSame(AgentEvalHarnessToolCall::STEP_UNDO, $undoCall->step);
        self::assertTrue($undoCall->asksForStructuredOutput, 'a removal reads the deleted flag of the structured result');
    }

    public function testAStepWithoutUndoOrWithAResultThatCarriesNoValueHasNoUndoCall(): void
    {
        self::assertNull($this->setupCallWithResult(['tool' => 'createNote', 'args' => []], self::CREATED_NOTE_RESULT)->buildUndoCall());
        self::assertNull($this->setupCallWithResult(
            ['tool' => 'createNote', 'undo' => ['tool' => 'deleteNote', 'argsFromResult' => ['noteId' => ['regex' => '\[#(\d+)']]]],
            '{"content":[{"type":"text","text":"Execution failed for Tool \'createNote\'"}],"isError":true}',
            true
        )->buildUndoCall());
    }

    public function testTheUndoCallKeepsItsFixedArgumentsBesideTheReadOnes(): void
    {
        $setupCall = $this->setupCallWithResult(
            ['tool' => 'createNote', 'undo' => ['tool' => 'deleteNote', 'args' => ['hard' => true], 'argsFromResult' => ['noteId' => ['regex' => '\[#(\d+)']]]],
            self::CREATED_NOTE_RESULT
        );

        self::assertSame(['tool' => 'deleteNote', 'args' => ['hard' => true, 'noteId' => '7547178']], $setupCall->buildUndoCall()?->toArray());
    }

    public function testTheLogTextOfARemovalThatFoundNothingCarriesNoErrorFlag(): void
    {
        $notFound = '{"content":[{"type":"text","text":"Execution failed for Tool \'deleteNote\': Note 1 not found."}],"isError":true}';

        $logText = $this->removalWithResult($notFound, true)->describeRemovalResult();

        self::assertStringContainsString('Note 1 not found', $logText);
        self::assertStringNotContainsString('isError', $logText);
        self::assertStringNotContainsString('[ERROR]', $logText);
        self::assertSame('{"structuredContent":{"deleted":true}}', $this->removalWithResult('{"structuredContent":{"deleted":true}}', false)->describeRemovalResult());
    }

    public function testARemovalThatLeftTheObjectIsTheOneWhoseResultSaysSo(): void
    {
        self::assertTrue($this->removalWithResult('{"structuredContent":{"noteId":1,"deleted":false,"stillPublishedOn":["EXAMPLE"]}}', false)->isRemovalThatLeftTheObject());
        self::assertFalse($this->removalWithResult('{"structuredContent":{"noteId":1,"deleted":true}}', false)->isRemovalThatLeftTheObject());
        self::assertFalse($this->removalWithResult('{"content":[{"type":"text","text":"Note 1 not found."}],"isError":true}', true)->isRemovalThatLeftTheObject());
    }

    public function testAPreparationStepThatAnsweredAnErrorEndsTheCaseBeforeTheAgentRuns(): void
    {
        $refusedReply = '{"content":[{"type":"text","text":"Execution failed for Tool publishReply: There was a problem on publishing your reply."}],"isError":true}';
        $setupCall = $this->setupCallWithResult(['tool' => 'publishReply'], $refusedReply, true);

        try {
            $setupCall->refuseCaseWhosePreparationStepFailed();
            self::fail('a failed preparation step must end the case');
        } catch (AgentEvalPreconditionNotEstablishedException $preconditionNotEstablished) {
            self::assertStringContainsString("the preparation step 'publishReply' of the case failed", $preconditionNotEstablished->getMessage());
            self::assertStringContainsString('There was a problem on publishing your reply.', $preconditionNotEstablished->getMessage());
            self::assertStringNotContainsString('isError', $preconditionNotEstablished->getMessage());
        }
    }

    public function testAPreparationStepThatSucceededLetsTheCaseRun(): void
    {
        $this->setupCallWithResult(['tool' => 'createNote'], self::CREATED_NOTE_RESULT)->refuseCaseWhosePreparationStepFailed();

        $this->addToAssertionCount(1);
    }

    public function testAReadingPreparationStepWithoutThePremiseOfTheCaseEndsTheCaseBeforeTheAgentRuns(): void
    {
        $stepBlock = ['tool' => 'listEntries', 'args' => ['top' => 20], 'resultContains' => ['example-source:gr1_']];
        $entriesOfAConnectedSource = '{"content":[{"type":"text","text":"Entries (2)\n- 5/5 `example-source:gr1_YS8xMTQw` reply:yes"}],"isError":false}';
        $entriesWithoutAConnectedSource = '{"content":[{"type":"text","text":"Entries (2)\n- 5/5 `example-source:Ci9DQUlRQUNv` reply:yes"}],"isError":false}';

        $this->setupCallWithResult($stepBlock, $entriesOfAConnectedSource)->refuseCaseWhosePreparationStepLacksExpectedResult();
        $this->setupCallWithResult(['tool' => 'createNote', 'args' => []], $entriesWithoutAConnectedSource)->refuseCaseWhosePreparationStepLacksExpectedResult();

        try {
            $this->setupCallWithResult($stepBlock, $entriesWithoutAConnectedSource)->refuseCaseWhosePreparationStepLacksExpectedResult();
            self::fail('a read that does not show the premise of the case must end the case');
        } catch (AgentEvalPreconditionNotEstablishedException $preconditionNotEstablished) {
            self::assertSame(
                "the preparation step 'listEntries' of the case does not show 'example-source:gr1_' on this "
                . AgentApplicationWording::current()->agentSpaceName . ', the agent was not run',
                $preconditionNotEstablished->getMessage()
            );
        }
    }

    public function testTheSentenceOfAMissingPremiseNamesTheAgentSpaceWithTheApplicationsWord(): void
    {
        $setupCall = $this->setupCallWithResult(['tool' => 'listEntries', 'resultContains' => ['needle']], '{"content":[{"type":"text","text":"haystack"}]}');
        $setupCall->agentSpaceName = 'studio';

        $this->expectExceptionMessage("the preparation step 'listEntries' of the case does not show 'needle' on this studio, the agent was not run");

        $setupCall->refuseCaseWhosePreparationStepLacksExpectedResult();
    }

    public function testOneStepOrAListOfStepsGivesTheCallsInOrderAndSkipsEntriesWithoutTool(): void
    {
        $singleStep = AgentEvalHarnessToolCall::buildCallsOfStepsBlock(AgentEvalHarnessToolCall::STEP_AFTER, ['tool' => 'rebuildPreview', 'args' => ['full' => true]]);
        $listOfSteps = AgentEvalHarnessToolCall::buildCallsOfStepsBlock(
            AgentEvalHarnessToolCall::STEP_SETUP,
            [['tool' => 'createNote'], 'not a step', ['args' => []], ['tool' => 'pinNote', 'args' => 'not a map']]
        );

        self::assertSame([['tool' => 'rebuildPreview', 'args' => ['full' => true]]], array_map(static fn (AgentEvalHarnessToolCall $call): array => $call->toArray(), $singleStep->getElements()));
        self::assertSame(
            [['tool' => 'createNote', 'args' => []], ['tool' => 'pinNote', 'args' => []]],
            array_map(static fn (AgentEvalHarnessToolCall $call): array => $call->toArray(), $listOfSteps->getElements())
        );
        self::assertTrue(AgentEvalHarnessToolCall::buildCallsOfStepsBlock(AgentEvalHarnessToolCall::STEP_SETUP, null)->isEmpty());
    }

    public function testTheExecutedArgumentsPutTheApplicationsArgumentsFirstAndNeverLetTheCaseOverwriteThem(): void
    {
        $setupCall = AgentEvalHarnessToolCall::forTool(AgentEvalHarnessToolCall::STEP_SETUP, 'createNote', ['spaceRef' => 9, 'content' => 'x']);
        $undoCall = AgentEvalHarnessToolCall::forTool(AgentEvalHarnessToolCall::STEP_UNDO, 'deleteNote', ['noteId' => 5, 'outputFormat' => 'markdown'], true);

        self::assertSame(['spaceRef' => 4, 'content' => 'x'], $setupCall->buildExecutedArguments(['spaceRef' => 4]));
        self::assertSame(['spaceRef' => 9, 'content' => 'x'], $setupCall->buildExecutedArguments(), 'without filled arguments the case arguments only');
        self::assertSame(['spaceRef' => 4, 'outputFormat' => 'structured', 'noteId' => 5], $undoCall->buildExecutedArguments(['spaceRef' => 4]));
    }

    public function testTheReportLineMarksAFailedCallAndCutsItsDetail(): void
    {
        $setupCall = $this->setupCallWithResult(['tool' => 'createNote'], "first\n\n   second", true);
        $writeCall = AgentEvalHarnessToolCall::forTool(AgentEvalHarnessToolCall::STEP_WRITE, 'saveNote', ['content' => 'old']);
        $writeCall->recordResult(str_repeat('a', 500), false);

        self::assertSame('restore setup createNote: [ERROR] first second', $setupCall->buildReportLine());
        self::assertSame('restore write saveNote: ' . str_repeat('a', 400), $writeCall->buildReportLine());
    }

    public function testTheReportLineOfARemovalIsMarkedOnlyWhenTheRecordStayed(): void
    {
        $alreadyGone = $this->removalWithResult('{"content":[{"type":"text","text":"Note 1 not found."}],"isError":true}', true);
        $stayed = $this->removalWithResult('{"structuredContent":{"deleted":false}}', false);
        $cleanup = AgentEvalHarnessToolCall::forTool(AgentEvalHarnessToolCall::STEP_CLEANUP, 'deleteNote', ['noteId' => 1], true);
        $cleanup->recordResult('{"structuredContent":{"deleted":true}}', false);

        self::assertSame('restore undo deleteNote: (nothing left to remove, or refused) Note 1 not found.', $alreadyGone->buildReportLine());
        self::assertSame('restore undo deleteNote: [ERROR] {"structuredContent":{"deleted":false}}', $stayed->buildReportLine());
        self::assertSame('restore cleanup deleteNote: {"structuredContent":{"deleted":true}}', $cleanup->buildReportLine());
    }
}
