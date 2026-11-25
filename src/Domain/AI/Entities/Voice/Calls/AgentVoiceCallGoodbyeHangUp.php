<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\Calls;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A hang-up the session worker of a running call holds back until the agent's goodbye has been spoken: `endVoiceCall`
 * on the call the agent is on
 * ({@see \DDD\Domain\AI\Services\Voice\AgentVoiceCallsService::requestCallEndAfterGoodbye()}). The tool runs inside the
 * call's own agent turn, and that turn's answer (the goodbye) reaches the voice model only after the turn ended;
 * hanging up at once would cut it off. The worker arms this when it reads the request and asks {@see isDueAt()} on
 * every later check. All clocks are Unix MILLISECONDS.
 *
 * "The goodbye's start" is the later of the arming and the moment the turn's answer was handed to the voice model (the
 * worker usually reads the request while the turn still runs, so the answer is handed over after it). The rule:
 * - not while the answer is still on its way: the agent turn has not settled, or the voice model is still producing
 *   its response;
 * - at {@see MAXIMUM_WAIT_MILLISECONDS} after the goodbye's start in any case (a voice that keeps talking), and
 *   {@see MAXIMUM_WAIT_MILLISECONDS_FOR_THE_ANSWER} after the arming even while the answer is still on its way (a turn
 *   that never settles): a call never stays up because the goodbye never came;
 * - otherwise once the goodbye was spoken ({@see isGoodbyeSpokenAt()}): the agent's voice produced output AFTER the
 *   goodbye's start and has been quiet for {@see $quietMilliseconds} since. A goodbye that starts late is not cut, and
 *   a pause between two goodbye sentences shorter than the quiet window is not taken for the end; when no output comes
 *   after the goodbye's start at all, due {@see NO_SPEECH_AFTER_THE_HAND_OVER_MILLISECONDS} after it. The clock of the
 *   agent's speech is the arrival of its output transcript fragments; captions trail the audio by a few hundred
 *   milliseconds, which the quiet window absorbs.
 *
 * Pure: the worker passes the clock and what it observed.
 *
 * The override point of an application with a provider whose speech has another clock (one that reports the end of
 * generating a response, not the words as they are spoken) is {@see isGoodbyeSpokenAt()}, with its own factory beside
 * {@see forLiveSession()}.
 */
class AgentVoiceCallGoodbyeHangUp extends ValueObject
{
    /** @var int Milliseconds the agent's voice must have been quiet after its goodbye before the line is dropped. */
    public const int LIVE_QUIET_MILLISECONDS = 1500;

    /**
     * @var int Milliseconds after the goodbye's start at which the call ends when the agent's voice produced no output
     * since: the answer was handed over, but nothing was spoken (an empty answer, a voice that stays silent).
     */
    public const int NO_SPEECH_AFTER_THE_HAND_OVER_MILLISECONDS = 6000;

    /** @var int Milliseconds after the goodbye's start at which the call ends whatever is still being spoken. */
    public const int MAXIMUM_WAIT_MILLISECONDS = 30000;

    /**
     * @var int Milliseconds after the arming at which the call ends even while the answer is still on its way. An agent
     * turn on a call takes 30 to 55 seconds at worst; the session worker's guard of a lost task reports a turn that
     * never comes back after 75 seconds.
     */
    public const int MAXIMUM_WAIT_MILLISECONDS_FOR_THE_ANSWER = 120000;

    /** @var int Unix milliseconds the worker read the request and armed the hang-up. */
    public int $armedAtUnixMs;

    /** @var string The end reason the request names: written on the call when the worker hangs up. */
    public string $endReason;

    /** @var int How long the agent's voice must have been quiet before the line is dropped, in milliseconds. */
    public int $quietMilliseconds;

    public function __construct(int $armedAtUnixMs, string $endReason, int $quietMilliseconds = self::LIVE_QUIET_MILLISECONDS)
    {
        parent::__construct();
        $this->armedAtUnixMs = $armedAtUnixMs;
        $this->endReason = $endReason;
        $this->quietMilliseconds = $quietMilliseconds;
    }

    /** The hang-up of a call of the live voice session: speech after the hand-over, then {@see LIVE_QUIET_MILLISECONDS} of quiet. */
    public static function forLiveSession(int $armedAtUnixMs, string $endReason): static
    {
        return new static($armedAtUnixMs, $endReason, static::LIVE_QUIET_MILLISECONDS);
    }

    /**
     * Whether the worker hangs up at `$nowUnixMs`.
     *
     * @param int|null $lastAgentSpeechAtUnixMs the last moment the agent's voice produced output on this call; null = never
     * @param int|null $answerHandedOverAtUnixMs the last moment a turn's answer was handed to the voice model; null = never
     * @param bool $answerStillOnItsWay the agent turn has not settled, or the voice model is still producing its response
     */
    public function isDueAt(
        int $nowUnixMs,
        ?int $lastAgentSpeechAtUnixMs,
        ?int $answerHandedOverAtUnixMs,
        bool $answerStillOnItsWay
    ): bool {
        if ($nowUnixMs - $this->armedAtUnixMs >= static::MAXIMUM_WAIT_MILLISECONDS_FOR_THE_ANSWER) {
            return true;
        }
        if ($answerStillOnItsWay) {
            return false;
        }
        $goodbyeStartUnixMs = max($this->armedAtUnixMs, $answerHandedOverAtUnixMs ?? $this->armedAtUnixMs);
        if ($nowUnixMs - $goodbyeStartUnixMs >= static::MAXIMUM_WAIT_MILLISECONDS) {
            return true;
        }

        return $this->isGoodbyeSpokenAt($nowUnixMs, $goodbyeStartUnixMs, $lastAgentSpeechAtUnixMs);
    }

    /**
     * Whether the goodbye that started at `$goodbyeStartUnixMs` was spoken by `$nowUnixMs`: the agent's voice produced
     * output after the goodbye's start and has been quiet for {@see $quietMilliseconds} since, or it produced none and
     * {@see NO_SPEECH_AFTER_THE_HAND_OVER_MILLISECONDS} passed.
     */
    protected function isGoodbyeSpokenAt(int $nowUnixMs, int $goodbyeStartUnixMs, ?int $lastAgentSpeechAtUnixMs): bool
    {
        if ($lastAgentSpeechAtUnixMs === null || $lastAgentSpeechAtUnixMs <= $goodbyeStartUnixMs) {
            return $nowUnixMs - $goodbyeStartUnixMs >= static::NO_SPEECH_AFTER_THE_HAND_OVER_MILLISECONDS;
        }

        return $nowUnixMs - $lastAgentSpeechAtUnixMs >= $this->quietMilliseconds;
    }
}
