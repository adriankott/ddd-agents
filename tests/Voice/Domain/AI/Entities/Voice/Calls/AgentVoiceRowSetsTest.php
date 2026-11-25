<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Entities\Voice\Calls;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceSeedInputMessage;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceSeedInputMessages;
use DDD\Domain\AI\Entities\Voice\Calls\LiveTranscriptSentence;
use DDD\Domain\AI\Entities\Voice\Calls\LiveTranscriptSentences;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;

/**
 * The two sets of rows a call carries in order — the seed rows of a session ({@see AgentVoiceSeedInputMessages}) and
 * the sentences the speakers finished ({@see LiveTranscriptSentences}): each row is held under the key of its own
 * object, so two rows with the same content stay two, the same object added twice is one row, the elements keep the
 * order of adding, and the factory without an argument gives an empty set.
 */
class AgentVoiceRowSetsTest extends AgentVoiceUnitTestCase
{
    public function testTwoSeedRowsWithTheSameRoleAndTextStayTwo(): void
    {
        $firstSeedRow = AgentVoiceSeedInputMessage::create(AgentVoiceSeedInputMessage::ROLE_USER, 'Yes.');
        $secondSeedRow = AgentVoiceSeedInputMessage::create(AgentVoiceSeedInputMessage::ROLE_USER, 'Yes.');

        $seedInputMessages = AgentVoiceSeedInputMessages::fromMessages($firstSeedRow, $secondSeedRow);

        static::assertCount(2, $seedInputMessages);
        static::assertSame([$firstSeedRow, $secondSeedRow], $seedInputMessages->getElements());
        static::assertNotSame($firstSeedRow->uniqueKey(), $secondSeedRow->uniqueKey());
    }

    public function testTheSeedRowsKeepTheOrderOfAdding(): void
    {
        $openingRow = AgentVoiceSeedInputMessage::create(AgentVoiceSeedInputMessage::ROLE_DEVELOPER, 'Opening');
        $callerRow = AgentVoiceSeedInputMessage::create(AgentVoiceSeedInputMessage::ROLE_USER, 'Hello');
        $agentRow = AgentVoiceSeedInputMessage::create(AgentVoiceSeedInputMessage::ROLE_ASSISTANT, 'Hi there');
        $seedInputMessages = AgentVoiceSeedInputMessages::fromMessages($agentRow, $openingRow);
        $seedInputMessages->add($callerRow);

        static::assertSame([$agentRow, $openingRow, $callerRow], $seedInputMessages->getElements());
        static::assertSame($agentRow, $seedInputMessages->first());
    }

    public function testTheSameSeedRowObjectAddedTwiceIsOneRow(): void
    {
        $seedRow = AgentVoiceSeedInputMessage::create(AgentVoiceSeedInputMessage::ROLE_DEVELOPER, 'Opening');

        $seedInputMessages = AgentVoiceSeedInputMessages::fromMessages($seedRow, $seedRow);
        $seedInputMessages->add($seedRow);

        static::assertSame([$seedRow], $seedInputMessages->getElements());
        static::assertSame($seedRow, $seedInputMessages->getByUniqueKey($seedRow->uniqueKey()));
    }

    public function testTheSeedRowFactoryWithoutARowGivesAnEmptySetOfItsClass(): void
    {
        $seedInputMessages = AgentVoiceSeedInputMessages::fromMessages();

        static::assertInstanceOf(AgentVoiceSeedInputMessages::class, $seedInputMessages);
        static::assertSame([], $seedInputMessages->getElements());
        static::assertCount(0, $seedInputMessages);
    }

    public function testTwoSentencesWithTheSameSpeakerWordsAndTimesStayTwo(): void
    {
        $firstSentence = LiveTranscriptSentence::create('USER', 'Yes.', 100, 400);
        $secondSentence = LiveTranscriptSentence::create('USER', 'Yes.', 100, 400);

        $liveTranscriptSentences = LiveTranscriptSentences::fromSentences($firstSentence, $secondSentence);

        static::assertCount(2, $liveTranscriptSentences);
        static::assertSame([$firstSentence, $secondSentence], $liveTranscriptSentences->getElements());
        static::assertNotSame($firstSentence->uniqueKey(), $secondSentence->uniqueKey());
    }

    public function testTheSentencesKeepTheOrderOfAddingNotTheOrderOfTheirTimes(): void
    {
        $laterSentence = LiveTranscriptSentence::create('ASSISTANT', 'Sure.', 2000, 2400);
        $earlierSentence = LiveTranscriptSentence::create('USER', 'Hello', 0, 900);
        $lastSentence = LiveTranscriptSentence::create('USER', 'Thanks', 3000, 3300);
        $liveTranscriptSentences = LiveTranscriptSentences::fromSentences($laterSentence, $earlierSentence);
        $liveTranscriptSentences->add($lastSentence);

        static::assertSame([$laterSentence, $earlierSentence, $lastSentence], $liveTranscriptSentences->getElements());
    }

    public function testTheSameSentenceObjectAddedTwiceIsOneSentence(): void
    {
        $sentence = LiveTranscriptSentence::create('USER', 'Hello', 0, 900);

        $liveTranscriptSentences = LiveTranscriptSentences::fromSentences($sentence, $sentence);
        $liveTranscriptSentences->add($sentence);

        static::assertSame([$sentence], $liveTranscriptSentences->getElements());
        static::assertSame($sentence, $liveTranscriptSentences->getByUniqueKey($sentence->uniqueKey()));
    }

    public function testTheSentenceFactoryWithoutASentenceGivesAnEmptySetOfItsClass(): void
    {
        $liveTranscriptSentences = LiveTranscriptSentences::fromSentences();

        static::assertInstanceOf(LiveTranscriptSentences::class, $liveTranscriptSentences);
        static::assertSame([], $liveTranscriptSentences->getElements());
        static::assertCount(0, $liveTranscriptSentences);
    }
}
