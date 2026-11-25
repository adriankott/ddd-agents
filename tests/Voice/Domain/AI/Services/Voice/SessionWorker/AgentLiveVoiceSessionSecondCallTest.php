<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallGoodbyeHangUp;
use DDD\Domain\AI\Services\Voice\SessionWorker\AgentLiveVoiceSessionsService;
use React\EventLoop\Timer\Timer;
use React\EventLoop\TimerInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use RuntimeException;
use Ratchet\Client\WebSocket;

/**
 * The second call of the same worker process (one consumer runs call after call): before a call is attached, every
 * field of the previous call goes back to its state in a fresh worker — checked over ALL fields the worker class
 * declares, so a field added later without a reset fails here. The timers, the socket and the drain listener of the
 * first call leave the loop, so none of them acts on the second; a SIGTERM drained during the first call is passed on.
 */
class AgentLiveVoiceSessionSecondCallTest extends AgentLiveVoiceSessionWorkerTestCase
{
    /** Fields that deliberately do not return to the fresh value: the trace token is minted anew for every call. */
    public const array FIELDS_NOT_RETURNED_TO_THE_FRESH_VALUE = ['traceWorkerToken'];

    public function testEveryDeclaredFieldReturnsToItsFreshStateBeforeTheNextCall(): void
    {
        $freshWorker = $this->buildWorker();
        $worker = $this->buildWorker();
        $dirtyValuesByField = [];
        foreach (static::findDeclaredWorkerFields() as $workerField) {
            $dirtyValuesByField[$workerField->getName()] = $this->buildDirtyValueOf($workerField);
            $workerField->setValue($worker, $dirtyValuesByField[$workerField->getName()]);
        }

        $worker->runWorkerStep('resetStateOfThePreviousCall');

        $fieldsNotReset = [];
        foreach (static::findDeclaredWorkerFields() as $workerField) {
            if ($workerField->getValue($worker) != $workerField->getValue($freshWorker)) {
                $fieldsNotReset[] = $workerField->getName();
            }
        }
        static::assertSame(static::FIELDS_NOT_RETURNED_TO_THE_FRESH_VALUE, $fieldsNotReset);
        static::assertNotSame($dirtyValuesByField['traceWorkerToken'], $worker->getWorkerState('traceWorkerToken'));
        static::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $worker->getWorkerState('traceWorkerToken'));
        static::assertGreaterThan(40, count($dirtyValuesByField), 'the reflection found the worker\'s fields');
        static::assertArrayHasKey('bookedSecondsOwedToTheCall', $dirtyValuesByField);
        static::assertArrayHasKey('eventLoopStopRequested', $dirtyValuesByField);
        static::assertSame(0, $worker->getWorkerState('bookedSecondsOwedToTheCall'), 'seconds owed to the first call are not written onto the second');
        static::assertFalse($worker->getWorkerState('eventLoopStopRequested'), 'a stop of the first call does not skip the loop of the second');
    }

    public function testTheTimersAndTheSocketOfTheFirstCallLeaveTheLoop(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $firstAgentVoiceCall = static::buildRunningCall();
        $firstSidebandWebSocket = new RecordingSidebandWebSocket();
        $worker->runWorkerStep('runAttachedSideband', $firstAgentVoiceCall, $firstSidebandWebSocket);
        $worker->runWorkerStep('armFarewellHangUp', $firstAgentVoiceCall, $firstSidebandWebSocket, 'Goodbye.', AgentVoiceCall::END_REASON_COMPLETED);
        static::assertSame([0.5, 2.0], $this->scriptedEventLoop->getPeriodicTimerIntervals());
        static::assertSame([6.0], $this->scriptedEventLoop->getWaitingOneShotTimerIntervals());

        $worker->runWorkerStep('resetStateOfThePreviousCall');

        static::assertSame([], $this->scriptedEventLoop->getPeriodicTimerIntervals());
        static::assertSame([], $this->scriptedEventLoop->getWaitingOneShotTimerIntervals());
        static::assertCount(3, $this->scriptedEventLoop->cancelledTimers);
        static::assertSame(1, $firstSidebandWebSocket->closeCount);
        static::assertSame(0, $firstSidebandWebSocket->countListeners('message'));
        static::assertSame(0, $firstSidebandWebSocket->countListeners('close'));

        $worker->hangUpAfterFarewellWasSpoken();
        $worker->runWatcherTickOfAttachedCall();
        $worker->runGoodbyeAndTaskOutcomeCheckOfAttachedCall();

        static::assertSame([], $worker->voiceProviderAdapter->hungUpProviderSessionIds, 'a late callback of the first call acts on nothing');
        static::assertSame([], $worker->agentVoiceCallsService->persistedCallEnds);
    }

    public function testASigtermDrainedDuringTheCallIsPassedToTheHandlerOfTheConsumer(): void
    {
        RecordedTerminationSignals::$receivedSignals = [];
        $worker = $this->buildWorker();
        $consumerTerminationHandler = [RecordedTerminationSignals::class, 'recordSignal'];
        $worker->setWorkerState('terminationSignalHandlerBeforeTheCall', $consumerTerminationHandler);
        $this->scriptedEventLoop->addSignal(SIGTERM, [$worker, 'logTerminationSignalDrain']);
        $worker->setWorkerState('attachedAgentVoiceCall', static::buildRunningCall());

        $worker->logTerminationSignalDrain();
        $worker->runWorkerStep('resetStateOfThePreviousCall');
        pcntl_signal_dispatch();

        static::assertSame(
            ['LIVE live_session_seven SIGTERM received — draining: the live call finishes before this worker stops'],
            $worker->voiceLogLines
        );
        static::assertSame([SIGTERM], RecordedTerminationSignals::$receivedSignals, 'the consumer stops after the call it drained for');
        static::assertSame($consumerTerminationHandler, pcntl_signal_get_handler(SIGTERM), 'the consumer\'s handler is back');
        static::assertSame([], $this->scriptedEventLoop->signalListeners, 'the drain listener left the loop');
        static::assertFalse($worker->getWorkerState('terminationSignalDrained'));
        static::assertNull($worker->getWorkerState('terminationSignalHandlerBeforeTheCall'));
    }

    public function testACallWithoutADrainedSignalSendsNone(): void
    {
        RecordedTerminationSignals::$receivedSignals = [];
        $worker = $this->buildWorker();
        $worker->setWorkerState('terminationSignalHandlerBeforeTheCall', [RecordedTerminationSignals::class, 'recordSignal']);

        $worker->runWorkerStep('resetStateOfThePreviousCall');
        pcntl_signal_dispatch();

        static::assertSame([], RecordedTerminationSignals::$receivedSignals);
        static::assertSame([SIGTERM], $this->scriptedEventLoop->removedSignals);
    }

    /** @return ReflectionProperty[] The instance fields the worker class itself declares */
    protected static function findDeclaredWorkerFields(): array
    {
        return array_values(array_filter(
            (new ReflectionClass(AgentLiveVoiceSessionsService::class))->getProperties(),
            static fn (ReflectionProperty $workerField): bool => !$workerField->isStatic()
                && $workerField->getDeclaringClass()->getName() === AgentLiveVoiceSessionsService::class
        ));
    }

    /** A value no fresh worker holds, of the field's type. */
    protected function buildDirtyValueOf(ReflectionProperty $workerField): mixed
    {
        $fieldType = $workerField->getType();
        static::assertInstanceOf(ReflectionNamedType::class, $fieldType, "{$workerField->getName()} has a type this test can dirty");
        $fieldTypeName = $fieldType->getName();

        return match (true) {
            $fieldTypeName === 'bool' => true,
            $fieldTypeName === 'int' => 7,
            $fieldTypeName === 'string' => 'value-of-the-first-call',
            $fieldTypeName === 'array' => [['ofTheFirstCall' => true]],
            // the handler a consumer had before the first call: the default handling, so ending the drain sends nothing
            $fieldTypeName === 'mixed' => SIG_DFL,
            $fieldTypeName === AgentVoiceCall::class => static::buildRunningCall(),
            $fieldTypeName === WebSocket::class => new RecordingSidebandWebSocket(),
            $fieldTypeName === TimerInterface::class => new Timer(1.0, [$this, 'failWhenATimerOfTheFirstCallFires'], false),
            $fieldTypeName === AgentVoiceCallGoodbyeHangUp::class => AgentVoiceCallGoodbyeHangUp::forLiveSession(1000, AgentVoiceCall::END_REASON_COMPLETED),
            $fieldTypeName === 'Throwable' => new RuntimeException('a connect failure of the first call'),
            default => (new ReflectionClass($fieldTypeName))->newInstanceWithoutConstructor(),
        };
    }

    public function failWhenATimerOfTheFirstCallFires(): void
    {
        static::fail('A timer of the first call fired.');
    }
}
