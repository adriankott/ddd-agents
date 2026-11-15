<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Evals\AgentEvalHarnessToolCall;
use DDD\Domain\AI\Entities\Evals\AgentEvalRestorePlan;
use DDD\Domain\AI\Entities\Evals\AgentEvalToolResultExtraction;
use DDD\Tests\Agents\Support\UnitTestCase;
use RuntimeException;

/**
 * The `restore` block of a case: its preparation steps, the capture read and what it captures, the capture that
 * cannot be restored, the revert write with its fixed payload, and the calls after it.
 */
class AgentEvalRestorePlanTest extends UnitTestCase
{
    protected const string READ_RESULT = '{"content":[{"type":"text","text":"Note 12"}],"structuredContent":{"note":{"id":12,"content":"old text","tags":{"a":1}}},"isError":false}';

    /** @param array<string, mixed> $restoreBlock */
    protected function captureReadWithResult(array $restoreBlock, string $resultContent, bool $resultIsError = false): AgentEvalHarnessToolCall
    {
        $captureReadCall = AgentEvalRestorePlan::fromRestoreBlock($restoreBlock)->buildCaptureReadCall();
        self::assertNotNull($captureReadCall);
        $captureReadCall->recordResult($resultContent, $resultIsError);
        return $captureReadCall;
    }

    public function testACapturedIdWithoutValueIsNamed(): void
    {
        $restorePlan = AgentEvalRestorePlan::fromRestoreBlock([]);

        self::assertSame(['noteId'], $restorePlan->findCapturedIdKeysWithoutValue(['noteId' => null, 'content' => null]));
        self::assertSame([], $restorePlan->findCapturedIdKeysWithoutValue(['noteId' => 12, 'content' => null, 'description' => '']));
        self::assertSame(['id'], $restorePlan->findCapturedIdKeysWithoutValue(['id' => null]));
    }

    public function testTheBlockGivesItsStepsInOrder(): void
    {
        $restorePlan = AgentEvalRestorePlan::fromRestoreBlock([
            'setup' => [['tool' => 'createNote', 'args' => ['content' => 'a']], ['tool' => 'pinNote']],
            'after' => ['tool' => 'rebuildPreview'],
        ]);

        self::assertSame(['createNote', 'pinNote'], array_map(static fn (AgentEvalHarnessToolCall $call): string => $call->toolName, $restorePlan->getSetupCalls()->getElements()));
        self::assertSame([AgentEvalHarnessToolCall::STEP_SETUP, AgentEvalHarnessToolCall::STEP_SETUP], array_map(static fn (AgentEvalHarnessToolCall $call): string => $call->step, $restorePlan->getSetupCalls()->getElements()));
        self::assertSame([['tool' => 'rebuildPreview', 'args' => []]], array_map(static fn (AgentEvalHarnessToolCall $call): array => $call->toArray(), $restorePlan->getAfterCalls()->getElements()));
        self::assertSame(AgentEvalHarnessToolCall::STEP_AFTER, $restorePlan->getAfterCalls()->first()?->step);
    }

    public function testTwoEqualSetupStepsAreTwoStepsInTheirOrder(): void
    {
        $restorePlan = AgentEvalRestorePlan::fromRestoreBlock([
            'setup' => [['tool' => 'createNote', 'args' => ['content' => 'a']], ['tool' => 'pinNote'], ['tool' => 'createNote', 'args' => ['content' => 'a']]],
        ]);

        self::assertSame(
            [['tool' => 'createNote', 'args' => ['content' => 'a']], ['tool' => 'pinNote', 'args' => []], ['tool' => 'createNote', 'args' => ['content' => 'a']]],
            array_map(static fn (AgentEvalHarnessToolCall $call): array => $call->toArray(), $restorePlan->getSetupCalls()->getElements()),
            'a repeated step stays a step of its own, at its place'
        );
    }

    public function testEveryCapturedValueNamesTheArgumentOfTheRevertWrite(): void
    {
        $restorePlan = AgentEvalRestorePlan::fromRestoreBlock(['readTool' => 'getNote', 'capture' => ['noteId' => 'note.id', 'content' => 'note.content']]);

        self::assertSame(['noteId', 'content'], array_map(static fn (AgentEvalToolResultExtraction $captureExtraction): string => $captureExtraction->argumentKey, $restorePlan->captureExtractions->getElements()));
        self::assertSame('note.content', $restorePlan->captureExtractions->getByArgumentKey('content')?->structuredContentPath);
    }

