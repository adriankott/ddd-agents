<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\CallTasks;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationDriveScope;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\SignalSources\PhoneConversationSignalSource;
use DDD\Domain\AI\Entities\Voice\Calls\AgentCallTaskOutcome;
use DDD\Domain\AI\Entities\Voice\Calls\AgentCallTaskStart;
use DDD\Domain\AI\Services\Voice\AgentCallTasksService;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The bridge between a voice call and the agent: a task is started by a turn-owing signal on the transcript — refused
 * as busy on the FRESH run state of the conversation, never on the one the worker holds — and its index is the newest
 * delegation signal; the outcome is read fresh: working while the run holds a turn, the newest assistant text once it
 * settled, failed for a FAILED run or a conversation that is gone.
 */
class AgentCallTasksServiceTest extends AgentVoiceUnitTestCase
{
    public const int CONVERSATION_ID = 41;

    /** @return array<string, array{0: string, 1: bool}> */
    public static function provideFreshRunStates(): array
    {
        return [
            'running' => [AIConversationRunState::RUNNING, true],
            'awaiting delegates' => [AIConversationRunState::AWAITING_DELEGATES, true],
            'queued' => [AIConversationRunState::QUEUED, true],
            'idle' => [AIConversationRunState::IDLE, false],
            'completed' => [AIConversationRunState::COMPLETED, false],
            'failed' => [AIConversationRunState::FAILED, false],
        ];
    }

    #[DataProvider('provideFreshRunStates')]
    public function testATaskIsRefusedAsBusyOnTheFreshRunState(string $freshRunState, bool $expectedBusy): void
    {
        $agentCallTasksService = new StagedAgentCallTasksService();
        $agentCallTasksService->freshConversation = static::buildConversation($freshRunState);
        $heldConversation = static::buildConversation($expectedBusy ? AIConversationRunState::IDLE : AIConversationRunState::RUNNING);

        $agentTaskStart = $agentCallTasksService->startAgentTaskOnTranscript($heldConversation);

        static::assertSame([static::CONVERSATION_ID], $agentCallTasksService->freshConversationReads);
        if ($expectedBusy) {
            static::assertSame(
                ['started' => false, 'status' => AgentCallTasksService::STATUS_BUSY, 'error' => null, 'taskUserMessageIndex' => null],
                static::describeTaskStart($agentTaskStart)
            );
            static::assertTrue($agentTaskStart->isBusy());
            static::assertSame([], $agentCallTasksService->recordingAgentService->driveRequests, 'nothing is queued');

            return;
        }
        static::assertTrue($agentTaskStart->started);
        static::assertCount(1, $agentCallTasksService->recordingAgentService->driveRequests);
    }

    public function testAConversationThatIsGoneIsNotFound(): void
    {
        $agentCallTasksService = new StagedAgentCallTasksService();

        static::assertSame(
            ['started' => false, 'status' => null, 'error' => 'conversation_not_found', 'taskUserMessageIndex' => null],
            static::describeTaskStart($agentCallTasksService->startAgentTaskOnTranscript(static::buildConversation(AIConversationRunState::IDLE)))
        );
        static::assertSame([], $agentCallTasksService->recordingAgentService->driveRequests);
    }

    public function testTheTaskIsDrivenAsAnAsyncAdminDriveWithTheDelegationSignal(): void
    {
        $agentCallTasksService = new StagedAgentCallTasksService();
        $agentCallTasksService->freshConversation = static::buildConversation(AIConversationRunState::IDLE);

        $agentCallTasksService->startAgentTaskOnTranscript(static::buildConversation(AIConversationRunState::IDLE));

        $driveRequest = $agentCallTasksService->recordingAgentService->driveRequests[0];
        static::assertSame(AIConversationDriveScope::ADMIN, $driveRequest['scope']);
        static::assertSame(static::CONVERSATION_ID, $driveRequest['aiConversationId']);
        static::assertNull($driveRequest['agentSpace']);
        static::assertSame('', $driveRequest['message'], 'no copy of the caller\'s words: the transcript carries them');
        static::assertTrue($driveRequest['async']);
        static::assertSame(PhoneConversationSignalSource::PHONE_DELEGATION->value, $driveRequest['startSignalSource']);
        static::assertStringStartsWith('The caller, on a voice call with you right now, asked you to take care of what they just said', (string)$driveRequest['startSignalText']);
        static::assertStringContainsString('endVoiceCall (skill Agent.Voice)', (string)$driveRequest['startSignalText']);
    }

