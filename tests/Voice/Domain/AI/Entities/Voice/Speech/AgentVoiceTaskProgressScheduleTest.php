<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Entities\Voice\Speech;

use DDD\Domain\AI\Entities\Conversations\Agent\Activity\AgentActivityLine;
use DDD\Domain\AI\Entities\Conversations\Agent\Activity\AgentActivityLines;
use DDD\Domain\AI\Entities\Conversations\Agent\Activity\AgentActivityTurnFeed;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceTaskProgressSchedule;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceTaskProgressUpdate;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;

/**
 * What the caller hears while one delegated task runs: the first spoken line at 6 s at the earliest, then one per
 * 10 s, three per task, only after 2 quiet seconds; older new lines and the words of a step without a line as
 * unspoken context; the bridge after 12 s without a new line, twice per task, never together with a spoken line.
 */
class AgentVoiceTaskProgressScheduleTest extends AgentVoiceUnitTestCase
{
    protected const int DELEGATED_AT_UNIX_MS = 1_000_000;

    /** @param AgentActivityLine[] $activityLines */
    protected static function buildFeed(array $activityLines): AgentActivityTurnFeed
    {
        $agentActivityLines = new AgentActivityLines();
        foreach ($activityLines as $activityLine) {
            $agentActivityLines->add($activityLine);
        }

        return AgentActivityTurnFeed::buildForTurn(77, 5, 1, $agentActivityLines);
    }

    protected static function buildLine(int $toolCallMessageId, ?string $line, string $toolName = 'findEntries'): AgentActivityLine
    {
        $activityLine = new AgentActivityLine();
        $activityLine->toolCallMessageId = $toolCallMessageId;
        $activityLine->state = AgentActivityLine::STATE_RUNNING;
        $activityLine->line = $line;
        $activityLine->narratorToolName = $toolName;

        return $activityLine;
    }

    protected static function startSchedule(): AgentVoiceTaskProgressSchedule
    {
        return AgentVoiceTaskProgressSchedule::startForDelegationAt(static::DELEGATED_AT_UNIX_MS);
    }

    public function testNothingWithoutAFeedBeforeTheBridgeIsDue(): void
    {
        static::assertSame(
            ['spokenProgressLine' => null, 'silentContextLines' => [], 'bridgeDue' => false, 'firstLineSeenAfterMilliseconds' => null],
            static::describeUpdate(static::startSchedule()->decideUpdateAt(null, static::DELEGATED_AT_UNIX_MS + 11_000, null))
        );
    }

    public function testALineIsNotSpokenBeforeSixSecondsAndTheFeedLatencyIsReportedOnce(): void
    {
        $agentVoiceTaskProgressSchedule = static::startSchedule();
        $turnFeed = static::buildFeed([static::buildLine(11, 'I am reading the newest entry')]);

        $earlyDecision = $agentVoiceTaskProgressSchedule->decideUpdateAt($turnFeed, static::DELEGATED_AT_UNIX_MS + 5_999, null);
        static::assertNull($earlyDecision->spokenProgressLine);
        static::assertSame([], $earlyDecision->silentContextLines, 'a held line is not passed on as context either');
        static::assertSame(5_999, $earlyDecision->firstLineSeenAfterMilliseconds);

        $dueDecision = $agentVoiceTaskProgressSchedule->decideUpdateAt($turnFeed, static::DELEGATED_AT_UNIX_MS + 6_000, null);
        static::assertSame('I am reading the newest entry', $dueDecision->spokenProgressLine);
        static::assertNull($dueDecision->firstLineSeenAfterMilliseconds);
        static::assertSame(5_999, $agentVoiceTaskProgressSchedule->firstLineSeenAfterMilliseconds);
        static::assertSame(static::DELEGATED_AT_UNIX_MS + 6_000, $agentVoiceTaskProgressSchedule->lastSpokenProgressAtUnixMs);
    }

    public function testALineIsSpokenOnlyAfterTwoQuietSeconds(): void
    {
        $agentVoiceTaskProgressSchedule = static::startSchedule();
        $turnFeed = static::buildFeed([static::buildLine(11, 'Checking your entries')]);
        $nowUnixMs = static::DELEGATED_AT_UNIX_MS + 8_000;

        static::assertNull($agentVoiceTaskProgressSchedule->decideUpdateAt($turnFeed, $nowUnixMs, $nowUnixMs - 1_999)->spokenProgressLine);
        static::assertSame('Checking your entries', $agentVoiceTaskProgressSchedule->decideUpdateAt($turnFeed, $nowUnixMs, $nowUnixMs - 2_000)->spokenProgressLine);
    }

    public function testAHandledLineIsNeverSpokenTwice(): void
    {
        $agentVoiceTaskProgressSchedule = static::startSchedule();
        $turnFeed = static::buildFeed([static::buildLine(11, 'Checking your entries')]);

        $agentVoiceTaskProgressSchedule->decideUpdateAt($turnFeed, static::DELEGATED_AT_UNIX_MS + 7_000, null);

        static::assertNull($agentVoiceTaskProgressSchedule->decideUpdateAt($turnFeed, static::DELEGATED_AT_UNIX_MS + 30_000, null)->spokenProgressLine);
        static::assertSame(1, $agentVoiceTaskProgressSchedule->spokenProgressCount);
    }

