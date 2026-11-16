<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Services\Eval\AgentEvalResultFilesService;

/**
 * A loose run file of an evaluation command: `<date>_<time>-<model>-<name>.json` in the results folder (a name the
 * retention sweep recognises), the document encoded as the command has always encoded it — the guard evaluation's
 * file pretty-printed with unescaped unicode and escaped slashes, the expected bytes being that encoding of the same
 * document.
 */
class AgentEvalResultFilesServiceLooseRunFileTest extends AgentEvalResultFilesTestCase
{
    public function testTheLooseRunFileIsNamedByDateModelAndNameAndKeepsItsEncoding(): void
    {
        $document = ['report' => ['model' => 'VENDOR.MODEL/A', 'text' => 'Größe'], 'cells' => [['run' => 1]]];
        $secondsBefore = time();

        $runFile = $this->getResultFilesService()->writeLooseRunFile('VENDOR.MODEL/A', 'guard-sentinel', $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        self::assertSame($this->resultsDirectory, dirname($runFile));
        self::assertMatchesRegularExpression('/^20\d{2}-\d{2}-\d{2}_\d{6}-VENDOR\.MODEL_A-guard-sentinel\.json$/', basename($runFile));
        self::assertMatchesRegularExpression(AgentEvalResultFilesService::RUN_ENTRY_NAME_PATTERN, basename($runFile));
        self::assertGreaterThanOrEqual(date('Y-m-d_His', $secondsBefore), substr(basename($runFile), 0, 17));
        self::assertSame((string)json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), (string)file_get_contents($runFile));
        self::assertStringContainsString('VENDOR.MODEL\/A', (string)file_get_contents($runFile));
    }

    public function testAResultDocumentIsWrittenInItsFolderWithTheRunFlags(): void
    {
        $resultFile = $this->resultsDirectory . '/run/model-call/.result-recall-a.json';
        $document = ['passed' => 1, 'cases' => [['finalText' => 'Größe a/b']]];

        $this->getResultFilesService()->writeResultDocument($resultFile, $document);

        self::assertSame((string)json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), (string)file_get_contents($resultFile));
    }

    public function testTheDefaultEncodingAndTheDefaultModelName(): void
    {
        self::assertSame('2026-11-16_101112-default-recall.json', $this->getResultFilesService()->getLooseRunFileName((int)mktime(10, 11, 12, 11, 16, 2026), null, 'recall'));

        $runFile = $this->getResultFilesService()->writeLooseRunFile(null, 'recall', ['path' => 'a/b']);

        self::assertSame((string)json_encode(['path' => 'a/b'], AgentEvalResultFilesService::JSON_FLAGS), (string)file_get_contents($runFile));
    }
}
