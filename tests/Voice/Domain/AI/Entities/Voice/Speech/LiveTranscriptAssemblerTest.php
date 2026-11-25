<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Entities\Voice\Speech;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Voice\Calls\LiveTranscriptAssembler;
use DDD\Domain\AI\Entities\Voice\Calls\LiveTranscriptSentence;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;
use DDD\Tests\Agents\Voice\Support\AgentVoiceValueObjectAssertionsTrait;

/**
 * The two sentence rules of the live transcript: a fragment of the other speaker that starts a real pause
 * (800 ms) after the open sentence's last word closes it, and a speaker quiet for 1.5 s has their own sentence closed
 * by the tick. Plus what a delegation reads and takes of the caller's open sentence.
 */
class LiveTranscriptAssemblerTest extends AgentVoiceUnitTestCase
{
    use AgentVoiceValueObjectAssertionsTrait;

    protected const string CALLER = AIConversationMessage::ROLE_USER;

    protected const string AGENT = AIConversationMessage::ROLE_ASSISTANT;

    public function testFragmentsOfOneSpeakerAccumulateUntilTheOtherSpeaksAfterAPause(): void
    {
        $liveTranscriptAssembler = new LiveTranscriptAssembler();

        static::assertSame([], $liveTranscriptAssembler->appendFragment(static::CALLER, 'Hello, ', 0, 400, 1000));
        static::assertSame([], $liveTranscriptAssembler->appendFragment(static::CALLER, 'I need', 400, 900, 1400));
        $completedSentences = $liveTranscriptAssembler->appendFragment(static::AGENT, 'Sure.', 900 + LiveTranscriptAssembler::TURN_CHANGE_GAP_MS, 2200, 2000);

        static::assertSameValueObjects([LiveTranscriptSentence::create(static::CALLER, 'Hello, I need', 0, 900)], $completedSentences);
        static::assertSame(2200, $liveTranscriptAssembler->getLastFragmentEndMs());
    }

    public function testAFragmentOneMillisecondShortOfThePauseLeavesBothOpen(): void
    {
        $liveTranscriptAssembler = new LiveTranscriptAssembler();
        $liveTranscriptAssembler->appendFragment(static::CALLER, 'Hello', 0, 900, 1000);

        static::assertSame([], $liveTranscriptAssembler->appendFragment(static::AGENT, 'Hi', 1699, 2000, 2000));
        static::assertCount(2, $liveTranscriptAssembler->closeAllSentences());
    }

    public function testThePauseIs800AndTheIdleTime1500Milliseconds(): void
    {
        $liveTranscriptAssembler = new LiveTranscriptAssembler();
        $liveTranscriptAssembler->appendFragment(static::CALLER, 'Hello', 0, 900, 1000);
        static::assertCount(1, $liveTranscriptAssembler->appendFragment(static::AGENT, 'Hi', 1700, 2000, 2000));

        static::assertSame([], $liveTranscriptAssembler->closeIdleSentences(3499));
        static::assertSameValueObjects([LiveTranscriptSentence::create(static::AGENT, 'Hi', 1700, 2000)], $liveTranscriptAssembler->closeIdleSentences(3500));
    }

    public function testAnIdleSpeakersSentenceIsClosedByTheTickOnce(): void
    {
        $liveTranscriptAssembler = new LiveTranscriptAssembler();
        $liveTranscriptAssembler->appendFragment(static::AGENT, 'One moment.', 0, 700, 1000);

        static::assertSame([], $liveTranscriptAssembler->closeIdleSentences(1000 + LiveTranscriptAssembler::IDLE_FLUSH_AFTER_MS - 1));
        static::assertSameValueObjects(
            [LiveTranscriptSentence::create(static::AGENT, 'One moment.', 0, 700)],
            $liveTranscriptAssembler->closeIdleSentences(1000 + LiveTranscriptAssembler::IDLE_FLUSH_AFTER_MS)
        );
        static::assertSame([], $liveTranscriptAssembler->closeIdleSentences(99999));
    }

    public function testTheTickClosesOnlyTheSpeakerWhoIsQuiet(): void
    {
        $liveTranscriptAssembler = new LiveTranscriptAssembler();
        $liveTranscriptAssembler->appendFragment(static::AGENT, 'Let me check', 0, 500, 1000);
        $liveTranscriptAssembler->appendFragment(static::CALLER, 'mm', 400, 500, 2000);

        $completedSentences = $liveTranscriptAssembler->closeIdleSentences(2600);

        static::assertSameValueObjects([LiveTranscriptSentence::create(static::AGENT, 'Let me check', 0, 500)], $completedSentences);
        static::assertSame('mm', $liveTranscriptAssembler->getOpenCallerSentenceText());
    }