    public function testSeveralNewLinesInOneWindowSpeakTheNewestAndPassTheOthersAsContext(): void
    {
        $turnFeed = static::buildFeed([
            static::buildLine(11, 'Reading your entries'),
            static::buildLine(12, 'Looking at the figures'),
            static::buildLine(13, 'Comparing the months'),
        ]);

        $progressDecision = static::startSchedule()->decideUpdateAt($turnFeed, static::DELEGATED_AT_UNIX_MS + 7_000, null);

        static::assertSame('Comparing the months', $progressDecision->spokenProgressLine);
        static::assertSame(['Reading your entries', 'Looking at the figures'], $progressDecision->silentContextLines);
        static::assertFalse($progressDecision->bridgeDue);
    }

    public function testASecondLineWaitsTenSecondsAfterTheFirstSpokenOne(): void
    {
        $agentVoiceTaskProgressSchedule = static::startSchedule();
        $agentVoiceTaskProgressSchedule->decideUpdateAt(static::buildFeed([static::buildLine(11, 'Reading your entries')]), static::DELEGATED_AT_UNIX_MS + 7_000, null);
        $turnFeed = static::buildFeed([static::buildLine(11, 'Reading your entries'), static::buildLine(12, 'Writing the reply')]);

        static::assertNull($agentVoiceTaskProgressSchedule->decideUpdateAt($turnFeed, static::DELEGATED_AT_UNIX_MS + 16_999, null)->spokenProgressLine);
        static::assertSame('Writing the reply', $agentVoiceTaskProgressSchedule->decideUpdateAt($turnFeed, static::DELEGATED_AT_UNIX_MS + 17_000, null)->spokenProgressLine);
    }

    public function testAtMostThreeSpokenLinesPerTaskThenEveryNewLineGoesAsContext(): void
    {
        $agentVoiceTaskProgressSchedule = static::startSchedule();
        $activityLines = [];
        $nowUnixMs = static::DELEGATED_AT_UNIX_MS + 6_000;
        $spokenLines = [];
        $contextLinesOverTheCap = [];
        for ($lineNumber = 1; $lineNumber <= 4; $lineNumber++) {
            $activityLines[] = static::buildLine(10 + $lineNumber, "Step {$lineNumber}");
            $progressDecision = $agentVoiceTaskProgressSchedule->decideUpdateAt(static::buildFeed($activityLines), $nowUnixMs, null);
            if ($progressDecision->spokenProgressLine !== null) {
                $spokenLines[] = $progressDecision->spokenProgressLine;
            } else {
                $contextLinesOverTheCap = $progressDecision->silentContextLines;
            }
            $nowUnixMs += AgentVoiceTaskProgressSchedule::MINIMUM_MILLISECONDS_BETWEEN_SPOKEN_PROGRESS;
        }

        static::assertSame(['Step 1', 'Step 2', 'Step 3'], $spokenLines);
        static::assertSame(['Step 4'], $contextLinesOverTheCap);
        static::assertSame(3, $agentVoiceTaskProgressSchedule->spokenProgressCount);
    }

    public function testAStepWithoutALineGoesOnceAsContextInItsOwnWordsAndItsLineIsSpokenLater(): void
    {
        $agentVoiceTaskProgressSchedule = static::startSchedule();
        $stepWithoutLine = static::buildLine(11, null, 'getWebPageAsMarkdown');

        $firstDecision = $agentVoiceTaskProgressSchedule->decideUpdateAt(static::buildFeed([$stepWithoutLine]), static::DELEGATED_AT_UNIX_MS + 1_000, null);
        static::assertSame(['Working on: get web page as markdown.'], $firstDecision->silentContextLines);
        static::assertNull($firstDecision->firstLineSeenAfterMilliseconds, 'a step without a line is no line');

        static::assertSame([], $agentVoiceTaskProgressSchedule->decideUpdateAt(static::buildFeed([$stepWithoutLine]), static::DELEGATED_AT_UNIX_MS + 2_000, null)->silentContextLines);

        $narratedDecision = $agentVoiceTaskProgressSchedule->decideUpdateAt(
            static::buildFeed([static::buildLine(11, 'Reading your website', 'getWebPageAsMarkdown')]),
            static::DELEGATED_AT_UNIX_MS + 7_000,
            null
        );
        static::assertSame('Reading your website', $narratedDecision->spokenProgressLine);
    }

    public function testASkippedStepIsNeverPassedOn(): void
    {
        $skippedStep = static::buildLine(11, null, 'findEntries');
        $skippedStep->state = AgentActivityLine::STATE_SKIPPED;

        static::assertSame([], static::startSchedule()->decideUpdateAt(static::buildFeed([$skippedStep]), static::DELEGATED_AT_UNIX_MS + 1_000, null)->silentContextLines);
    }

