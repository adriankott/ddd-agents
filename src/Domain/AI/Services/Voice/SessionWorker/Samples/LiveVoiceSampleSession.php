<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Voice\SessionWorker\Samples;

use DDD\Domain\AI\Services\Voice\Samples\LiveVoiceSampleRecording;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Throwable;

/**
 * One running sample session of {@see LiveVoiceSampleSynthesizer}: its own event loop, its socket, its timers and the
 * recording the socket's events go into. The loop calls its methods as callbacks (`[$session, 'method']`), the way the
 * call's session worker does, so no closure holds the state.
 *
 * Life: {@see onConnected()} sends the start event and starts the input pacer; {@see onInputTick()} appends 0.1 s of
 * silence while the session runs and asks for the close once the speech is over or the time is up;
 * a grace timer drops a socket the provider did not close; every end goes through {@see stop()}, which
 * cancels the timers, closes the socket (the session is billed while it is open) and stops the loop.
 *
 * No service: the synthesizer builds one per sample with `new`.
 */
#[Exclude]
class LiveVoiceSampleSession
{
    /** @var LiveVoiceSampleRecording What the session produced */
    public LiveVoiceSampleRecording $liveVoiceSampleRecording;

    /** @var float When the session was started (microtime) */
    public float $startedAt;

    /** @var Throwable|null Why the socket could not be opened; null when it opened */
    public ?Throwable $connectFailure = null;

    /** @var bool Whether `session.close` was sent */
    public bool $closeRequested = false;

    /** @var WebSocket|null The open socket; null before it opened and after the stop */
    protected ?WebSocket $openWebSocketConnection = null;

    /** @var TimerInterface[] The timers of the session, cancelled by {@see stop()} */
    protected array $timers = [];

    /** @var string One input chunk of silence, base64 */
    protected string $silentInputChunk;

    /**
     * @param LoopInterface $eventLoop The session's own loop
     * @param string $sessionStartEventJson The first event (`session.start`) as JSON
     */
    public function __construct(
        protected LoopInterface $eventLoop,
        protected string $sessionStartEventJson,
    ) {
        $this->liveVoiceSampleRecording = new LiveVoiceSampleRecording();
        $this->startedAt = microtime(true);
        $this->silentInputChunk = base64_encode(str_repeat("\0", (int)(LiveVoiceSampleSynthesizer::SAMPLE_RATE * LiveVoiceSampleSynthesizer::INPUT_CHUNK_SECONDS) * 2));
    }

    /**
     * Arms the watchdog and runs the loop until the session stops.
     *
     * @return void
     */
    public function run(): void
    {
        $this->timers[] = $this->eventLoop->addTimer(
            LiveVoiceSampleSynthesizer::MAXIMUM_SESSION_SECONDS + LiveVoiceSampleSynthesizer::CLOSE_GRACE_SECONDS + 5.0,
            [$this, 'stop']
        );
        $this->eventLoop->run();
    }

    /**
     * The socket is open: listen, send the start event, pace the silent input.
     *
     * @param WebSocket $webSocketConnection
     * @return void
     */
    public function onConnected(WebSocket $webSocketConnection): void
    {
        $this->openWebSocketConnection = $webSocketConnection;
        $webSocketConnection->on('message', [$this, 'onMessage']);
        $webSocketConnection->on('close', [$this, 'stop']);
        $webSocketConnection->send($this->sessionStartEventJson);
        $this->timers[] = $this->eventLoop->addPeriodicTimer(LiveVoiceSampleSynthesizer::INPUT_CHUNK_SECONDS, [$this, 'onInputTick']);
    }

    /**
     * The socket could not be opened.
     *
     * @param Throwable $throwable
     * @return void
     */
    public function onConnectFailed(Throwable $throwable): void
    {
        $this->connectFailure = $throwable;
        $this->stop();
    }

    /**
     * One event of the provider; the session ends when the provider closed it.
     *
     * @param MessageInterface $message
     * @return void
     */
    public function onMessage(MessageInterface $message): void
    {
        $this->liveVoiceSampleRecording->applySessionEvent((string)$message, microtime(true));
        if ($this->liveVoiceSampleRecording->sessionClosed) {
            $this->stop();
        }
    }

    /**
     * Every {@see LiveVoiceSampleSynthesizer::INPUT_CHUNK_SECONDS}: 0.1 s of silence while the session runs (its timeline
     * only moves while input comes in); after the speech, or when the time is up, `session.close` and a grace timer.
     *
     * @return void
     */
    public function onInputTick(): void
    {
        $webSocketConnection = $this->openWebSocketConnection;
        if ($webSocketConnection === null || $this->closeRequested) {
            return;
        }
        $now = microtime(true);
        if ($this->liveVoiceSampleRecording->sessionStarted) {
            $webSocketConnection->send('{"type":"session.input_audio.append","audio":"' . $this->silentInputChunk . '"}');
        }
        $isOverTime = ($now - $this->startedAt) >= LiveVoiceSampleSynthesizer::MAXIMUM_SESSION_SECONDS;
        if ($isOverTime || $this->liveVoiceSampleRecording->hasFinishedSpeaking($now, LiveVoiceSampleSynthesizer::QUIET_SECONDS_AFTER_SPEECH)) {
            $this->closeRequested = true;
            $webSocketConnection->send('{"type":"session.close"}');
            $this->timers[] = $this->eventLoop->addTimer(LiveVoiceSampleSynthesizer::CLOSE_GRACE_SECONDS, [$this, 'stop']);
        }
    }

    /**
     * Ends the session: cancels the timers, closes the socket (billed while open; never left to the provider's
     * timeout) and stops the loop. Safe to call more than once (the socket's close event calls it again).
     *
     * @return void
     */
    public function stop(): void
    {
        foreach ($this->timers as $timer) {
            $this->eventLoop->cancelTimer($timer);
        }
        $this->timers = [];
        $webSocketConnection = $this->openWebSocketConnection;
        $this->openWebSocketConnection = null;
        $webSocketConnection?->close();
        $this->eventLoop->stop();
    }
}
