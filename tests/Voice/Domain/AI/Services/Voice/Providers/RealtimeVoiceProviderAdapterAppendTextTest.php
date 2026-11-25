<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Providers;

use DDD\Domain\AI\Services\Voice\Live\OpenAILiveVoiceProviderAdapter;
use DDD\Domain\AI\Services\Voice\Providers\RealtimeVoiceProviderAdapter;
use DDD\Infrastructure\Exceptions\BadGatewayException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;
use LogicException;
use RuntimeException;

/**
 * What the seam derives from an adapter's append size: a text cut to one append (the cut sentence included, moved
 * back to a sentence end above half the size) and a text split into appends in order; and what the trace reads of a
 * failed provider action. The adapter double has an append size of 100 characters; the cut sentence is the
 * package's prompt `AI.Voice.AnswerCut` ("The full answer is in the chat.", 31 characters plus its leading space).
 */
class RealtimeVoiceProviderAdapterAppendTextTest extends AgentVoiceUnitTestCase
{
    protected const string CUT_SUFFIX = ' The full answer is in the chat.';

    protected function buildAdapter(): FixedAppendCapRealtimeVoiceProviderAdapter
    {
        $fixedAppendCapAdapter = new FixedAppendCapRealtimeVoiceProviderAdapter();
        $fixedAppendCapAdapter->appendMaximumCharacters = 100;

        return $fixedAppendCapAdapter;
    }

    public function testATextWithinTheSizeIsOnlyTrimmed(): void
    {
        $fixedAppendCapAdapter = $this->buildAdapter();

        static::assertSame('Short answer.', $fixedAppendCapAdapter->cutToAppendCap("  Short answer.\n"));
        static::assertSame(str_repeat('a', 100), $fixedAppendCapAdapter->cutToAppendCap(' ' . str_repeat('a', 100) . ' '));
    }

    public function testALongTextWithoutASentenceEndIsCutHardAndLeavesRoomForTheCutSentence(): void
    {
        $cutText = $this->buildAdapter()->cutToAppendCap(str_repeat('a', 101));

        static::assertSame(str_repeat('a', 68) . static::CUT_SUFFIX, $cutText);
        static::assertSame(100, mb_strlen($cutText));
    }

    public function testTheCutMovesBackToASentenceEndAboveHalfTheSize(): void
    {
        $fixedAppendCapAdapter = $this->buildAdapter();
        $sentenceEndAt51 = str_repeat('a', 51) . '. ' . str_repeat('b', 80);
        $questionEndAt55 = str_repeat('a', 55) . '? ' . str_repeat('b', 80);
        $exclamationEndAt60 = str_repeat('a', 60) . '! ' . str_repeat('b', 80);

        static::assertSame(str_repeat('a', 51) . '.' . static::CUT_SUFFIX, $fixedAppendCapAdapter->cutToAppendCap($sentenceEndAt51));
        static::assertSame(str_repeat('a', 55) . '?' . static::CUT_SUFFIX, $fixedAppendCapAdapter->cutToAppendCap($questionEndAt55));
        static::assertSame(str_repeat('a', 60) . '!' . static::CUT_SUFFIX, $fixedAppendCapAdapter->cutToAppendCap($exclamationEndAt60));
    }

    public function testASentenceEndAtHalfTheSizeOrBelowDoesNotMoveTheCut(): void
    {
        $sentenceEndAt50 = str_repeat('a', 50) . '. ' . str_repeat('b', 80);

        static::assertSame(str_repeat('a', 50) . '. ' . str_repeat('b', 16) . static::CUT_SUFFIX, $this->buildAdapter()->cutToAppendCap($sentenceEndAt50));
    }

    public function testTheLastSentenceEndBeforeTheCutWins(): void
    {
        $twoSentenceEnds = str_repeat('a', 52) . '. ' . str_repeat('b', 8) . '. ' . str_repeat('c', 80);

        static::assertSame(str_repeat('a', 52) . '. ' . str_repeat('b', 8) . '.' . static::CUT_SUFFIX, $this->buildAdapter()->cutToAppendCap($twoSentenceEnds));
    }

    public function testALineBreakIsNoSentenceEndOfACut(): void
    {
        $lineBreakAt60 = str_repeat('a', 60) . "\n" . str_repeat('b', 80);

        static::assertSame(str_repeat('a', 60) . "\n" . str_repeat('b', 7) . static::CUT_SUFFIX, $this->buildAdapter()->cutToAppendCap($lineBreakAt60));
    }

    public function testWithoutTheCutPromptTheCutTakesTheWholeSize(): void
    {
        $fixedAppendCapAdapter = $this->buildAdapter();
        $fixedAppendCapAdapter->appendCutPromptName = 'AI.Voice.NoSuchPromptOfTheTest';

        static::assertSame(str_repeat('a', 100), $fixedAppendCapAdapter->cutToAppendCap(str_repeat('a', 150)));
    }

    public function testTheShippedAdapterCutsAt1200CharactersWithTheCutSentence(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith([]));

        $cutText = (new OpenAILiveVoiceProviderAdapter())->cutToAppendCap(str_repeat('a', 1300));