    /** @return array<string, array{0: array<int, array{0: string, 1: string|null, 2: int}>, 1: int}> */
    public static function provideDrivenMessages(): array
    {
        $delegation = PhoneConversationSignalSource::PHONE_DELEGATION->value;

        return [
            'the newest delegation signal' => [[
                [AIConversationMessage::MESSAGE_SIGNAL, $delegation, 5],
                [AIConversationMessage::MESSAGE_TEXT, null, 6],
                [AIConversationMessage::MESSAGE_SIGNAL, $delegation, 9],
                [AIConversationMessage::MESSAGE_SIGNAL, 'ANOTHER_SOURCE', 12],
            ], 9],
            'no delegation signal: the last index' => [[
                [AIConversationMessage::MESSAGE_TEXT, null, 2],
                [AIConversationMessage::MESSAGE_SIGNAL, 'ANOTHER_SOURCE', 3],
            ], 3],
            'no row at all' => [[], 0],
        ];
    }

    /** @param array<int, array{0: string, 1: string|null, 2: int}> $drivenMessageRows */
    #[DataProvider('provideDrivenMessages')]
    public function testTheTaskIndexIsTheNewestDelegationSignal(array $drivenMessageRows, int $expectedTaskIndex): void
    {
        $agentCallTasksService = new StagedAgentCallTasksService();
        $agentCallTasksService->freshConversation = static::buildConversation(AIConversationRunState::IDLE);
        $drivenConversation = static::buildConversation(AIConversationRunState::QUEUED);
        $drivenConversation->messages = new AIConversationMessages();
        foreach ($drivenMessageRows as [$messageType, $signalSource, $indexInConversation]) {
            $drivenMessage = new AIConversationMessage();
            $drivenMessage->messageType = $messageType;
            $drivenMessage->signalSource = $signalSource;
            $drivenMessage->indexInConversation = $indexInConversation;
            $drivenConversation->messages->add($drivenMessage);
        }
        $agentCallTasksService->recordingAgentService->drivenConversation = $drivenConversation;

        static::assertSame(
            ['started' => true, 'status' => 'working', 'error' => null, 'taskUserMessageIndex' => $expectedTaskIndex],
            static::describeTaskStart($agentCallTasksService->startAgentTaskOnTranscript(static::buildConversation(AIConversationRunState::IDLE)))
        );
    }

    public function testTheOutcomeOfAConversationThatIsGoneIsFailed(): void
    {
        $agentCallTasksService = new StagedAgentCallTasksService();

        static::assertSame(['status' => 'failed', 'answerText' => null], static::describeTaskOutcome($agentCallTasksService->findAgentTaskOutcome(static::buildConversation(AIConversationRunState::IDLE), 4)));
    }

    public function testTheOutcomeIsWorkingWhileTheRunHoldsATurn(): void
    {
        $agentCallTasksService = new StagedAgentCallTasksService();
        $freshConversation = static::buildTailScriptedConversation(AIConversationRunState::RUNNING, ['An answer already written.']);
        $agentCallTasksService->freshConversation = $freshConversation;

        static::assertSame(['status' => 'working', 'answerText' => null], static::describeTaskOutcome($agentCallTasksService->findAgentTaskOutcome(static::buildConversation(AIConversationRunState::IDLE), 4)));
        static::assertSame([], $freshConversation->tailReads, 'the tail is not read while the turn runs');
    }

