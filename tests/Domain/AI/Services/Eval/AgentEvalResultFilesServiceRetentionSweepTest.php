<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

/**
 * The retention sweep over the results folder: a run entry (a folder or a loose file) whose name carries a date older
 * than the retention is deleted with everything below it; an entry the sweep does not recognise is never touched, a
 * symbolic link is neither followed nor removed, and a link inside a deleted run folder goes as a link while its
 * target stays. An application's own dated folders are swept once its service names their pattern.
 */
class AgentEvalResultFilesServiceRetentionSweepTest extends AgentEvalResultFilesTestCase
{
    /** The moment the tests count back from: 2026-07-29 noon, local time — 28 days back is 2026-07-01 noon. */
    protected function getNowTimestamp(): int
    {
        return (int)mktime(12, 0, 0, 7, 29, 2026);
    }

    public function testAnExpiredRunFolderAndAnExpiredRunFileAreDeletedAndTheNewerOnesStay(): void
    {
        $this->writeTemporaryFile($this->resultsDirectory . '/2026-07-01_235959-OPENAI.GPT6_LUNA-all/case.json', str_repeat('a', 1000));
        $this->writeTemporaryFile($this->resultsDirectory . '/2026-07-01_235959-OPENAI.GPT6_LUNA-all/nested/deeper.json', str_repeat('b', 500));
        $this->writeTemporaryFile($this->resultsDirectory . '/2026-06-12_080000-suite-night/_suite.json', str_repeat('c', 24));
        $this->writeTemporaryFile($this->resultsDirectory . '/2026-06-30_101010-default-case-x.json', str_repeat('d', 76));
        $this->writeTemporaryFile($this->resultsDirectory . '/2026-07-02_000000-OPENAI.GPT6_LUNA-all/case.json', 'kept');
        $this->writeTemporaryFile($this->resultsDirectory . '/2026-07-28_120000-default-all.json', 'kept');

        $agentEvalResultsRetentionSweep = $this->getResultFilesService()->sweepRunEntriesOlderThanRetention($this->getNowTimestamp());

        self::assertDirectoryDoesNotExist($this->resultsDirectory . '/2026-07-01_235959-OPENAI.GPT6_LUNA-all', 'dated on the last day older than 28 days');
        self::assertDirectoryDoesNotExist($this->resultsDirectory . '/2026-06-12_080000-suite-night', 'a suite run folder carries the same date');
        self::assertFileDoesNotExist($this->resultsDirectory . '/2026-06-30_101010-default-case-x.json');
        self::assertFileExists($this->resultsDirectory . '/2026-07-02_000000-OPENAI.GPT6_LUNA-all/case.json', 'dated inside the retention');
        self::assertFileExists($this->resultsDirectory . '/2026-07-28_120000-default-all.json');
        self::assertSame(3, $agentEvalResultsRetentionSweep->getDeletedEntryCount());
        self::assertSame(1600, $agentEvalResultsRetentionSweep->getFreedBytes());
        self::assertSame(28, $agentEvalResultsRetentionSweep->getRetentionDays());
        self::assertSame('Retention sweep: deleted 3 run artifact(s) older than 28 days (0.0 MB freed)', $agentEvalResultsRetentionSweep->buildReportLine());
    }

    public function testAnEntryTheSweepDoesNotRecogniseIsNeverDeletedHoweverOld(): void
    {
        $unrecognisedEntries = [
            'character-tone-logs/2020-01-01_000000-old.json',
            'tool-errors.jsonl',
            'harness.sh',
            'warmup-20200101-120000-77/result.json',
            'website-2020-01-01_000000/case.json',
            'notes-2020-01-01_000000-x.json',
            '2020-01-01-no-time/case.json',
        ];
        foreach ($unrecognisedEntries as $unrecognisedEntry) {
            $this->writeTemporaryFile($this->resultsDirectory . '/' . $unrecognisedEntry, 'curated');
            touch($this->resultsDirectory . '/' . $unrecognisedEntry, (int)mktime(0, 0, 0, 1, 1, 2020));
        }

        $agentEvalResultsRetentionSweep = $this->getResultFilesService()->sweepRunEntriesOlderThanRetention($this->getNowTimestamp());

        foreach ($unrecognisedEntries as $unrecognisedEntry) {
            self::assertFileExists($this->resultsDirectory . '/' . $unrecognisedEntry, 'the name carries no date the sweep recognises, the modification time does not count');
        }
        self::assertSame(0, $agentEvalResultsRetentionSweep->getDeletedEntryCount());
        self::assertNull($agentEvalResultsRetentionSweep->buildReportLine(), 'a sweep that deleted nothing prints nothing');
    }

