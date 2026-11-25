<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\Calls;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * Turns the transcript DELTAS of a live voice session into spoken sentences. GPT-Live delivers
 * `session.input_transcript.delta` and `session.output_transcript.delta` as caption fragments with `start_ms` /
 * `end_ms` and NO event that marks the end of a turn; the two speakers may even overlap. So the sentence boundary is
 * the session worker's to decide, and this object decides it with two rules only:
 *
 * 1. a fragment from the OTHER speaker that STARTS after the current speaker's open sentence ended closes that
 *    sentence (a real turn change); a fragment that starts while the other is still speaking is an overlap
 *    (back-channels, interruptions) and leaves both sentences open;
 * 2. a speaker who has been quiet for {@see IDLE_FLUSH_AFTER_MS} closes their own open sentence.
 *
 * Pure: no clock of its own (the caller passes the wall-clock milliseconds in), no repository, no socket. That makes
 * it testable alone and keeps the worker's event handler free of buffer bookkeeping.
 */
class LiveTranscriptAssembler extends ValueObject
{
    /** @var int A speaker's open sentence is closed once no new fragment arrived for this long. */
    public const int IDLE_FLUSH_AFTER_MS = 1500;

    /**
     * @var int A fragment of the OTHER speaker closes an open sentence only when it starts this long after that
     * sentence's last word: a real turn change. Anything closer is an interjection ("mm-hm", "yes?") or the two
     * talking at once, and both sentences stay open: the provider delivers captions word by word, and closing on
     * every back-channel chops the agent's sentences into single words.
     */
    public const int TURN_CHANGE_GAP_MS = 800;

    /** @var array<string, string> The open sentence text per role (USER = the caller, ASSISTANT = the agent). */
    protected array $openSentenceTextByRole = [];

    /** @var array<string, int> Session-timeline start (ms) of the open sentence per role. */
    protected array $openSentenceStartMsByRole = [];

    /** @var array<string, int> Session-timeline end (ms) of the open sentence per role. */
    protected array $openSentenceEndMsByRole = [];

    /** @var array<string, int> Wall-clock ms of the last fragment per role — the idle rule reads this. */
    protected array $lastFragmentReceivedAtUnixMsByRole = [];

    /** @var int The furthest session-timeline point (ms) any fragment reached. */
    protected int $lastFragmentEndMs = 0;

    /**
     * The session-timeline point (ms) the transcript has reached so far. A caller that must keep an earlier phase of
     * a call out of the stored transcript records the end of that phase as this value: a sentence that STARTED before
     * it belongs to that phase, however late its captions arrive.
     */
    public function getLastFragmentEndMs(): int
    {
        return $this->lastFragmentEndMs;
    }

    /**
     * Append one fragment. Returns the sentences this fragment COMPLETED (zero or one — the other speaker's
     * open sentence when the speaker changed).
     *
     * @return LiveTranscriptSentence[]
     */
    public function appendFragment(
        string $role,
        string $fragmentText,
        int $startMs,
        int $endMs,
        int $nowUnixMs
    ): array {
        $completedSentences = [];
        $otherRole = $role === AIConversationMessage::ROLE_USER
            ? AIConversationMessage::ROLE_ASSISTANT
            : AIConversationMessage::ROLE_USER;
        if (isset($this->openSentenceEndMsByRole[$otherRole])
            && $startMs >= $this->openSentenceEndMsByRole[$otherRole] + static::TURN_CHANGE_GAP_MS
        ) {
            $completedSentences = $this->closeOpenSentenceForRole($otherRole);
        }
        $this->lastFragmentEndMs = max($this->lastFragmentEndMs, $endMs);

        if (!isset($this->openSentenceTextByRole[$role])) {
            $this->openSentenceTextByRole[$role] = '';
            $this->openSentenceStartMsByRole[$role] = $startMs;
        }
        $this->openSentenceTextByRole[$role] .= $fragmentText;
        $this->openSentenceEndMsByRole[$role] = max($this->openSentenceEndMsByRole[$role] ?? 0, $endMs);
        $this->lastFragmentReceivedAtUnixMsByRole[$role] = $nowUnixMs;

        return $completedSentences;
    }