    public function testTheOutcomeOfASettledRunIsItsNewestAssistantText(): void
    {
        $agentCallTasksService = new StagedAgentCallTasksService();
        $freshConversation = static::buildTailScriptedConversation(AIConversationRunState::IDLE, ['First thoughts.', 'The shop opens at nine.', '   ']);
        $agentCallTasksService->freshConversation = $freshConversation;

        static::assertSame(
            ['status' => 'completed', 'answerText' => 'The shop opens at nine.'],
            static::describeTaskOutcome($agentCallTasksService->findAgentTaskOutcome(static::buildConversation(AIConversationRunState::IDLE), 4))
        );
        static::assertSame([['afterIndex' => 4, 'useEntityRegistrCache' => false]], $freshConversation->tailReads, 'read after the task index, fresh');
    }

    public function testTheOutcomeOfAFailedRunCarriesItsLastText(): void
    {
        $agentCallTasksService = new StagedAgentCallTasksService();
        $agentCallTasksService->freshConversation = static::buildTailScriptedConversation(AIConversationRunState::FAILED, ['I could not reach the review store.']);

        static::assertSame(
            ['status' => 'failed', 'answerText' => 'I could not reach the review store.'],
            static::describeTaskOutcome($agentCallTasksService->findAgentTaskOutcome(static::buildConversation(AIConversationRunState::IDLE), 4))
        );
    }

    public function testASettledRunWithoutAnAnswerIsStillWorking(): void
    {
        $agentCallTasksService = new StagedAgentCallTasksService();
        $agentCallTasksService->freshConversation = static::buildTailScriptedConversation(AIConversationRunState::IDLE, []);

        static::assertSame(['status' => 'working', 'answerText' => null], static::describeTaskOutcome($agentCallTasksService->findAgentTaskOutcome(static::buildConversation(AIConversationRunState::IDLE), 4)));
    }

    protected static function buildConversation(string $runState): AIConversation
    {
        $aiConversation = AIConversation::newInstance();
        $aiConversation->id = static::CONVERSATION_ID;
        $aiConversation->accountId = null;
        $aiConversation->runState = $runState;

        return $aiConversation;
    }

    /** @param string[] $assistantTexts */
    protected static function buildTailScriptedConversation(string $runState, array $assistantTexts): TailScriptedAIConversation
    {
        $freshConversation = new TailScriptedAIConversation();
        $freshConversation->id = static::CONVERSATION_ID;
        $freshConversation->runState = $runState;
        $freshConversation->scriptedTailMessages = new AIConversationMessages();
        $callerRow = new AIConversationMessage();
        $callerRow->role = AIConversationMessage::ROLE_USER;
        $callerRow->messageType = AIConversationMessage::MESSAGE_TEXT;
        $callerRow->message = 'A caller line after the answer.';
        foreach ($assistantTexts as $assistantText) {
            $assistantRow = new AIConversationMessage();
            $assistantRow->role = AIConversationMessage::ROLE_ASSISTANT;
            $assistantRow->messageType = AIConversationMessage::MESSAGE_TEXT;
            $assistantRow->message = $assistantText;
            $freshConversation->scriptedTailMessages->add($assistantRow);
        }
        $freshConversation->scriptedTailMessages->add($callerRow);

        return $freshConversation;
    }

    /** @return array{started: bool, status: string|null, error: string|null, taskUserMessageIndex: int|null} every field of the answer */
    protected static function describeTaskStart(AgentCallTaskStart $agentCallTaskStart): array
    {
        return [
            'started' => $agentCallTaskStart->started,
            'status' => $agentCallTaskStart->status,
            'error' => $agentCallTaskStart->error,
            'taskUserMessageIndex' => $agentCallTaskStart->taskUserMessageIndex,
        ];
    }

    /** @return array{status: string, answerText: string|null} every field of the outcome */
    protected static function describeTaskOutcome(AgentCallTaskOutcome $agentCallTaskOutcome): array
    {
        return ['status' => $agentCallTaskOutcome->status, 'answerText' => $agentCallTaskOutcome->answerText];
    }
}