    public function testASymbolicLinkInTheResultsFolderIsNeitherFollowedNorRemoved(): void
    {
        $outsideDirectory = $this->temporaryDirectory . '/outside';
        $this->writeTemporaryFile($outsideDirectory . '/precious.json', 'outside the results folder');
        $this->writeTemporaryFile($this->temporaryDirectory . '/outside-file.json', 'outside the results folder');
        symlink($outsideDirectory, $this->resultsDirectory . '/2020-01-01_000000-linked-folder');
        symlink($this->temporaryDirectory . '/outside-file.json', $this->resultsDirectory . '/2020-01-01_000000-linked-file.json');

        $agentEvalResultsRetentionSweep = $this->getResultFilesService()->sweepRunEntriesOlderThanRetention($this->getNowTimestamp());

        self::assertTrue(is_link($this->resultsDirectory . '/2020-01-01_000000-linked-folder'), 'the link stays');
        self::assertTrue(is_link($this->resultsDirectory . '/2020-01-01_000000-linked-file.json'), 'the link stays');
        self::assertFileExists($outsideDirectory . '/precious.json', 'the target of the link is never entered');
        self::assertFileExists($this->temporaryDirectory . '/outside-file.json');
        self::assertSame(0, $agentEvalResultsRetentionSweep->getDeletedEntryCount());
    }

    public function testALinkInsideAnExpiredRunFolderGoesAsALinkAndItsTargetStays(): void
    {
        $outsideDirectory = $this->temporaryDirectory . '/outside';
        $this->writeTemporaryFile($outsideDirectory . '/precious.json', str_repeat('p', 300));
        $this->writeTemporaryFile($this->temporaryDirectory . '/outside-file.json', str_repeat('q', 200));
        $expiredRunDirectory = $this->resultsDirectory . '/2020-01-01_000000-OPENAI.GPT6_LUNA-all';
        $this->writeTemporaryFile($expiredRunDirectory . '/case.json', str_repeat('r', 10));
        symlink($outsideDirectory, $expiredRunDirectory . '/linked-folder');
        symlink($this->temporaryDirectory . '/outside-file.json', $expiredRunDirectory . '/linked-file.json');

        $agentEvalResultsRetentionSweep = $this->getResultFilesService()->sweepRunEntriesOlderThanRetention($this->getNowTimestamp());

        self::assertFalse(file_exists($expiredRunDirectory) || is_link($expiredRunDirectory . '/linked-folder'), 'the run folder and its links are gone');
        self::assertFileExists($outsideDirectory . '/precious.json');
        self::assertSame(str_repeat('q', 200), file_get_contents($this->temporaryDirectory . '/outside-file.json'));
        self::assertSame(1, $agentEvalResultsRetentionSweep->getDeletedEntryCount());
        self::assertSame(10, $agentEvalResultsRetentionSweep->getFreedBytes(), 'the bytes of a link target are not the run folder\'s');
    }

    public function testTheApplicationsOwnDatedFoldersAreSweptOnlyAsFolders(): void
    {
        $this->writeTemporaryFile($this->resultsDirectory . '/seed-20200101-120000-77/result.json', 'seed');
        $this->writeTemporaryFile($this->resultsDirectory . '/seed-20260720-120000-78/result.json', 'seed');
        $this->writeTemporaryFile($this->resultsDirectory . '/seed-20200101-120000-79.json', 'a file, not a folder');
        $this->writeTemporaryFile($this->resultsDirectory . '/seed-replay-20200101-120000-80/result.json', 'another kind');

        $packageSweep = $this->getResultFilesService()->sweepRunEntriesOlderThanRetention($this->getNowTimestamp());
        self::assertSame(0, $packageSweep->getDeletedEntryCount(), 'the package does not know the application\'s folders');
        self::assertDirectoryExists($this->resultsDirectory . '/seed-20200101-120000-77');

        $applicationSweep = (new AgentEvalResultFilesServiceWithApplicationFoldersDouble())->sweepRunEntriesOlderThanRetention($this->getNowTimestamp());

        self::assertDirectoryDoesNotExist($this->resultsDirectory . '/seed-20200101-120000-77');
        self::assertDirectoryExists($this->resultsDirectory . '/seed-20260720-120000-78', 'inside the retention');
        self::assertFileExists($this->resultsDirectory . '/seed-20200101-120000-79.json', 'the pattern names folders');
        self::assertDirectoryExists($this->resultsDirectory . '/seed-replay-20200101-120000-80');
        self::assertSame(1, $applicationSweep->getDeletedEntryCount());
    }

    public function testAMissingResultsFolderIsNoError(): void
    {
        $this->setResultsDirectoryConfigValue($this->temporaryDirectory . '/never-created');

        $agentEvalResultsRetentionSweep = $this->getResultFilesService()->sweepRunEntriesOlderThanRetention($this->getNowTimestamp());

        self::assertSame(0, $agentEvalResultsRetentionSweep->getDeletedEntryCount());
        self::assertDirectoryDoesNotExist($this->temporaryDirectory . '/never-created');
    }

    public function testNothingAboveTheResultsFolderIsTouched(): void
    {
        $this->writeTemporaryFile($this->temporaryDirectory . '/2020-01-01_000000-beside-the-results-folder/case.json', 'beside');
        $this->writeTemporaryFile($this->temporaryDirectory . '/2020-01-01_000000-beside.json', 'beside');

        $this->getResultFilesService()->sweepRunEntriesOlderThanRetention($this->getNowTimestamp());

        self::assertFileExists($this->temporaryDirectory . '/2020-01-01_000000-beside-the-results-folder/case.json');
        self::assertFileExists($this->temporaryDirectory . '/2020-01-01_000000-beside.json');
    }
}