    public function testThereIsNoCaptureReadWithoutReadToolOrCapture(): void
    {
        self::assertNull(AgentEvalRestorePlan::fromRestoreBlock(['readTool' => 'getNote'])->buildCaptureReadCall());
        self::assertNull(AgentEvalRestorePlan::fromRestoreBlock(['capture' => ['content' => 'note.content']])->buildCaptureReadCall());
        self::assertSame(
            ['tool' => 'getNote', 'args' => ['outputFormat' => 'structured']],
            AgentEvalRestorePlan::fromRestoreBlock(['readTool' => 'getNote', 'readArgs' => ['outputFormat' => 'structured'], 'capture' => ['content' => 'note.content']])
                ->buildCaptureReadCall()?->toArray()
        );
    }

    public function testTheCaptureReadsEachPathOfTheStructuredContent(): void
    {
        $restoreBlock = ['readTool' => 'getNote', 'capture' => ['noteId' => 'note.id', 'content' => 'note.content', 'tags' => 'note.tags', 'missing' => 'note.nothing']];
        $captureReadCall = $this->captureReadWithResult($restoreBlock, self::READ_RESULT);
        $restorePlan = AgentEvalRestorePlan::fromRestoreBlock($restoreBlock);

        self::assertSame(['noteId' => 12, 'content' => 'old text', 'tags' => ['a' => 1], 'missing' => null], $restorePlan->captureOriginalValues($captureReadCall));
        self::assertSame(
            'restore capture getNote: {"noteId":12,"content":"old text","tags":{"a":1},"missing":null}',
            $restorePlan->buildCaptureReportLine($captureReadCall)
        );
        $restorePlan->refuseCaseWhoseCaptureFailed($captureReadCall);
    }

    public function testAFailedCaptureReadEndsTheCaseWithTheReadsAnswer(): void
    {
        $restoreBlock = ['readTool' => 'getNote', 'capture' => ['content' => 'note.content']];
        $failedAnswer = '{"content":[{"type":"text","text":"Note not found."}],"isError":true}';
        $captureReadCall = $this->captureReadWithResult($restoreBlock, $failedAnswer, true);
        $restorePlan = AgentEvalRestorePlan::fromRestoreBlock($restoreBlock);

        self::assertSame('restore capture getNote: [ERROR] ' . $failedAnswer, $restorePlan->buildCaptureReportLine($captureReadCall));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("The capture read 'getNote' failed, the case was not run: " . $failedAnswer);
        $restorePlan->refuseCaseWhoseCaptureFailed($captureReadCall);
    }

    public function testACaptureThatLeftAnIdUnreadEndsTheCase(): void
    {
        $restoreBlock = ['readTool' => 'getNote', 'capture' => ['noteId' => 'note.missingId', 'content' => 'note.content']];
        $captureReadCall = $this->captureReadWithResult($restoreBlock, self::READ_RESULT);
        $restorePlan = AgentEvalRestorePlan::fromRestoreBlock($restoreBlock);

        self::assertSame('restore capture getNote: [ERROR] no value for noteId — ' . self::READ_RESULT, $restorePlan->buildCaptureReportLine($captureReadCall));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("The capture read 'getNote' found no noteId, the case was not run.");
        $restorePlan->refuseCaseWhoseCaptureFailed($captureReadCall);
    }

    public function testTheRevertWriteTakesTheFixedPayloadOverACapturedValue(): void
    {
        $restorePlan = AgentEvalRestorePlan::fromRestoreBlock(['writeTool' => 'saveNote', 'writeArgs' => ['replaceExisting' => true, 'content' => 'baseline']]);

        self::assertSame(
            ['tool' => 'saveNote', 'args' => ['replaceExisting' => true, 'content' => 'baseline', 'noteId' => 12]],
            $restorePlan->buildRevertWriteCall(['content' => 'captured', 'noteId' => 12])?->toArray()
        );
        self::assertSame(AgentEvalHarnessToolCall::STEP_WRITE, $restorePlan->buildRevertWriteCall([])?->step);
        self::assertFalse($restorePlan->buildRevertWriteCall([])->asksForStructuredOutput);
    }

    public function testThereIsNoRevertWriteWithoutToolOrWithoutAnythingToWrite(): void
    {
        self::assertNull(AgentEvalRestorePlan::fromRestoreBlock(['writeTool' => 'saveNote'])->buildRevertWriteCall([]));
        self::assertNull(AgentEvalRestorePlan::fromRestoreBlock(['writeArgs' => ['content' => 'x']])->buildRevertWriteCall(['noteId' => 1]));
    }

    public function testTheBlockIsKeptAsStated(): void
    {
        $restoreBlock = ['readTool' => 'getNote', 'unknownKey' => ['kept' => true]];

        self::assertSame($restoreBlock, AgentEvalRestorePlan::fromRestoreBlock($restoreBlock)->toArray());
    }
}