    public function testInterjectionsKeepBothOpenAndOnlyARealPauseClosesTheOther(): void
    {
        $liveTranscriptAssembler = new LiveTranscriptAssembler();
        $liveTranscriptAssembler->appendFragment(static::AGENT, 'Last time ', 0, 300, 1000);
        static::assertSame([], $liveTranscriptAssembler->appendFragment(static::CALLER, 'Mhm', 400, 600, 1100));
        static::assertSame([], $liveTranscriptAssembler->appendFragment(static::AGENT, 'we spoke', 650, 1100, 1300));

        $completedOnTurnChange = $liveTranscriptAssembler->appendFragment(static::CALLER, ' right', 1100 + LiveTranscriptAssembler::TURN_CHANGE_GAP_MS, 2300, 2400);

        static::assertSameValueObjects([LiveTranscriptSentence::create(static::AGENT, 'Last time we spoke', 0, 1100)], $completedOnTurnChange);
        static::assertSameValueObjects([LiveTranscriptSentence::create(static::CALLER, 'Mhm right', 400, 2300)], $liveTranscriptAssembler->closeAllSentences());
        static::assertSame([], $liveTranscriptAssembler->closeAllSentences());
    }

    public function testTheOpenCallerSentenceIsReadableBeforeItCloses(): void
    {
        $liveTranscriptAssembler = new LiveTranscriptAssembler();
        $liveTranscriptAssembler->appendFragment(static::CALLER, 'How are ', 0, 300, 1000);
        $liveTranscriptAssembler->appendFragment(static::CALLER, 'my figures?', 300, 900, 1200);

        static::assertSame('How are my figures?', $liveTranscriptAssembler->getOpenCallerSentenceText());
        static::assertSame('', (new LiveTranscriptAssembler())->getOpenCallerSentenceText());
    }

    public function testBlankFragmentsProduceNoSentence(): void
    {
        $liveTranscriptAssembler = new LiveTranscriptAssembler();
        $liveTranscriptAssembler->appendFragment(static::CALLER, '   ', 0, 100, 1000);

        static::assertSame([], $liveTranscriptAssembler->appendFragment(static::AGENT, 'Hi', 900, 1000, 1100));
    }

    public function testTheOpenCallerSentenceIsHandedOverOnlyOnce(): void
    {
        $liveTranscriptAssembler = new LiveTranscriptAssembler();
        $liveTranscriptAssembler->appendFragment(static::CALLER, 'my code is 1 2 3', 0, 900, 1000);

        static::assertSame('my code is 1 2 3', $liveTranscriptAssembler->getOpenCallerSentenceText());
        $liveTranscriptAssembler->consumeOpenCallerSentence();
        static::assertSame('', $liveTranscriptAssembler->getOpenCallerSentenceText());

        $liveTranscriptAssembler->appendFragment(static::CALLER, ' 4 5 6', 900, 1400, 1500);
        static::assertSame('4 5 6', $liveTranscriptAssembler->getOpenCallerSentenceText());
        static::assertSame(1400, $liveTranscriptAssembler->getLastFragmentEndMs());

        $liveTranscriptAssembler->closeAllSentences();
        $liveTranscriptAssembler->appendFragment(static::CALLER, 'next', 2000, 2300, 2400);
        static::assertSame('next', $liveTranscriptAssembler->getOpenCallerSentenceText());
    }

    public function testADelegationClosesTheCallersOpenSentenceAtOnceAndLeavesTheAgentsOpen(): void
    {
        $liveTranscriptAssembler = new LiveTranscriptAssembler();
        $liveTranscriptAssembler->appendFragment(static::CALLER, 'show me my figures', 0, 900, 1000);
        $liveTranscriptAssembler->appendFragment(static::AGENT, 'one moment', 800, 1200, 1300);

        static::assertSameValueObjects([LiveTranscriptSentence::create(static::CALLER, 'show me my figures', 0, 900)], $liveTranscriptAssembler->closeOpenCallerSentence());
        static::assertSame('', $liveTranscriptAssembler->getOpenCallerSentenceText());
        static::assertSameValueObjects([LiveTranscriptSentence::create(static::AGENT, 'one moment', 800, 1200)], $liveTranscriptAssembler->closeAllSentences());
    }

    public function testTheTimelineMarkNeverMovesBackForALateFragment(): void
    {
        $liveTranscriptAssembler = new LiveTranscriptAssembler();
        $liveTranscriptAssembler->appendFragment(static::AGENT, 'later words', 2000, 3000, 1000);
        $liveTranscriptAssembler->appendFragment(static::CALLER, 'early words', 100, 400, 1100);

        static::assertSame(3000, $liveTranscriptAssembler->getLastFragmentEndMs());
    }
}
