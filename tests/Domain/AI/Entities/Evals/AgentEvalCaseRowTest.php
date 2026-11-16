<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRow;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRows;
use DDD\Domain\AI\Entities\Evals\AgentEvalNotApplicableReason;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTask;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The result row of a case keeps every key in its order; a suite stamps the task's facts onto it, keeps what the
 * harness reported, recognises an infrastructure error and records the second attempt; a not-applicable row is
 * neither a pass nor a fail.
 */
class AgentEvalCaseRowTest extends UnitTestCase
{
    public function testTheTaskIsStampedOntoTheRowAndExistingKeysKeepTheirPlace(): void
    {
        $agentEvalCaseRow = AgentEvalCaseRow::fromArray(['skill' => 'Notes.Profile', 'case' => 'from-the-child', 'passed' => true, 'error' => null, 'messages' => ['trace']]);
        $agentEvalCaseRow->removeEmptyError();
        $agentEvalCaseRow->stampTask($this->task(), 12.345);

        self::assertSame(
            ['skill' => 'Notes.Profile', 'case' => 'profile.write', 'passed' => true, 'messages' => ['trace'], 'suite' => 'tools', 'fixture' => 'Notes.Profile', 'model' => 'VENDOR.MODEL', 'flavor' => 'judge', 'access' => 'write', 'lane' => '7:profile', 'processSeconds' => 12.3],
            $agentEvalCaseRow->toArray()
        );
        self::assertArrayNotHasKey('messages', $agentEvalCaseRow->toArrayWithoutMessages());
        self::assertSame('tools:profile.write', $agentEvalCaseRow->describeSuiteAndCase());
        self::assertFalse(AgentEvalCaseRow::fromArray(['error' => 'kept'])->hasKey('removed'));
    }

    public function testTheHarnessLogIsKeptAndARefusedRestoreStepMarksTheRow(): void
    {
        $refused = AgentEvalCaseRow::fromArray(['passed' => true]);
        $refused->recordHarnessLogOfErrorOutput("noise\n  restore capture getNotes: [ERROR] unreachable\n  restore pinNote: {\"isError\":true}\nmore noise");
        self::assertSame(['restore capture getNotes: [ERROR] unreachable', 'restore pinNote: {"isError":true}'], $refused->getValue('harnessLog'));
        self::assertTrue($refused->isRestoreFailed());

        $failedCaptureOnly = AgentEvalCaseRow::fromArray(['passed' => true]);
        $failedCaptureOnly->recordHarnessLogOfErrorOutput('restore capture getNotes: was NOT executed');
        self::assertFalse($failedCaptureOnly->isRestoreFailed(), 'a failed capture changes nothing');
        self::assertFalse($failedCaptureOnly->hasKey('restoreFailed'));

        $silent = AgentEvalCaseRow::fromArray(['passed' => true]);
        $silent->recordHarnessLogOfErrorOutput("no harness line\n");
        self::assertSame(['passed' => true], $silent->toArray());
    }

    public function testAnInfrastructureErrorIsRecognisedAndTheSecondAttemptCarriesTheFirst(): void
    {
        $retriedErrors = ['Deadlock found when trying to get lock', 'cURL error 28'];
        $firstAttempt = AgentEvalCaseRow::fromArray(['passed' => false, 'error' => 'SQLSTATE[40001]: Deadlock found when trying to get lock; try restarting', 'processSeconds' => 40.2, 'harnessLog' => ['restore x']]);

        self::assertSame('Deadlock found when trying to get lock', $firstAttempt->findInfrastructureError($retriedErrors));
        self::assertNull(AgentEvalCaseRow::fromArray(['passed' => false, 'error' => 'the agent called the wrong tool'])->findInfrastructureError($retriedErrors));
        self::assertNull(AgentEvalCaseRow::fromArray(['passed' => true, 'error' => 'cURL error 28'])->findInfrastructureError($retriedErrors), 'a passed row is never retried');
        self::assertNull(AgentEvalCaseRow::fromArray(['passed' => false])->findInfrastructureError($retriedErrors));

        $secondAttempt = AgentEvalCaseRow::fromArray(['passed' => true, 'processSeconds' => 20.1]);
        $secondAttempt->markAsRetriedAfter('Deadlock found when trying to get lock', $firstAttempt);
        self::assertSame([
            'passed' => true,
            'processSeconds' => 60.3,
            'retriedAfter' => 'Deadlock found when trying to get lock',
            'firstAttempt' => ['error' => 'SQLSTATE[40001]: Deadlock found when trying to get lock; try restarting', 'processSeconds' => 40.2, 'harnessLog' => ['restore x']],
        ], $secondAttempt->toArray());
        self::assertTrue($secondAttempt->wasRetried());
    }

