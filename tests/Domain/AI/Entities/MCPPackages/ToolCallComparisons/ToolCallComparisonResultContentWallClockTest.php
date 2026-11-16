<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\MCPPackages\ToolCallComparisons;

use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonCaseOutcome;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonSession;
use DDD\Domain\AI\Services\Eval\ToolCallComparisonsService;
use DDD\Domain\Base\Entities\RecordedRuns\RecordedRunClockWindow;
use DDD\Domain\Base\Entities\RecordedRuns\RecordedRunWallClockNormalization;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The run's wall-clock rule on tool RESULTS: a structured result serialises rows the run created, so their
 * `changeHistory.createdTime` differs between a record and its replay. The time-named keys of the JSON the result
 * carries read `<clock>` inside the run's window; a moment outside the window, a changed non-moment value of the same
 * JSON and a moment in free text are still compared as they are.
 */
class ToolCallComparisonResultContentWallClockTest extends UnitTestCase
{
    protected const string RECORD_RUN_START = '2026-10-22 10:47:30+02:00';

    protected const string REPLAY_RUN_START = '2026-10-22 10:49:55+02:00';

    protected const string ROW_CREATED_BEFORE_THE_RUN = '2026-03-02 09:15:00+01:00';

    protected static function buildRunWallClockRule(string $runStart): RecordedRunWallClockNormalization
    {
        $runStartTimestamp = (int)strtotime($runStart);
        $pinnedMoment = date('Y-m-d H:i:s', $runStartTimestamp - ToolCallComparisonSession::PINNED_MOMENT_LEAD_SECONDS);
        return RecordedRunWallClockNormalization::declare(
            RecordedRunClockWindow::between($runStartTimestamp - 1, $runStartTimestamp + 300),
            [$pinnedMoment, (string)strtotime($pinnedMoment)]
        );
    }

    protected static function newSession(): ToolCallComparisonSession
    {
        /** @var ToolCallComparisonsService $toolCallComparisonsService */
        $toolCallComparisonsService = DDDService::instance()->getService(ToolCallComparisonsService::class);
        return $toolCallComparisonsService->newSession();
    }

    /**
     * The executor's JSON of a structured MCP response: the sources serialised into the text part, one row the run
     * created (its creation moment) and one row that existed before the run.
     */
    protected static function buildStructuredSourcesResult(string $createdTimeOfTheRunRow, float $averageRating, string $createdTimeOfTheOlderRow = self::ROW_CREATED_BEFORE_THE_RUN): string
    {
        $sources = [
            'sources' => [
                ['name' => 'Source A', 'averageRating' => $averageRating, 'changeHistory' => ['createdTime' => $createdTimeOfTheRunRow]],
                ['name' => 'Source B', 'averageRating' => 4.0, 'changeHistory' => ['createdTime' => $createdTimeOfTheOlderRow]],
            ],
        ];
        return (string)json_encode([
            'content' => [['type' => 'text', 'text' => (string)json_encode($sources)]],
            'isError' => false,
        ]);
    }

    protected static function buildOutcome(string $resultContent): ToolCallComparisonCaseOutcome
    {
        $caseOutcome = ToolCallComparisonCaseOutcome::forCase('getSources.structured', 'getSources');
        $caseOutcome->resultContent = $resultContent;
        return $caseOutcome;
    }

    /** @return string[] the differences of the replayed result against the recorded one */
    protected static function compareResults(string $recordedResultContent, string $replayedResultContent): array
    {
        $recordedOutcome = self::buildOutcome(self::newSession()->buildComparableResultContent($recordedResultContent, self::buildRunWallClockRule(self::RECORD_RUN_START)));
        $replayedOutcome = self::buildOutcome(self::newSession()->buildComparableResultContent($replayedResultContent, self::buildRunWallClockRule(self::REPLAY_RUN_START)));
        return $recordedOutcome->buildDifferencesFrom($replayedOutcome);
    }

    public function testTheCreationMomentOfARowTheRunCreatedReadsAClockPlaceholderInTheStructuredResult(): void
    {
        $comparableResultContent = self::newSession()->buildComparableResultContent(
            self::buildStructuredSourcesResult('2026-10-22 10:48:53+02:00', 4.5),
            self::buildRunWallClockRule(self::RECORD_RUN_START)
        );

        $sources = json_decode((string)json_decode($comparableResultContent, true)['content'][0]['text'], true)['sources'];
        self::assertSame('<clock>', $sources[0]['changeHistory']['createdTime']);
        self::assertSame(self::ROW_CREATED_BEFORE_THE_RUN, $sources[1]['changeHistory']['createdTime'], 'a moment outside the run window stays raw');
        self::assertSame(4.5, $sources[0]['averageRating']);
    }

    public function testARecordAndItsReplayAreIdenticalAndAChangedValueOfTheSameJsonIsReported(): void
    {
        $recordedResultContent = self::buildStructuredSourcesResult('2026-10-22 10:48:53+02:00', 4.5);

        self::assertSame([], self::compareResults($recordedResultContent, self::buildStructuredSourcesResult('2026-10-22 10:51:17+02:00', 4.5)));

        $differences = self::compareResults($recordedResultContent, self::buildStructuredSourcesResult('2026-10-22 10:51:17+02:00', 4.6));
        self::assertCount(1, $differences, 'a changed non-moment value next to the normalised moment is still a difference');
        self::assertStringContainsString('4.6', $differences[0]);
    }

    public function testAChangedMomentOutsideTheRunWindowIsReported(): void
    {
        $differences = self::compareResults(
            self::buildStructuredSourcesResult('2026-10-22 10:48:53+02:00', 4.5),
            self::buildStructuredSourcesResult('2026-10-22 10:51:17+02:00', 4.5, '2026-03-03 09:15:00+01:00')
        );

        self::assertCount(1, $differences, 'only moments inside the run window are normalised');
        self::assertStringContainsString('2026-03-03 09:15:00', $differences[0]);
    }

    public function testAMomentInAFreeTextResultStaysRaw(): void
    {
        $recordedResultContent = (string)json_encode(['content' => [['type' => 'text', 'text' => "## Sources\n- Source A, connected 2026-10-22 10:48:53"]], 'isError' => false]);
        $replayedResultContent = (string)json_encode(['content' => [['type' => 'text', 'text' => "## Sources\n- Source A, connected 2026-10-22 10:51:17"]], 'isError' => false]);

        self::assertSame($recordedResultContent, self::newSession()->buildComparableResultContent($recordedResultContent, self::buildRunWallClockRule(self::RECORD_RUN_START)), 'a markdown result keeps its bytes');
        self::assertCount(1, self::compareResults($recordedResultContent, $replayedResultContent), 'a free-text moment is compared raw');
    }
}
