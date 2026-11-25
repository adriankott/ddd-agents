<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Support\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The named sets of {@see AIConversationRunState} and their questions over all seven states (and over no state and an
 * unknown value, which belong to no set), and the conversation's own questions on its `runState` column: closed, and
 * a run in progress (a non-terminal state, or a turn owed).
 */
class AIConversationRunStateSetsTest extends UnitTestCase
{
    protected const array ALL_RUN_STATES = [
        AIConversationRunState::IDLE,
        AIConversationRunState::RUNNING,
        AIConversationRunState::AWAITING_DELEGATES,
        AIConversationRunState::FAILED,
        AIConversationRunState::COMPLETED,
        AIConversationRunState::CANCELLED,
        AIConversationRunState::QUEUED,
    ];

    public function testTheSevenStatesKeepTheirStoredValues(): void
    {
        self::assertSame(['IDLE', 'RUNNING', 'AWAITING_DELEGATES', 'FAILED', 'COMPLETED', 'CANCELLED', 'QUEUED'], self::ALL_RUN_STATES);
    }

    /** @return array<string, array{0: string[], 1: string|null, 2: string[]}> the set, its question (none: a SQL bind only), its members */
    public static function provideNamedSetRows(): array
    {
        return [
            'NON_TERMINAL' => [AIConversationRunState::NON_TERMINAL, 'isNonTerminal', ['RUNNING', 'AWAITING_DELEGATES', 'QUEUED']],
            'TERMINAL' => [AIConversationRunState::TERMINAL, 'isTerminal', ['COMPLETED', 'FAILED', 'CANCELLED']],
            'ACTIVE_DELEGATION_WORKER' => [AIConversationRunState::ACTIVE_DELEGATION_WORKER, null, ['RUNNING']],
            'CLOSED' => [AIConversationRunState::CLOSED, 'isClosed', ['COMPLETED', 'CANCELLED']],
            'LIVE_TURN' => [AIConversationRunState::LIVE_TURN, 'holdsALiveTurn', ['RUNNING', 'AWAITING_DELEGATES']],
            'AT_REST_OR_FAILED' => [AIConversationRunState::AT_REST_OR_FAILED, 'isAtRestOrFailed', ['IDLE', 'FAILED']],
            'AT_REST_OR_ENDED' => [AIConversationRunState::AT_REST_OR_ENDED, 'isAtRestOrEnded', ['IDLE', 'FAILED', 'COMPLETED', 'CANCELLED']],
            'AT_REST_OR_IN_FLIGHT' => [AIConversationRunState::AT_REST_OR_IN_FLIGHT, 'isAtRestOrInFlight', ['IDLE', 'AWAITING_DELEGATES', 'RUNNING', 'QUEUED']],
            'AT_REST_OR_AWAITING_DELEGATES' => [AIConversationRunState::AT_REST_OR_AWAITING_DELEGATES, 'isAtRestOrAwaitingDelegates', ['IDLE', 'AWAITING_DELEGATES']],
            'RUNNING_OR_QUEUED' => [AIConversationRunState::RUNNING_OR_QUEUED, 'isRunningOrQueued', ['RUNNING', 'QUEUED']],
            'DELEGATION_CHILD_NOT_STARTED' => [AIConversationRunState::DELEGATION_CHILD_NOT_STARTED, null, ['QUEUED', 'IDLE']],
        ];
    }

    /**
     * The set holds exactly its members, in this order, and its question answers true for exactly those of the seven
     * states — false for no state and for an unknown value.
     *
     * @param string[] $setMembers
     * @param string[] $expectedMembers
     */
    #[DataProvider('provideNamedSetRows')]
    public function testEveryNamedSetAndItsQuestion(array $setMembers, ?string $questionName, array $expectedMembers): void
    {
        self::assertSame($expectedMembers, $setMembers);
        if ($questionName === null) {
            return;
        }
        foreach ([...self::ALL_RUN_STATES, null, 'UNKNOWN'] as $stateName) {
            self::assertSame(
                in_array($stateName, $expectedMembers, true),
                AIConversationRunState::of($stateName)->{$questionName}(),
                $questionName . ' for ' . var_export($stateName, true)
            );
        }
    }

    public function testTheConversationAsksItsOwnColumn(): void
    {
        $conversation = new AIConversation();
        foreach (self::ALL_RUN_STATES as $runState) {
            $conversation->runState = $runState;
            self::assertSame($runState, $conversation->getRunState()->stateName);
        }
    }

    /** @return array<string, array{0: string, 1: bool, 2: bool}> state, isClosed, isRunInProgress without an owed turn */
    public static function provideConversationQuestionRows(): array
    {
        return [
            'IDLE' => [AIConversationRunState::IDLE, false, false],
            'RUNNING' => [AIConversationRunState::RUNNING, false, true],
            'AWAITING_DELEGATES' => [AIConversationRunState::AWAITING_DELEGATES, false, true],
            'FAILED' => [AIConversationRunState::FAILED, false, false],
            'COMPLETED' => [AIConversationRunState::COMPLETED, true, false],
            'CANCELLED' => [AIConversationRunState::CANCELLED, true, false],
            'QUEUED' => [AIConversationRunState::QUEUED, false, true],
        ];
    }

    /** A turn owed (`agentTurnOwedAt`) makes every state a run in progress; closed reads the state alone. */
    #[DataProvider('provideConversationQuestionRows')]
    public function testTheConversationQuestionsForEveryState(string $runState, bool $expectedClosed, bool $expectedRunInProgress): void
    {
        $conversation = new AIConversation();
        $conversation->runState = $runState;
        $conversation->agentTurnOwedAt = null;

        self::assertSame($expectedClosed, $conversation->isClosed(), 'isClosed');
        self::assertSame($expectedRunInProgress, $conversation->isRunInProgress(), 'isRunInProgress');
        $conversation->agentTurnOwedAt = new DateTime();
        self::assertSame($expectedClosed, $conversation->isClosed(), 'isClosed with an owed turn');
        self::assertTrue($conversation->isRunInProgress(), 'isRunInProgress with an owed turn');
    }
}