        static::assertSame(str_repeat('a', 1168) . static::CUT_SUFFIX, $cutText);
        static::assertSame(1200, mb_strlen($cutText));
    }

    public function testALongTextSplitsIntoPiecesAtSentenceEndsInOrder(): void
    {
        $firstSentence = str_repeat('a', 58) . '.';
        $secondSentence = str_repeat('b', 58) . '!';
        $thirdSentence = str_repeat('c', 58) . '?';

        $appendPieces = $this->buildAdapter()->splitIntoAppendPieces($firstSentence . ' ' . $secondSentence . ' ' . $thirdSentence);

        static::assertSame([$firstSentence, $secondSentence, $thirdSentence], $appendPieces);
    }

    public function testASplitAlsoEndsAPieceAtALineBreak(): void
    {
        $appendPieces = $this->buildAdapter()->splitIntoAppendPieces(str_repeat('a', 60) . "\n" . str_repeat('b', 60));

        static::assertSame([str_repeat('a', 60), str_repeat('b', 60)], $appendPieces);
    }

    public function testASplitWithoutASentenceEndAboveHalfTheSizeCutsHard(): void
    {
        $fixedAppendCapAdapter = $this->buildAdapter();

        static::assertSame([str_repeat('a', 100), str_repeat('a', 100), str_repeat('a', 50)], $fixedAppendCapAdapter->splitIntoAppendPieces(str_repeat('a', 250)));
        static::assertSame(
            [str_repeat('a', 50) . '. ' . str_repeat('b', 48), str_repeat('b', 32)],
            $fixedAppendCapAdapter->splitIntoAppendPieces(str_repeat('a', 50) . '. ' . str_repeat('b', 80))
        );
        static::assertSame(
            [str_repeat('a', 51) . '.', str_repeat('b', 80)],
            $fixedAppendCapAdapter->splitIntoAppendPieces(str_repeat('a', 51) . '. ' . str_repeat('b', 80))
        );
    }

    public function testAShortOrBlankTextSplitsIntoOneOrNoPiece(): void
    {
        $fixedAppendCapAdapter = $this->buildAdapter();

        static::assertSame(['short'], $fixedAppendCapAdapter->splitIntoAppendPieces('  short '));
        static::assertSame([], $fixedAppendCapAdapter->splitIntoAppendPieces("  \n "));
        static::assertSame([str_repeat('a', 100)], $fixedAppendCapAdapter->splitIntoAppendPieces(str_repeat('a', 100)));
    }

    public function testEverySplitPieceFitsOneAppendAndNothingIsLost(): void
    {
        $sentences = [];
        for ($sentenceNumber = 1; $sentenceNumber <= 12; $sentenceNumber++) {
            $sentences[] = 'Sentence number ' . $sentenceNumber . ' says something of moderate length.';
        }
        $longText = implode(' ', $sentences);

        $appendPieces = $this->buildAdapter()->splitIntoAppendPieces($longText);

        static::assertGreaterThan(2, count($appendPieces));
        foreach ($appendPieces as $appendPiece) {
            static::assertLessThanOrEqual(100, mb_strlen($appendPiece));
            static::assertStringEndsWith('.', $appendPiece);
        }
        static::assertSame($longText, implode(' ', $appendPieces));
    }

    public function testTheProviderUnavailableExceptionCarriesTheCodeAndTheProvidersWords(): void
    {
        $transportFailure = new LogicException('socket closed');

        $badGatewayException = $this->buildAdapter()->buildProviderUnavailableExceptionForTest('the provider said no', $transportFailure);

        static::assertSame(RealtimeVoiceProviderAdapter::ERROR_PROVIDER_UNAVAILABLE, $badGatewayException->getMessage());
        static::assertSame('provider_unavailable', $badGatewayException->getMessage());
        static::assertSame(502, $badGatewayException->getCode());
        static::assertInstanceOf(RuntimeException::class, $badGatewayException->getPrevious());
        static::assertSame('the provider said no', $badGatewayException->getPrevious()->getMessage());
        static::assertSame($transportFailure, $badGatewayException->getPrevious()->getPrevious());
    }

    public function testTheTraceReadsTheProvidersWordsOrTheFailuresOwnMessage(): void
    {
        $fixedAppendCapAdapter = $this->buildAdapter();

        static::assertSame('the provider said no', $fixedAppendCapAdapter->describeProviderFailure($fixedAppendCapAdapter->buildProviderUnavailableExceptionForTest('the provider said no')));
        static::assertSame('provider_unavailable', $fixedAppendCapAdapter->describeProviderFailure($fixedAppendCapAdapter->buildProviderUnavailableExceptionForTest('   ')));
        static::assertSame('provider_unavailable', $fixedAppendCapAdapter->describeProviderFailure(new BadGatewayException('provider_unavailable')));
        static::assertSame(
            'voice_provider_not_registered',
            $fixedAppendCapAdapter->describeProviderFailure(new InternalErrorException('voice_provider_not_registered', previous: new RuntimeException('inner words')))
        );
        static::assertSame('plain failure', $fixedAppendCapAdapter->describeProviderFailure(new RuntimeException('plain failure')));
    }
}
