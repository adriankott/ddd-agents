<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRow;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunSummary;

/**
 * Reading a run back: the run file a reference names (a file, a run folder or a run name, as given or in the results
 * folder), the most recently written run without a reference, and the full row with its message trace of a row of a
 * run folder's summary — that summary holds the rows without their traces, the case file beside it holds the trace.
 */
class AgentEvalResultFilesServiceReadBackTest extends AgentEvalResultFilesTestCase
{
    public function testAReferenceNamesAFileARunFolderOrARunName(): void
    {
        $agentEvalResultFilesService = $this->getResultFilesService();
        $this->writeTemporaryFile($this->resultsDirectory . '/2026-07-13_205441-default-all/_summary.json', '{}');
        $this->writeTemporaryFile($this->resultsDirectory . '/2026-07-13_205442-default-case-x.json', '{}');
        $this->writeTemporaryFile($this->temporaryDirectory . '/elsewhere/run.json', '{}');

        self::assertSame($this->resultsDirectory . '/2026-07-13_205441-default-all/_summary.json', $agentEvalResultFilesService->findRunFileByReference('2026-07-13_205441-default-all'));
        self::assertSame($this->resultsDirectory . '/2026-07-13_205442-default-case-x.json', $agentEvalResultFilesService->findRunFileByReference('2026-07-13_205442-default-case-x'));
        self::assertSame($this->resultsDirectory . '/2026-07-13_205442-default-case-x.json', $agentEvalResultFilesService->findRunFileByReference('2026-07-13_205442-default-case-x.json'));
        self::assertSame($this->temporaryDirectory . '/elsewhere/run.json', $agentEvalResultFilesService->findRunFileByReference($this->temporaryDirectory . '/elsewhere/run.json'));
        self::assertSame($this->temporaryDirectory . '/elsewhere/run.json', $agentEvalResultFilesService->findRunFileByReference($this->temporaryDirectory . '/elsewhere/run'));
        self::assertNull($agentEvalResultFilesService->findRunFileByReference('2026-01-01_000000-never'));
    }

    public function testWithoutAReferenceTheMostRecentlyWrittenRunIsRead(): void
    {
        $agentEvalResultFilesService = $this->getResultFilesService();
        self::assertNull($agentEvalResultFilesService->findMostRecentRunFile(), 'an empty results folder');
        $this->writeTemporaryFile($this->resultsDirectory . '/2026-07-13_205441-default-all/_summary.json', '{}');
        $this->writeTemporaryFile($this->resultsDirectory . '/2026-07-14_090000-default-case-x.json', '{}');
        $this->writeTemporaryFile($this->resultsDirectory . '/2026-07-15_090000-suite/_suite.json', '{}');
        touch($this->resultsDirectory . '/2026-07-13_205441-default-all/_summary.json', 2000000000);
        touch($this->resultsDirectory . '/2026-07-14_090000-default-case-x.json', 1900000000);

        self::assertSame($this->resultsDirectory . '/2026-07-13_205441-default-all/_summary.json', $agentEvalResultFilesService->findMostRecentRunFile(), 'by the time it was written; a folder without a summary is no run file');
        self::assertTrue($agentEvalResultFilesService->hasResultsDirectory());

        $this->setResultsDirectoryConfigValue($this->temporaryDirectory . '/never-created');
        self::assertNull($agentEvalResultFilesService->findMostRecentRunFile());
        self::assertFalse($agentEvalResultFilesService->hasResultsDirectory());
    }

    public function testTheTraceOfAToolsRunFolderRowIsReadFromItsCaseFile(): void
    {
        $agentEvalResultFilesService = $this->getResultFilesService();
        $fixtureInput = $this->readFixtureInput();
        $runName = '2026-07-13_205441-OPENAI.GPT6_LUNA-all';
        $runDirectory = $agentEvalResultFilesService->prepareToolsRunDirectory($runName);
        $caseRowValues = $fixtureInput['caseRowOfChild'];
        $agentEvalResultFilesService->writeCaseFileOfToolsRun($runDirectory, $caseRowValues['case'], 0, AgentEvalCaseRow::fromArray($caseRowValues));
        $summaryFile = $agentEvalResultFilesService->writeToolsRunSummary($runName, true, AgentEvalRunSummary::fromArray(['total' => 1, 'cases' => [$caseRowValues]]));
        $strippedSummaryRow = json_decode((string)file_get_contents($summaryFile), true)['cases'][0];
        self::assertArrayNotHasKey('messages', $strippedSummaryRow, 'the folder summary strips the trace');

        $fullCaseRow = $agentEvalResultFilesService->findFullCaseRowOfSummaryRow($summaryFile, AgentEvalCaseRow::fromArray($strippedSummaryRow));

        self::assertNotNull($fullCaseRow);
        self::assertSame($caseRowValues['messages'], $fullCaseRow->toArray()['messages']);
    }

    public function testTheTraceOfARepetitionAndOfASuiteRowIsReadFromTheirCaseFiles(): void
    {
        $runDirectory = $this->resultsDirectory . '/2026-07-13_205441-default-all';
        $this->writeTemporaryFile($runDirectory . '/catalog.one.rep3.json', '{"case": "catalog.one", "repetition": 3, "messages": {"elements": [1]}}');
        $suiteRunDirectory = $this->resultsDirectory . '/2026-07-13_205441-suite/default-judge';
        $this->writeTemporaryFile($suiteRunDirectory . '/tools-catalog.one.json', '{"case": "catalog.one", "suite": "tools", "messages": {"elements": [2]}}');
        $agentEvalResultFilesService = $this->getResultFilesService();

        $repetitionRow = $agentEvalResultFilesService->findFullCaseRowOfSummaryRow($runDirectory . '/_summary.json', AgentEvalCaseRow::fromArray(['case' => 'catalog.one', 'repetition' => 3]));
        $suiteRow = $agentEvalResultFilesService->findFullCaseRowOfSummaryRow($suiteRunDirectory . '/_summary.json', AgentEvalCaseRow::fromArray(['case' => 'catalog.one', 'suite' => 'tools']));

        self::assertSame(['elements' => [1]], $repetitionRow?->toArray()['messages']);
        self::assertSame(['elements' => [2]], $suiteRow?->toArray()['messages']);
    }

    public function testALooseRunFileAndAMissingCaseFileGiveNoRow(): void
    {
        $agentEvalResultFilesService = $this->getResultFilesService();

        self::assertNull($agentEvalResultFilesService->findFullCaseRowOfSummaryRow($this->resultsDirectory . '/2026-07-13_205441-default-case-x.json', AgentEvalCaseRow::fromArray(['case' => 'catalog.one'])), 'a loose run file carries its traces');
        self::assertNull($agentEvalResultFilesService->findFullCaseRowOfSummaryRow($this->resultsDirectory . '/2026-07-13_205441-default-all/_summary.json', AgentEvalCaseRow::fromArray(['case' => 'catalog.one'])));
        self::assertNull($agentEvalResultFilesService->findFullCaseRowOfSummaryRow($this->resultsDirectory . '/2026-07-13_205441-default-all/_summary.json', AgentEvalCaseRow::fromArray(['passed' => false])), 'a row without a case id');
    }
}
