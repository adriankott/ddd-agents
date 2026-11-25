<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

use LogicException;
use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\Timer;
use React\EventLoop\TimerInterface;

/**
 * An event loop without a clock and without streams, installed as the global loop (`Loop::set()`) for one test.
 * {@see run()} behaves like the real loop where the worker depends on it: it starts running whatever was asked before
 * (a `stop()` before `run()` is lost, as in `StreamSelectLoop`), runs the future ticks, then the one-shot timers in
 * the order they are due (the clock jumps, nothing waits), and returns once `stop()` was called. Periodic timers are
 * recorded and never fired: a test fires their callback itself. A loop left with only periodic timers or signal
 * listeners would keep running for ever; this one throws instead, so a lost stop is a red test, not a hanging run.
 */
class ScriptedEventLoop implements LoopInterface
{
    /** @var array<int, array{timer: TimerInterface, dueAtSeconds: float}> The one-shot timers not fired or cancelled yet, by object id */
    public array $oneShotTimers = [];

    /** @var array<int, TimerInterface> The periodic timers not cancelled, by object id */
    public array $periodicTimers = [];

    /** @var TimerInterface[] Every timer passed to {@see cancelTimer()}, in order */
    public array $cancelledTimers = [];

    /** @var float[] The intervals of the one-shot timers {@see run()} fired, in order */
    public array $firedOneShotTimerIntervals = [];

    /** @var array<int, callable[]> The signal listeners by signal number */
    public array $signalListeners = [];

    /** @var int[] The signal numbers passed to {@see removeSignal()} */
    public array $removedSignals = [];

    /** @var callable[] The future ticks not run yet */
    public array $futureTicks = [];

    /** @var bool When true, {@see run()} first calls every SIGTERM listener (a deploy's stop arriving during the call) */
    public bool $deliversTerminationSignalWhenRunStarts = false;

    public int $runCount = 0;

    public int $stopCount = 0;

    protected bool $running = false;

    protected float $clockSeconds = 0.0;

    public function addReadStream($stream, $listener): void
    {
        throw new LogicException('The scripted event loop has no streams.');
    }

    public function addWriteStream($stream, $listener): void
    {
        throw new LogicException('The scripted event loop has no streams.');
    }

    public function removeReadStream($stream): void
    {
    }

    public function removeWriteStream($stream): void
    {
    }

    public function addTimer($interval, $callback): TimerInterface
    {
        $timer = new Timer($interval, $callback, false);
        $this->oneShotTimers[spl_object_id($timer)] = ['timer' => $timer, 'dueAtSeconds' => $this->clockSeconds + (float)$interval];

        return $timer;
    }

    public function addPeriodicTimer($interval, $callback): TimerInterface
    {
        $timer = new Timer($interval, $callback, true);
        $this->periodicTimers[spl_object_id($timer)] = $timer;

        return $timer;
    }

    public function cancelTimer(TimerInterface $timer): void
    {
        $this->cancelledTimers[] = $timer;
        unset($this->oneShotTimers[spl_object_id($timer)], $this->periodicTimers[spl_object_id($timer)]);
    }

    public function futureTick($listener): void
    {
        $this->futureTicks[] = $listener;
    }

    public function addSignal($signal, $listener): void
    {
        $this->signalListeners[(int)$signal][] = $listener;
    }

    public function removeSignal($signal, $listener): void
    {
        $this->removedSignals[] = (int)$signal;
        $this->signalListeners[(int)$signal] = array_values(array_filter(
            $this->signalListeners[(int)$signal] ?? [],
            static fn (mixed $signalListener): bool => $signalListener !== $listener
        ));
        if ($this->signalListeners[(int)$signal] === []) {
            unset($this->signalListeners[(int)$signal]);
        }
    }

    public function run(): void
    {
        $this->runCount++;
        $this->running = true;
        if ($this->deliversTerminationSignalWhenRunStarts) {
            foreach ($this->signalListeners[SIGTERM] ?? [] as $terminationSignalListener) {
                $terminationSignalListener(SIGTERM);
            }
        }
        while ($this->running) {
            if ($this->futureTicks !== []) {
                $futureTick = array_shift($this->futureTicks);
                $futureTick();
                continue;
            }
            if ($this->oneShotTimers !== []) {
                $this->fireTheNextOneShotTimer();
                continue;
            }
            if ($this->periodicTimers !== [] || $this->signalListeners !== []) {
                throw new LogicException(sprintf(
                    'The loop would keep running: %d periodic timers and %d signal listeners are left, nothing stops it.',
                    count($this->periodicTimers),
                    array_sum(array_map('count', $this->signalListeners))
                ));
            }
            break;
        }
        $this->running = false;
    }

    public function stop(): void
    {
        $this->stopCount++;
        $this->running = false;
    }

    /** @return float[] The intervals of the one-shot timers still waiting */
    public function getWaitingOneShotTimerIntervals(): array
    {
        return array_values(array_map(
            static fn (array $oneShotTimer): float => (float)$oneShotTimer['timer']->getInterval(),
            $this->oneShotTimers
        ));
    }

    /** @return float[] The intervals of the periodic timers not cancelled */
    public function getPeriodicTimerIntervals(): array
    {
        return array_values(array_map(
            static fn (TimerInterface $periodicTimer): float => (float)$periodicTimer->getInterval(),
            $this->periodicTimers
        ));
    }

    protected function fireTheNextOneShotTimer(): void
    {
        $nextTimerId = null;
        foreach ($this->oneShotTimers as $timerId => $oneShotTimer) {
            if ($nextTimerId === null || $oneShotTimer['dueAtSeconds'] < $this->oneShotTimers[$nextTimerId]['dueAtSeconds']) {
                $nextTimerId = $timerId;
            }
        }
        $nextTimer = $this->oneShotTimers[$nextTimerId];
        unset($this->oneShotTimers[$nextTimerId]);
        $this->clockSeconds = $nextTimer['dueAtSeconds'];
        $this->firedOneShotTimerIntervals[] = (float)$nextTimer['timer']->getInterval();
        $timerCallback = $nextTimer['timer']->getCallback();
        $timerCallback($nextTimer['timer']);
    }
}