    public function testANotApplicableRowIsOutsideThePassRate(): void
    {
        $agentEvalCaseRow = AgentEvalCaseRow::buildNotApplicable(
            ['skill' => 'Notes.Replies', 'case' => 'reply.delete', 'flavor' => 'judge'],
            AgentEvalNotApplicableReason::buildPreconditionNotEstablishedReason("the preparation step 'replyToNote' of the case failed, the agent was not run: refused")
        );

        self::assertSame(
            ['skill' => 'Notes.Replies', 'case' => 'reply.delete', 'flavor' => 'judge', 'passed' => null, 'outcome' => 'notApplicable', 'notApplicableReason' => AgentEvalNotApplicableReason::REASON_PRECONDITION_NOT_ESTABLISHED, 'notApplicableDetail' => "the preparation step 'replyToNote' of the case failed, the agent was not run: refused"],
            $agentEvalCaseRow->toArray()
        );
        self::assertTrue($agentEvalCaseRow->isNotApplicable());
        self::assertFalse($agentEvalCaseRow->isFailed());
        self::assertSame(['passed' => false, 'error' => 'boom'], array_intersect_key(AgentEvalCaseRow::buildFailedWithError(['case' => 'x'], 'boom')->toArray(), ['passed' => 1, 'error' => 1]));
    }

    public function testTheSetListsTheCasesAReaderLooksAtFirst(): void
    {
        $agentEvalCaseRows = AgentEvalCaseRows::fromRowArrays([
            ['suite' => 'tools', 'case' => 'passed', 'passed' => true, 'retriedAfter' => 'cURL error 28', 'processSeconds' => 3.5],
            ['suite' => 'tools', 'case' => 'failed', 'passed' => false, 'restoreFailed' => true, 'processSeconds' => 1.5],
            ['suite' => 'selection', 'case' => 'na', 'passed' => null, 'outcome' => 'notApplicable', 'notApplicableDetail' => 'withheld'],
            ['suite' => 'selection', 'case' => 'errored', 'passed' => false, 'error' => 'timed out'],
        ]);

        self::assertSame(['selection:na — withheld'], $agentEvalCaseRows->describeNotApplicableCases());
        self::assertSame(['tools:failed'], $agentEvalCaseRows->describeRestoreFailedCases());
        self::assertSame(['tools:passed'], $agentEvalCaseRows->describeRetriedCases());
        self::assertSame(['tools:failed', 'selection:errored'], $agentEvalCaseRows->describeFailedCases());
        self::assertSame(['tools', 'selection'], $agentEvalCaseRows->getSuitesInOrder());
        self::assertSame(5.0, $agentEvalCaseRows->sumProcessSeconds());
        self::assertSame(3, $agentEvalCaseRows->countApplicable());
        self::assertSame(2, $agentEvalCaseRows->buildRowsOfSuite('selection')->count());
    }

    protected function task(): AgentEvalSuiteTask
    {
        $task = AgentEvalSuiteTask::newInstance();
        $task->caseId = 'profile.write';
        $task->suite = 'tools';
        $task->fixturePath = '/fixtures/Notes.Profile.json';
        $task->modelName = 'VENDOR.MODEL';
        $task->flavor = AgentEvalSuiteTask::FLAVOR_JUDGE;
        $task->access = AgentEvalSuiteTask::ACCESS_WRITE;
        $task->agentSpaceId = 7;
        $task->resourceKey = 'profile';
        return $task;
    }
}