    /**
     * Close every open sentence whose speaker has been quiet for {@see IDLE_FLUSH_AFTER_MS}. The worker's periodic
     * tick calls this, because nothing else would ever close the LAST sentence before the other side speaks again.
     *
     * @return LiveTranscriptSentence[]
     */
    public function closeIdleSentences(int $nowUnixMs): array
    {
        $completedSentences = [];
        foreach (array_keys($this->openSentenceTextByRole) as $role) {
            $lastFragmentReceivedAtUnixMs = $this->lastFragmentReceivedAtUnixMsByRole[$role] ?? $nowUnixMs;
            if (($nowUnixMs - $lastFragmentReceivedAtUnixMs) >= static::IDLE_FLUSH_AFTER_MS) {
                array_push($completedSentences, ...$this->closeOpenSentenceForRole($role));
            }
        }

        return $completedSentences;
    }

    /**
     * Close everything that is still open: the call ended, nothing more will be spoken.
     *
     * @return LiveTranscriptSentence[]
     */
    public function closeAllSentences(): array
    {
        $completedSentences = [];
        foreach (array_keys($this->openSentenceTextByRole) as $role) {
            array_push($completedSentences, ...$this->closeOpenSentenceForRole($role));
        }

        return $completedSentences;
    }

    /** @var int Characters of the caller's open sentence already handed to a delegation; the rest is what is new. */
    protected int $consumedOpenCallerSentenceChars = 0;

    /**
     * The caller's open, not yet closed sentence (what a delegation composed mid-sentence must still include) minus
     * the part an earlier delegation already took ({@see consumeOpenCallerSentence()}): the same request is never
     * handed over twice.
     */
    public function getOpenCallerSentenceText(): string
    {
        $openCallerSentenceText = $this->openSentenceTextByRole[AIConversationMessage::ROLE_USER] ?? '';

        return trim(mb_substr($openCallerSentenceText, $this->consumedOpenCallerSentenceChars));
    }

    /**
     * Close the caller's open sentence NOW: the moment the model delegates, what the caller said so far is the
     * request and belongs in the transcript before the agent reads it, not 1.5 s later behind the agent's turn.
     *
     * @return LiveTranscriptSentence[]
     */
    public function closeOpenCallerSentence(): array
    {
        return $this->closeOpenSentenceForRole(AIConversationMessage::ROLE_USER);
    }

    /** Mark the caller's open sentence, as it is now, as handed over. */
    public function consumeOpenCallerSentence(): void
    {
        $this->consumedOpenCallerSentenceChars = mb_strlen(
            $this->openSentenceTextByRole[AIConversationMessage::ROLE_USER] ?? ''
        );
    }

    /**
     * @return LiveTranscriptSentence[]
     */
    protected function closeOpenSentenceForRole(string $role): array
    {
        $openSentenceText = trim($this->openSentenceTextByRole[$role] ?? '');
        $completedSentences = [];
        if ($openSentenceText !== '') {
            $completedSentences[] = LiveTranscriptSentence::create(
                $role,
                $openSentenceText,
                (int)($this->openSentenceStartMsByRole[$role] ?? 0),
                (int)($this->openSentenceEndMsByRole[$role] ?? 0)
            );
        }
        unset(
            $this->openSentenceTextByRole[$role],
            $this->openSentenceStartMsByRole[$role],
            $this->openSentenceEndMsByRole[$role],
            $this->lastFragmentReceivedAtUnixMsByRole[$role]
        );
        if ($role === AIConversationMessage::ROLE_USER) {
            $this->consumedOpenCallerSentenceChars = 0;
        }

        return $completedSentences;
    }
}