    public function testTheBridgeComesAfterTwelveSecondsWithoutANewLineOnlyWhenQuiet(): void
    {
        $agentVoiceTaskProgressSchedule = static::startSchedule();
        $nowUnixMs = static::DELEGATED_AT_UNIX_MS + AgentVoiceTaskProgressSchedule::BRIDGE_AFTER_MILLISECONDS_WITHOUT_NEW_LINE;

        static::assertFalse($agentVoiceTaskProgressSchedule->decideUpdateAt(null, $nowUnixMs - 1, null)->bridgeDue);
        static::assertFalse($agentVoiceTaskProgressSchedule->decideUpdateAt(null, $nowUnixMs, $nowUnixMs - 500)->bridgeDue, 'not while somebody speaks');
        static::assertTrue($agentVoiceTaskProgressSchedule->decideUpdateAt(null, $nowUnixMs, $nowUnixMs - 2_000)->bridgeDue);
        static::assertSame(1, $agentVoiceTaskProgressSchedule->bridgeCount);
        static::assertSame($nowUnixMs, $agentVoiceTaskProgressSchedule->lastBridgeAtUnixMs);
    }

    public function testAPassedOnLineRestartsTheBridgeClock(): void
    {
        $agentVoiceTaskProgressSchedule = static::startSchedule();
        $turnFeed = static::buildFeed([static::buildLine(11, 'Reading your entries')]);
        $agentVoiceTaskProgressSchedule->decideUpdateAt($turnFeed, static::DELEGATED_AT_UNIX_MS + 8_000, null);

        static::assertFalse($agentVoiceTaskProgressSchedule->decideUpdateAt($turnFeed, static::DELEGATED_AT_UNIX_MS + 19_999, null)->bridgeDue);
        static::assertTrue($agentVoiceTaskProgressSchedule->decideUpdateAt($turnFeed, static::DELEGATED_AT_UNIX_MS + 20_000, null)->bridgeDue);
    }

    public function testAtMostTwoBridgesPerTaskTwelveSecondsApart(): void
    {
        $agentVoiceTaskProgressSchedule = static::startSchedule();
        $bridgeInterval = AgentVoiceTaskProgressSchedule::BRIDGE_AFTER_MILLISECONDS_WITHOUT_NEW_LINE;

        static::assertTrue($agentVoiceTaskProgressSchedule->decideUpdateAt(null, static::DELEGATED_AT_UNIX_MS + $bridgeInterval, null)->bridgeDue);
        static::assertFalse($agentVoiceTaskProgressSchedule->decideUpdateAt(null, static::DELEGATED_AT_UNIX_MS + $bridgeInterval + 500, null)->bridgeDue);
        static::assertTrue($agentVoiceTaskProgressSchedule->decideUpdateAt(null, static::DELEGATED_AT_UNIX_MS + 2 * $bridgeInterval, null)->bridgeDue);
        static::assertFalse($agentVoiceTaskProgressSchedule->decideUpdateAt(null, static::DELEGATED_AT_UNIX_MS + 10 * $bridgeInterval, null)->bridgeDue);
        static::assertSame(2, $agentVoiceTaskProgressSchedule->bridgeCount);
    }

    public function testASpokenLineAndTheBridgeNeverGoOutTogether(): void
    {
        $progressDecision = static::startSchedule()->decideUpdateAt(static::buildFeed([static::buildLine(11, 'Reading your entries')]), static::DELEGATED_AT_UNIX_MS + 30_000, null);

        static::assertSame('Reading your entries', $progressDecision->spokenProgressLine);
        static::assertFalse($progressDecision->bridgeDue);
    }

    public function testTheStepWordsOfAToolName(): void
    {
        static::assertSame('', AgentVoiceTaskProgressSchedule::buildStepWords(static::buildLine(11, null, '  ')));
        static::assertSame('Working on: search web.', AgentVoiceTaskProgressSchedule::buildStepWords(static::buildLine(11, null, 'search_web')));
        static::assertSame('Working on: read page v2 now.', AgentVoiceTaskProgressSchedule::buildStepWords(static::buildLine(11, null, 'readPage.v2--now')));
    }

    public function testQuietMeansNobodySpokeForTwoSecondsOrAtAll(): void
    {
        static::assertTrue(AgentVoiceTaskProgressSchedule::isQuietAt(5_000, null));
        static::assertTrue(AgentVoiceTaskProgressSchedule::isQuietAt(5_000, 3_000));
        static::assertFalse(AgentVoiceTaskProgressSchedule::isQuietAt(5_000, 3_001));
    }

    /** @return array{spokenProgressLine: string|null, silentContextLines: string[], bridgeDue: bool, firstLineSeenAfterMilliseconds: int|null} every field of the answer */
    protected static function describeUpdate(AgentVoiceTaskProgressUpdate $agentVoiceTaskProgressUpdate): array
    {
        return [
            'spokenProgressLine' => $agentVoiceTaskProgressUpdate->spokenProgressLine,
            'silentContextLines' => $agentVoiceTaskProgressUpdate->silentContextLines,
            'bridgeDue' => $agentVoiceTaskProgressUpdate->bridgeDue,
            'firstLineSeenAfterMilliseconds' => $agentVoiceTaskProgressUpdate->firstLineSeenAfterMilliseconds,
        ];
    }
}
